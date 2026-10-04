<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Phase 11 CP1 on real MariaDB: historical Phase 10 purchases keep every
 * value through the recipient migrations (rollback and re-migrate) and keep
 * working; the CHECK constraint holds; rollback is refused while a NIN
 * purchase exists; parallel submissions of one NIN confirmation make one
 * purchase. Numbers are generated when the tests run; the test-only
 * FakeProvider returns outcomes only.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

const PIT_CP1 = ['2026_10_04_100000_add_recipient_type_to_purchases', '2026_10_04_100100_create_purchase_identity_recipients_table'];

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
});

function pitNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN plan with an executable FakeProvider route. */
function pitNinPlan(): Plan
{
    $service = Service::where('slug', 'nin')->first() ?? Service::factory()->create(['name' => 'NIN', 'slug' => 'nin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => 'nin-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

function pitStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

function pitRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (PurchaseException $e) {
        return $e->getMessage();
    }

    return null;
}

/** Every row of the purchase and money tables, in id order, exactly as stored. */
function pitRows(): array
{
    $rows = [];
    foreach (['purchases', 'purchase_attempts', 'purchase_status_changes', 'transactions', 'wallet_ledger_entries', 'wallets'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $rows;
}

function pitWithout(array $rows, string $column): array
{
    $rows['purchases'] = array_map(fn (array $row) => array_diff_key($row, [$column => true]), $rows['purchases']);

    return $rows;
}

/** @return list<string> CHECK constraints on purchases */
function pitChecks(): array
{
    return DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'purchases')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all();
}

/** The CP1 part of the schema as MariaDB reports it. */
function pitSchema(): array
{
    return [
        'purchases' => Schema::getColumns('purchases'),
        'identity' => Schema::hasTable('purchase_identity_recipients') ? Schema::getColumns('purchase_identity_recipients') : null,
        'indexes' => collect(Schema::getIndexes('purchases'))->sortBy('name')->values()->all(),
        'foreign_keys' => collect(Schema::getForeignKeys('purchases'))->sortBy('name')->values()->all(),
        'checks' => pitChecks(),
        'migrations' => DB::table('migrations')->whereIn('migration', PIT_CP1)->orderBy('migration')->pluck('migration')->all(),
    ];
}

/** Ledger and wallet consistency for one customer's wallet. */
function pitWalletConsistent(int $userId): void
{
    $wallet = Wallet::where('user_id', $userId)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running)->and($running)->toBeGreaterThanOrEqual(0);
    }
    expect($wallet->balance_kobo)->toBe($running)
        ->and(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

/**
 * Starts $workers identity_worker.php processes at one barrier, each submitting the same NIN/BVN purchase $count times.
 *
 * @return list<array<string, mixed>>
 */
function pitRace(int $workers, int $userId, int $planId, int $count, string $key, string $script, int $delayMs, string $number): array
{
    $barrier = sys_get_temp_dir().'/identity-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array',
        'IDENTITY_TEST_NUMBER' => $number];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env, $userId, $planId, $count, $key, $script, $delayMs) {
        for ($i = 0; $i < $workers; $i++) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/identity_worker.php'), $barrier,
                (string) $userId, (string) $planId, (string) $count, $key, $script, (string) $delayMs]);
        }
    })->start();

    $deadline = microtime(true) + 60;
    while (count(glob($barrier.'.ready.*')) < $workers && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(count(glob($barrier.'.ready.*')))->toBe($workers);
    touch($barrier);
    $results = $pool->wait();
    array_map('unlink', [$barrier, ...glob($barrier.'.ready.*')]);

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput())
            ->and($result->output().$result->errorOutput())->not->toContain($number);
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

