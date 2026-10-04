<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseResult;
use App\Models\PurchaseStatusChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Phase 11 CP2 on real MariaDB: separate PHP processes deliver, re-check and
 * settle one NIN/BVN purchase at the same moment, and exactly one result row
 * and one success (or one refund, never both) come out; the compound foreign
 * key, the unique purchase and the field-count CHECK hold in the database;
 * the results migration rolls back and re-migrates over historical data and
 * refuses while a result exists. Numbers and result values are neutral
 * fixtures generated when the tests run; the test-only FakeProvider answers.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

const PRC_MIGRATION = '2026_10_04_120000_create_purchase_results_table';

/** The last CP1 migration: everything newer is CP2 or later. */
const PRC_CP1_LAST = '2026_10_04_100100_create_purchase_identity_recipients_table';

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
});

function prcNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

/** An available fixed-price NIN or BVN plan (15,000 kobo) with $routes executable routes (cost 10,000 kobo). */
function prcPlan(RecipientType $type, int $routes = 2): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    for ($priority = 1; $priority <= $routes; $priority++) {
        puxRoute($plan, $priority, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);
    }

    return $plan->fresh();
}

/** A NIN/BVN purchase whose provider call was unclear (no answer), due for its re-check; its second route must never be tried. */
function prcUnclear(RecipientType $type = RecipientType::Nin, ?User $user = null): Purchase
{
    FakeProvider::$purchaseScript = ['timeout'];
    $purchase = puxService()->purchase($user ?? puxCustomer(100_000), prcPlan($type), prcNumber(), null, (string) Str::uuid(), null, true);
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return $purchase->fresh();
}

function prcStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/**
 * Starts result_worker.php processes at one barrier.
 *
 * @param  list<array{0: string, 1: int, 2: int, 3: int, 4: string, 5: string, 6: int}>  $workers  [mode, staff, purchase, count, script, fixture|none, delayMs]
 * @return list<array<string, mixed>>
 */
function prcRace(array $workers): array
{
    $barrier = sys_get_temp_dir().'/result-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/result_worker.php'), $barrier,
                $w[0], (string) $w[1], (string) $w[2], (string) $w[3], $w[4], $w[5], (string) $w[6]]);
        }
    })->start();

    $deadline = microtime(true) + 60;
    while (count(glob($barrier.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(count(glob($barrier.'.ready.*')))->toBe(count($workers));
    touch($barrier);
    $results = $pool->wait();
    array_map('unlink', [$barrier, ...glob($barrier.'.ready.*')]);

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput())
            ->and($result->output().$result->errorOutput())->not->toContain('FIXTURE-');
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/**
 * After a race: exactly one outcome. Successful means one result row, from the
 * delivering attempt, readable, and no refund; failed means one refund and no
 * result; otherwise neither. One move to a final status, the wallet exact,
 * wallet:verify and purchases:verify clean.
 */
function prcSettledOnce(Purchase $purchase, int $attempts = 1): void
{
    $purchase->refresh();
    $results = PurchaseResult::where('purchase_id', $purchase->id)->get();
    $refunds = Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count();

    expect(PurchaseAttempt::where('purchase_id', $purchase->id)->count())->toBe($attempts)
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->where('status', PurchaseAttemptStatus::Succeeded->value)->count())
        ->toBe($purchase->status === PurchaseStatus::Successful ? 1 : 0)
        ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->whereIn('new_status', ['successful', 'failed'])->count())->toBe($purchase->isFinal() ? 1 : 0)
        ->and(Transaction::where('idempotency_key', 'purchase:'.$purchase->reference)->count())->toBe(1);
    if ($purchase->status === PurchaseStatus::Successful) {
        expect($results)->toHaveCount(1)
            ->and($results->sole()->purchase_attempt_id)->toBe($purchase->successful_attempt_id)
            ->and($results->sole()->canBeRead())->toBeTrue()
            ->and($refunds)->toBe(0)
            ->and($purchase->refund_transaction_id)->toBeNull();
    } elseif ($purchase->status === PurchaseStatus::Failed) {
        expect($results)->toHaveCount(0)->and($refunds)->toBe(1);
    } else {
        expect($results)->toHaveCount(0)->and($refunds)->toBe(0);
    }

    $wallet = Wallet::where('user_id', $purchase->user_id)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running);
    }
    expect($wallet->balance_kobo)->toBe($running)
        ->and($wallet->balance_kobo)->toBe(100_000 - ($purchase->status === PurchaseStatus::Failed ? 0 : 15_000))
        ->and(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
}

