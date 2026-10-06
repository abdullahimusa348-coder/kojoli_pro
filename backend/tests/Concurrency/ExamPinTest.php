<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4 on real MariaDB: Exam PIN purchases. Parallel HTTP submissions
 * of one sealed Buy Exam PIN confirmation (separate processes, each through
 * the HTTP kernel) make one purchase, one debit, one provider call and one
 * result; parallel executions and re-checks settle a purchase once, never with
 * both a result and a refund; a success without a usable result stays
 * unclear. The existing recipient CHECK holds Exam PIN rows to no recipient
 * and no fingerprint, and rolling back is refused while one exists. The
 * test-only FakeProvider returns outcomes and neutral generated fixture
 * result fields.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
});

/** An available fixed-price plan of the existing exam-pin service (15,000 kobo) with $routes executable routes (cost 10,000 kobo). */
function xpcPlan(int $routes = 1): Plan
{
    $service = Service::where('slug', 'exam-pin')->first() ?? Service::factory()->create(['name' => 'Exam PIN', 'slug' => 'exam-pin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam', 'code' => 'exam-pin-test-'.Str::lower(Str::random(6)),
        'network' => null]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Fixture PIN', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    for ($priority = 1; $priority <= $routes; $priority++) {
        puxRoute($plan, $priority, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);
    }

    return $plan->fresh();
}

/** An Exam PIN purchase whose provider call was unclear (no answer), due for its re-check; its second route is never tried. */
function xpcUnclear(?User $user = null): Purchase
{
    FakeProvider::$purchaseScript = ['timeout'];
    $purchase = puxService()->purchase($user ?? puxCustomer(100_000), xpcPlan(2), '', null, (string) Str::uuid());
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return $purchase->fresh();
}

function xpcStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** Runs worker processes of $script at one start barrier; returns every JSON line they printed. */
function xpcRun(string $script, array $commands, array $env = []): array
{
    $barrier = sys_get_temp_dir().'/exam-pin-race-'.uniqid();
    $env += ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array'];

    $pool = Process::pool(function ($pool) use ($script, $commands, $barrier, $env) {
        foreach ($commands as $arguments) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/'.$script), $barrier,
                ...array_map('strval', $arguments)]);
        }
    })->start();

    $deadline = microtime(true) + 60;
    while (count(glob($barrier.'.ready.*')) < count($commands) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(count(glob($barrier.'.ready.*')))->toBe(count($commands));
    touch($barrier);
    $results = $pool->wait();
    array_map('unlink', [$barrier, ...glob($barrier.'.ready.*')]);

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput())
            ->and($result->output().$result->errorOutput())->not->toContain('FIXTURE-');
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $decoded = json_decode($line, true);
            expect($decoded)->toBeArray();
            $lines[] = $decoded;
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
function xpcSettledOnce(Purchase $purchase, int $attempts = 1): void
{
    $purchase->refresh();
    $results = PurchaseResult::where('purchase_id', $purchase->id)->get();
    $refunds = Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count();

    expect($purchase->recipient_type)->toBe(RecipientType::None)
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->count())->toBe($attempts)
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->where('status', PurchaseAttemptStatus::Succeeded->value)->count())
        ->toBe($purchase->status === PurchaseStatus::Successful ? 1 : 0)
        ->and(PurchaseStatusChange::where('purchase_id', $purchase->id)->whereIn('new_status', ['successful', 'failed'])->count())->toBe($purchase->isFinal() ? 1 : 0)
        ->and(Transaction::where('idempotency_key', 'purchase:'.$purchase->reference)->count())->toBe(1)
        ->and(PurchaseIdentityRecipient::where('purchase_id', $purchase->id)->count())->toBe(0);
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