it('keeps historical Phase 10 purchases identical through rollback and re-migration, and every Phase 10 flow working on them', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan, 1);
    puxRoute($plan, 2);
    $user = puxCustomer(1_000_000);
    $staff = pitStaff();
    $buy = function (array $script, string $key) use ($user, $plan): Purchase {
        FakeProvider::$purchaseScript = $script;

        return puxService()->purchase($user, $plan, '08012345678', null, $key);
    };

    // Phone purchases in every Phase 10 state, through the real engine.
    $review = $buy(['timeout'], 'h-review');
    $this->travel(25)->hours();
    FakeProvider::$queryScript = ['unknown'];
    puxService()->reconcile();
    $successful = $buy(['succeeded'], 'h-successful');
    $failedOver = $buy(['failed_definite', 'succeeded'], 'h-failed-over');
    $refunded = $buy(['failed_definite', 'failed_definite'], 'h-refunded');
    $settles = $buy(['timeout'], 'h-settles');
    $fails = $buy(['timeout'], 'h-fails');
    expect(collect([$review, $successful, $failedOver, $refunded, $settles, $fails])->map(fn (Purchase $p) => $p->fresh()->status->value)->all())
        ->toBe(['review', 'successful', 'successful', 'failed', 'pending', 'pending']);
    $before = pitRows();

    // Back to the Phase 10 schema (allowed: every purchase is a phone purchase); no value changes.
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
    $columns = collect(Schema::getColumns('purchases'))->keyBy('name');
    expect($columns->has('recipient_type'))->toBeFalse()
        ->and($columns['recipient']['nullable'])->toBeFalse()
        ->and($columns['request_fingerprint']['nullable'])->toBeFalse()
        ->and(Schema::hasTable('purchase_identity_recipients'))->toBeFalse()
        ->and(pitChecks())->toBe([]);
    $historical = pitRows();
    expect($historical)->toBe(pitWithout($before, 'recipient_type'));

    // CP1 again over that historical data: every value kept (updated_at included), each purchase reads as phone.
    Artisan::call('migrate', ['--force' => true]);
    $after = pitRows();
    expect(pitWithout($after, 'recipient_type'))->toBe($historical)
        ->and($after)->toBe($before)
        ->and(collect($after['purchases'])->pluck('recipient_type')->unique()->all())->toBe(['phone'])
        ->and(PurchaseIdentityRecipient::count())->toBe(0)
        ->and(pitChecks())->toBe(['purchases_recipient_by_type']);

    // The Phase 10 flows keep working on them.
    FakeProvider::$calls = [];
    expect(puxService()->purchase($user, $plan, '+234 801 234 5678', null, 'h-successful')->id)->toBe($successful->id)
        ->and(pitRefusal(fn () => puxService()->purchase($user, $plan, '08099999999', null, 'h-successful')))
        ->toBe('This request was already used for a different purchase. Please start again.')
        ->and(FakeProvider::$calls)->toBe([]);

    FakeProvider::$queryScript = ['succeeded'];
    app(RecheckPurchase::class)->handle($review->fresh(), $staff);
    $this->travel(3)->minutes();
    FakeProvider::$queryScript = ['succeeded', 'failed_definite'];
    puxService()->reconcile();
    puxService()->reconcile();

    expect($settles->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and($fails->fresh()->status)->toBe(PurchaseStatus::Failed)
        ->and($review->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$fails->reference)->count())->toBe(1)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$refunded->reference)->count())->toBe(1)
        ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe(2)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(1_000_000 - 4 * 10_000)
        ->and(DB::table('purchases')->distinct()->pluck('recipient_type')->all())->toBe(['phone'])
        ->and(PurchaseIdentityRecipient::count())->toBe(0);
    pitWalletConsistent($user->id);
});

it('enforces the recipient CHECK in the database for phone and NIN purchases', function () {
    $data = puxPlan('data', 10_000);
    puxRoute($data);
    $user = puxCustomer(100_000);
    $phone = puxService()->create($user, $data, '08012345678', null, 'phone');
    $nin = puxService()->create($user, pitNinPlan(), pitNumber(), null, 'nin', null, true);
    $rows = pitRows();

    $violations = [
        ['update purchases set recipient = null where id = ?', [$phone->id]],
        ['update purchases set request_fingerprint = null where id = ?', [$phone->id]],
        ["update purchases set recipient_type = 'nin' where id = ?", [$phone->id]],
        ["update purchases set recipient = '08012345678' where id = ?", [$nin->id]],
        ["update purchases set recipient = '' where id = ?", [$nin->id]],
        ['update purchases set request_fingerprint = ? where id = ?', [str_repeat('a', 64), $nin->id]],
        ["update purchases set recipient_type = 'phone' where id = ?", [$nin->id]],
    ];
    foreach ($violations as [$sql, $bindings]) {
        expect(fn () => DB::update($sql, $bindings))->toThrow(QueryException::class, 'purchases_recipient_by_type');
    }

    expect(pitRows())->toBe($rows)
        ->and(pitChecks())->toBe(['purchases_recipient_by_type']);
    pitWalletConsistent($user->id);
});

it('refuses to roll back while a NIN purchase exists, leaving the schema and data unchanged', function () {
    $user = puxCustomer(100_000);
    FakeProvider::$purchaseScript = ['succeeded'];
    $nin = puxService()->purchase($user, pitNinPlan(), pitNumber(), null, 'nin', null, true);
    $schema = pitSchema();
    $rows = pitRows();

    expect($nin->status)->toBe(PurchaseStatus::Successful)
        ->and(fn () => Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]))
        ->toThrow(RuntimeException::class, 'Refusing to roll back: NIN/BVN purchases have identity recipients, which would be lost. Nothing was changed.');
    expect(pitSchema())->toBe($schema)
        ->and(pitRows())->toBe($rows)
        ->and(PurchaseIdentityRecipient::count())->toBe(1);

    // The purchases migration refuses on its own too, before any schema change.
    $migration = require database_path('migrations/'.PIT_CP1[0].'.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Refusing to roll back: 1 purchase(s) are not phone purchases (NIN/BVN).');
    expect(pitSchema())->toBe($schema)
        ->and(pitRows())->toBe($rows);
    pitWalletConsistent($user->id);
});

it('turns parallel submissions of one NIN confirmation into one purchase, one identity recipient, one debit and one provider call', function () {
    $plan = pitNinPlan();
    $user = puxCustomer(100_000);
    $number = pitNumber();

    $results = collect(pitRace(10, $user->id, $plan->id, 3, 'one-nin-confirmation', 'succeeded', 50, $number));

    $purchase = Purchase::sole();
    expect($results)->toHaveCount(30)
        ->and($results->where('result', '!=', 'ok')->values()->all())->toBe([])
        ->and($results->pluck('purchase')->unique()->values()->all())->toBe([$purchase->id])
        ->and($purchase->recipient_type)->toBe(RecipientType::Nin)
        ->and($purchase->recipient)->toBeNull()
        ->and($purchase->request_fingerprint)->toBeNull()
        ->and($purchase->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseIdentityRecipient::count())->toBe(1)
        ->and(PurchaseIdentityRecipient::sole()->number(RecipientType::Nin))->toBe($number)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(85_000)
        ->and(json_encode(DB::table('purchases')->get()))->not->toContain($number);
    pitWalletConsistent($user->id);
});