/** Every row of the purchase, identity and money tables, in id order, exactly as stored. */
function prcRows(): array
{
    $rows = [];
    foreach (['purchases', 'purchase_attempts', 'purchase_status_changes', 'purchase_identity_recipients', 'transactions', 'wallet_ledger_entries', 'wallets'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $rows;
}

/** @return list<string> CHECK constraints on purchase_results */
function prcChecks(): array
{
    return DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'purchase_results')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all();
}

/** The result table as MariaDB reports it. */
function prcSchema(): array
{
    return Schema::hasTable('purchase_results') ? [
        'columns' => Schema::getColumns('purchase_results'),
        'indexes' => collect(Schema::getIndexes('purchase_results'))->sortBy('name')->values()->all(),
        'foreign_keys' => collect(Schema::getForeignKeys('purchase_results'))->sortBy('name')->values()->all(),
        'checks' => prcChecks(),
    ] : [];
}

it('delivers one result and one success when parallel executions of one NIN purchase race', function () {
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, prcPlan(RecipientType::Nin), prcNumber(), null, 'one-nin', null, true);

    $results = collect(prcRace(array_fill(0, 10, ['execute', 0, $purchase->id, 2, 'succeeded', 'fixture', 50])));

    expect($results)->toHaveCount(20)
        ->and($results->where('result', '!=', 'ok')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseResult::count())->toBe(1);
    prcSettledOnce($purchase);
});

it('delivers one result when scheduled reconciliation and staff re-checks race', function (RecipientType $type) {
    $purchase = prcUnclear($type);
    $staff = prcStaff();

    $results = collect(prcRace([
        ...array_fill(0, 5, ['reconcile', 0, 0, 2, 'succeeded,succeeded', 'fixture', 60]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, 'succeeded,succeeded', 'fixture', 60]),
    ]));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseResult::count())->toBe(1);
    prcSettledOnce($purchase);
})->with(['NIN' => [RecipientType::Nin], 'BVN' => [RecipientType::Bvn]]);

it('produces exactly one outcome, never a result and a refund, when re-checks get conflicting answers', function () {
    $staff = prcStaff();
    $outcomes = [];
    for ($round = 0; $round < 3; $round++) {
        $purchase = prcUnclear();

        $results = collect(prcRace([
            ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, 'succeeded,succeeded', 'fixture', 50]),
            ...array_fill(0, 5, ['reconcile', 0, 0, 2, 'failed_definite,failed_definite', 'none', 50]),
        ]));

        expect($results->where('result', 'error')->values()->all())->toBe([])
            ->and($purchase->fresh()->status)->toBeIn([PurchaseStatus::Successful, PurchaseStatus::Failed]);
        prcSettledOnce($purchase);
        $outcomes[] = $purchase->fresh()->status->value;
    }
    expect(PurchaseResult::count())->toBe(count(array_keys($outcomes, 'successful')));
});

it('keeps a success without its result unclear under parallel re-checks: no result, no success, no refund', function () {
    $purchase = prcUnclear();
    $staff = prcStaff();

    $results = collect(prcRace([
        ...array_fill(0, 5, ['reconcile', 0, 0, 3, 'succeeded,succeeded,succeeded', 'none', 30]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 3, 'succeeded,succeeded,succeeded', 'none', 30]),
    ]));

    $purchase->refresh();
    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->status)->toBe(PurchaseStatus::Pending)
        ->and($purchase->check_count)->toBeGreaterThanOrEqual(1)
        ->and($purchase->attempts()->sole()->status)->toBe(PurchaseAttemptStatus::Unknown)
        ->and($purchase->attempts()->sole()->error_code)->toBe('result_missing');
    prcSettledOnce($purchase);
});