/** Every row of the purchase and money tables, in id order, exactly as stored. */
function xpcRows(): array
{
    $rows = [];
    foreach (['purchases', 'purchase_attempts', 'purchase_status_changes', 'purchase_results', 'transactions', 'wallet_ledger_entries', 'wallets'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $rows;
}

it('turns parallel HTTP submissions of one sealed Exam PIN confirmation into one purchase, one debit, one provider call and one result', function () {
    $plan = xpcPlan();
    $user = puxCustomer(100_000);
    $confirmation = $this->actingAs($user)->post('/buy/exam-pin/confirm', ['plan' => $plan->id])->assertOk()->viewData('confirmation');

    $results = collect(xpcRun('exam_pin_buy_worker.php', array_fill(0, 8, [$user->id, 3, 'succeeded', 50]),
        ['EXAM_PIN_TEST_CONFIRMATION' => $confirmation]));

    $purchase = Purchase::sole();
    expect($results)->toHaveCount(24)
        ->and($results->pluck('status')->unique()->values()->all())->toBe([302])
        ->and($results->pluck('location')->unique()->values()->all())->toBe(['/purchases/'.$purchase->reference])
        ->and($purchase->user_id)->toBe($user->id)
        ->and($purchase->recipient_type)->toBe(RecipientType::None)
        ->and(DB::table('purchases')->where('id', $purchase->id)->value('recipient_type'))->toBe('none')
        ->and($purchase->recipient)->toBeNull()
        ->and($purchase->request_fingerprint)->toBeNull()
        ->and($purchase->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(PurchaseResult::count())->toBe(1)
        ->and(PurchaseResult::sole()->canBeRead())->toBeTrue();
    xpcSettledOnce($purchase);
});

it('refuses parallel HTTP submissions of a confirmation sealed for another customer, buying nothing', function () {
    $plan = xpcPlan();
    $owner = puxCustomer(100_000);
    $other = puxCustomer(100_000);
    $confirmation = $this->actingAs($owner)->post('/buy/exam-pin/confirm', ['plan' => $plan->id])->assertOk()->viewData('confirmation');

    $results = collect(xpcRun('exam_pin_buy_worker.php', array_fill(0, 4, [$other->id, 2, 'succeeded', 0]),
        ['EXAM_PIN_TEST_CONFIRMATION' => $confirmation]));

    expect($results)->toHaveCount(8)
        ->and($results->pluck('location')->unique()->values()->all())->toBe(['/buy/exam-pin'])
        ->and(Purchase::count())->toBe(0)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(0)
        ->and(Wallet::where('user_id', $owner->id)->sole()->balance_kobo)->toBe(100_000)
        ->and(Wallet::where('user_id', $other->id)->sole()->balance_kobo)->toBe(100_000);
});

it('delivers one result and one success when parallel executions of one Exam PIN purchase race', function () {
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, xpcPlan(2), '', null, 'one-exam-pin');

    $results = collect(xpcRun('result_worker.php', array_fill(0, 10, ['execute', 0, $purchase->id, 2, 'succeeded', 'fixture', 50])));

    expect($results)->toHaveCount(20)
        ->and($results->where('result', '!=', 'ok')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseResult::count())->toBe(1);
    xpcSettledOnce($purchase);
});

it('settles once, trying each route at most once, when parallel executions meet a definite failure and fail over', function () {
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, xpcPlan(2), '', null, 'failover-exam-pin');

    // Each call's first answer is a definite failure: whichever process takes the second route decides the outcome.
    $results = collect(xpcRun('result_worker.php', array_fill(0, 10, ['execute', 0, $purchase->id, 2, 'failed_definite,succeeded', 'fixture', 50])));

    expect($results)->toHaveCount(20)
        ->and($results->where('result', '!=', 'ok')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBeIn([PurchaseStatus::Successful, PurchaseStatus::Failed])
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->pluck('plan_provider_route_id')->unique())->toHaveCount(2);
    xpcSettledOnce($purchase, 2);
});

it('delivers one result when scheduled reconciliation and staff re-checks race', function () {
    $purchase = xpcUnclear();
    $staff = xpcStaff();

    $results = collect(xpcRun('result_worker.php', [
        ...array_fill(0, 5, ['reconcile', 0, 0, 2, 'succeeded,succeeded', 'fixture', 60]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, 'succeeded,succeeded', 'fixture', 60]),
    ]));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(PurchaseResult::count())->toBe(1);
    xpcSettledOnce($purchase);
});

it('produces exactly one outcome, never a result and a refund, when re-checks get conflicting answers', function () {
    $staff = xpcStaff();
    $outcomes = [];
    for ($round = 0; $round < 3; $round++) {
        $purchase = xpcUnclear();

        $results = collect(xpcRun('result_worker.php', [
            ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, 'succeeded,succeeded', 'fixture', 50]),
            ...array_fill(0, 5, ['reconcile', 0, 0, 2, 'failed_definite,failed_definite', 'none', 50]),
        ]));

        expect($results->where('result', 'error')->values()->all())->toBe([])
            ->and($purchase->fresh()->status)->toBeIn([PurchaseStatus::Successful, PurchaseStatus::Failed]);
        xpcSettledOnce($purchase);
        $outcomes[] = $purchase->fresh()->status->value;
    }
    expect(PurchaseResult::count())->toBe(count(array_keys($outcomes, 'successful')));
});

it('keeps a success without a usable result unclear under parallel re-checks: no result, no success, no refund', function (string $results) {
    $purchase = xpcUnclear();
    $staff = xpcStaff();

    $lines = collect(xpcRun('result_worker.php', [
        ...array_fill(0, 5, ['reconcile', 0, 0, 3, 'succeeded,succeeded,succeeded', $results, 30]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 3, 'succeeded,succeeded,succeeded', $results, 30]),
    ]));

    $purchase->refresh();
    expect($lines->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->status)->toBe(PurchaseStatus::Pending)
        ->and($purchase->check_count)->toBeGreaterThanOrEqual(1)
        ->and($purchase->attempts()->sole()->status)->toBe(PurchaseAttemptStatus::Unknown)
        ->and($purchase->attempts()->sole()->error_code)->toBe('result_missing');
    xpcSettledOnce($purchase);
})->with(['no result' => ['none'], 'only blank values' => ['blank']]);

it('holds Exam PIN rows to no recipient and no fingerprint with the existing CHECK, in MariaDB itself', function () {
    $user = puxCustomer(100_000);
    FakeProvider::$purchaseScript = ['succeeded'];
    FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
    $exam = puxService()->purchase($user, xpcPlan(), '', null, (string) Str::uuid());
    $rows = xpcRows();

    $violations = [
        ["update purchases set recipient = '08012345678' where id = ?", [$exam->id]],
        ["update purchases set recipient = '' where id = ?", [$exam->id]],
        ['update purchases set request_fingerprint = ? where id = ?', [str_repeat('a', 64), $exam->id]],
        ["update purchases set recipient_type = 'phone' where id = ?", [$exam->id]],
    ];
    foreach ($violations as [$sql, $bindings]) {
        expect(fn () => DB::update($sql, $bindings))->toThrow(QueryException::class, 'purchases_recipient_by_type');
    }

    expect(xpcRows())->toBe($rows)
        ->and(DB::table('purchases')->where('id', $exam->id)->value('recipient_type'))->toBe('none')
        ->and(collect(Schema::getColumns('purchases'))->firstWhere('name', 'recipient_type')['type'])->toBe('varchar(12)')
        ->and(Artisan::call('purchases:verify'))->toBe(0, Artisan::output());
});

it('refuses to roll back while an Exam PIN purchase exists, leaving the schema and data unchanged', function () {
    $user = puxCustomer(100_000);
    FakeProvider::$purchaseScript = ['succeeded'];
    FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
    $exam = puxService()->purchase($user, xpcPlan(), '', null, (string) Str::uuid());
    $schema = Schema::getColumns('purchases');
    $rows = xpcRows();
    $migration = require database_path('migrations/2026_10_04_100000_add_recipient_type_to_purchases.php');

    expect($exam->status)->toBe(PurchaseStatus::Successful)
        ->and(fn () => $migration->down())->toThrow(RuntimeException::class, 'Refusing to roll back: 1 purchase(s) are not phone purchases');
    expect(Schema::getColumns('purchases'))->toBe($schema)
        ->and(xpcRows())->toBe($rows);
});