it('enforces one result per purchase, from an attempt of that purchase, with 1 to 50 fields, in MariaDB itself', function () {
    $user = puxCustomer(300_000);
    $nin = prcUnclear(RecipientType::Nin, $user);
    $bvn = prcUnclear(RecipientType::Bvn, $user);
    $insert = fn (Purchase $purchase, int $attemptId, int $count = 2) => DB::table('purchase_results')->insert([
        'purchase_id' => $purchase->id, 'purchase_attempt_id' => $attemptId,
        'encrypted_fields' => Crypt::encryptString(json_encode(FakeProvider::fixtureFields($count)->all())), 'field_count' => $count]);

    expect(prcChecks())->toBe(['purchase_results_field_count'])
        ->and(fn () => $insert($nin, $bvn->attempts()->sole()->id))->toThrow(QueryException::class, 'purchase_results_attempt_purchase_fk')
        ->and(fn () => DB::table('purchase_results')->insert(['purchase_id' => $nin->id, 'purchase_attempt_id' => $nin->attempts()->sole()->id,
            'encrypted_fields' => 'x', 'field_count' => 0]))->toThrow(QueryException::class, 'purchase_results_field_count')
        ->and(fn () => DB::table('purchase_results')->insert(['purchase_id' => $nin->id, 'purchase_attempt_id' => $nin->attempts()->sole()->id,
            'encrypted_fields' => 'x', 'field_count' => 51]))->toThrow(QueryException::class, 'purchase_results_field_count');
    $insert($nin, $nin->attempts()->sole()->id);
    expect(fn () => $insert($nin, $nin->attempts()->sole()->id))->toThrow(QueryException::class, 'purchase_results_purchase_id_unique')
        ->and(fn () => DB::table('purchase_attempts')->where('purchase_id', $nin->id)->delete())->toThrow(QueryException::class, 'purchase_results_')
        ->and(fn () => DB::table('purchase_results')->update(['purchase_attempt_id' => $bvn->attempts()->sole()->id]))
        ->toThrow(QueryException::class, 'purchase_results_attempt_purchase_fk')
        ->and(DB::table('purchase_results')->count())->toBe(1)
        ->and((int) DB::table('purchase_results')->value('purchase_attempt_id'))->toBe($nin->attempts()->sole()->id)
        ->and(DB::table('purchase_results')->value('created_at'))->not->toBeNull();
});

it('rolls the results table back and forward over historical data, and refuses once a result exists', function () {
    $staff = prcStaff();
    $user = puxCustomer(1_000_000);
    $data = puxPlan('data', 10_000);
    puxRoute($data, 1);
    puxRoute($data, 2);
    $phone = function (array $script) use ($user, $data): Purchase {
        FakeProvider::$purchaseScript = $script;

        return puxService()->purchase($user, $data, '08012345678', null, (string) Str::uuid());
    };
    $identity = function (RecipientType $type, array $script) use ($user): Purchase {
        FakeProvider::$purchaseScript = $script;

        return puxService()->purchase($user, prcPlan($type), prcNumber(), null, (string) Str::uuid(), null, true);
    };

    // Phase 10 phone purchases in every state, and CP1 NIN/BVN purchases that never succeeded (no result yet).
    $phone(['succeeded']);
    $phone(['failed_definite', 'succeeded']);
    $phone(['failed_definite', 'failed_definite']);
    $phone(['timeout']);
    $identity(RecipientType::Nin, ['failed_definite', 'failed_definite']);
    $review = $identity(RecipientType::Bvn, ['timeout']);
    $this->travel(25)->hours();
    FakeProvider::$queryScript = ['unknown', 'unknown'];
    puxService()->reconcile();
    $this->travel(-25)->hours();
    $unclear = $identity(RecipientType::Nin, ['timeout']);
    expect($review->fresh()->status)->toBe(PurchaseStatus::Review)
        ->and(PurchaseResult::count())->toBe(0);
    $before = prcRows();
    $schema = prcSchema();
    expect($schema['checks'])->toBe(['purchase_results_field_count']);

    // Back to the CP1 schema (allowed: no result exists); no value changes.
    $steps = DB::table('migrations')->where('migration', '>', PRC_CP1_LAST)->count();
    Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]); // CP2 and every later checkpoint, newest first
    expect(Schema::hasTable('purchase_results'))->toBeFalse()
        ->and(prcRows())->toBe($before);

    // CP2 again over that data: every value kept, the constraints back.
    Artisan::call('migrate', ['--force' => true]);
    expect(prcRows())->toBe($before)
        ->and(prcSchema())->toEqual($schema)
        ->and(PurchaseResult::count())->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());

    // The engine keeps working on it: a staff re-check delivers the review purchase's result, reconciliation the unclear one's.
    FakeProvider::$queryScript = ['succeeded'];
    FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
    app(RecheckPurchase::class)->handle($review->fresh(), $staff);
    $this->travel(3)->minutes();
    FakeProvider::$queryScript = ['succeeded'];
    FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
    puxService()->reconcile();
    expect($review->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and($unclear->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseResult::count())->toBe(2)
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());

    // Now a result exists: rolling back is refused before any change.
    $rows = prcRows();
    $results = DB::table('purchase_results')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $steps = DB::table('migrations')->where('migration', '>', PRC_CP1_LAST)->count();
    expect(fn () => Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]))
        ->toThrow(RuntimeException::class, 'Refusing to roll back: NIN/BVN purchases have stored results, which would be lost. Nothing was changed.');
    expect(prcSchema())->toEqual($schema)
        ->and(prcRows())->toBe($rows)
        ->and(DB::table('purchase_results')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($results)
        ->and(DB::table('migrations')->where('migration', PRC_MIGRATION)->exists())->toBeTrue();
});
