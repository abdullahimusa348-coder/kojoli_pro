<?php

use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Real purchase concurrency against MariaDB: separate PHP processes buy from
 * one wallet, resubmit one idempotency key, and execute one purchase at the
 * same moment, through PurchaseService with the test-only FakeProvider.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
});

/**
 * @param  list<array{0: string, 1: int, 2: int, 3: int, 4: string, 5: string, 6: int}>  $workers  [mode, user, target, count, key, script, delayMs]
 * @return list<array<string, mixed>>
 */
function pucRace(array $workers): array
{
    $barrier = sys_get_temp_dir().'/purchase-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)->command([PHP_BINARY, base_path('tests/Concurrency/purchase_worker.php'), $barrier,
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
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/** Ledger and wallet consistency for one customer's wallet. */
function pucWalletConsistent(int $userId): void
{
    $wallet = Wallet::where('user_id', $userId)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running)->and($running)->toBeGreaterThanOrEqual(0);
    }
    expect($wallet->balance_kobo)->toBe($running)->and(Artisan::call('wallet:verify'))->toBe(0);
}

/** Invariants for one purchase after a race: one debit, at most one refund, each route once, never success and refund together. */
function pucPurchaseConsistent(Purchase $purchase): void
{
    $purchase->refresh();
    $attempts = PurchaseAttempt::where('purchase_id', $purchase->id)->get();

    expect(Transaction::where('idempotency_key', 'purchase:'.$purchase->reference)->count())->toBe(1)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBeLessThanOrEqual(1)
        ->and($attempts->pluck('plan_provider_route_id')->unique()->count())->toBe($attempts->count())
        ->and($attempts->where('status', PurchaseAttemptStatus::Succeeded)->count())->toBeLessThanOrEqual(1);

    if ($purchase->status === PurchaseStatus::Successful) {
        expect($purchase->refund_transaction_id)->toBeNull()
            ->and($attempts->where('status', PurchaseAttemptStatus::Succeeded)->count())->toBe(1)
            ->and($attempts->max('attempt_number'))->toBe($purchase->successfulAttempt->attempt_number); // nothing tried after delivery
    } elseif ($purchase->status === PurchaseStatus::Failed) {
        expect($purchase->refund_transaction_id)->not->toBeNull()
            ->and($attempts->every(fn ($a) => $a->status === PurchaseAttemptStatus::FailedDefinite))->toBeTrue();
    } else {
        expect($purchase->refund_transaction_id)->toBeNull();
    }
}

it('lets parallel purchases spend one wallet exactly down to zero, never overdrawn', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 9_000]);
    $user = puxCustomer(300_000); // room for exactly 30 purchases

    $results = collect(pucRace(array_fill(0, 8, ['buy', $user->id, $plan->id, 6, 'k', 'succeeded', 0]))); // 48 attempts

    expect($results)->toHaveCount(48)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('status', 'successful')->count())->toBe(30)
        ->and($results->where('result', 'refused')->count())->toBe(18)
        ->and(Purchase::count())->toBe(30)
        ->and(Transaction::where('type', 'purchase')->where('direction', 'debit')->count())->toBe(30)
        ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe(0)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(0);
    foreach (Purchase::all() as $purchase) {
        pucPurchaseConsistent($purchase);
    }
    pucWalletConsistent($user->id);
});

it('turns many parallel submissions of one idempotency key into one purchase, one debit and one provider call', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    puxRoute($plan, 2);
    $user = puxCustomer(100_000);

    $results = collect(pucRace(array_fill(0, 10, ['buy', $user->id, $plan->id, 3, 'same', 'succeeded', 50])));

    $purchase = Purchase::sole();
    expect($results)->toHaveCount(30)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->pluck('purchase')->unique()->values()->all())->toBe([$purchase->id])
        ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and($purchase->status)->toBe(PurchaseStatus::Successful)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(90_000);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

it('executes one purchase from many processes with each route tried at most once and one settlement', function () {
    $plan = puxPlan('data', 10_000);
    foreach ([1, 2, 3] as $priority) {
        puxRoute($plan, $priority);
    }
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, $plan, '08012345678', null, 'one');

    $results = collect(pucRace(array_fill(0, 10, ['execute', $user->id, $purchase->id, 3, '-', 'failed_definite,succeeded', 80])));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and(PurchaseAttempt::count())->toBeLessThanOrEqual(3)
        ->and($purchase->fresh()->status)->toBeIn([PurchaseStatus::Successful, PurchaseStatus::Failed]);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

it('refunds exactly once when parallel executions all fail definitely', function () {
    $plan = puxPlan('data', 10_000);
    foreach ([1, 2, 3] as $priority) {
        puxRoute($plan, $priority);
    }
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, $plan, '08012345678', null, 'fail');

    $results = collect(pucRace(array_fill(0, 10, ['execute', $user->id, $purchase->id, 3, '-', 'failed_definite,failed_definite,failed_definite', 40])));

    $purchase->refresh();
    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->status)->toBe(PurchaseStatus::Failed)
        ->and(PurchaseAttempt::count())->toBe(3)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1)
        ->and(WalletLedgerEntry::where('entry_type', 'purchase_refund')->count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

it('never fails over or refunds when parallel executions meet an unknown outcome', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan, 1);
    puxRoute($plan, 2);
    $user = puxCustomer(100_000);
    $purchase = puxService()->create($user, $plan, '08012345678', null, 'unclear');

    $results = collect(pucRace(array_fill(0, 10, ['execute', $user->id, $purchase->id, 3, '-', 'timeout,succeeded', 40])));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(PurchaseAttempt::sole()->status)->toBe(PurchaseAttemptStatus::Unknown)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(90_000);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

/** A debited purchase whose first route answered unclearly and whose re-check is due; route 2 must never be tried. */
function pucUnclear(): Purchase
{
    $plan = puxPlan('data', 10_000);
    puxRoute($plan, 1);
    puxRoute($plan, 2);
    FakeProvider::$purchaseScript = ['timeout'];
    $purchase = puxService()->purchase(puxCustomer(100_000), $plan, '08012345678', null, (string) Str::uuid());
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return $purchase->fresh();
}

function pucStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** Exactly one move to a final status, and nothing tried on route 2. */
function pucSettledOnce(Purchase $purchase): void
{
    $purchase->refresh();
    expect(PurchaseStatusChange::where('purchase_id', $purchase->id)->whereIn('new_status', ['successful', 'failed'])->count())->toBe($purchase->isFinal() ? 1 : 0)
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->count())->toBe(1);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($purchase->user_id);
}

it('settles a success once when scheduled reconciliation and staff re-checks race', function () {
    $purchase = pucUnclear();
    $staff = pucStaff();

    $results = collect(pucRace([
        ...array_fill(0, 5, ['reconcile', 0, 0, 2, '-', 'succeeded,succeeded', 60]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, '-', 'succeeded,succeeded', 60]),
    ]));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
        ->and(Wallet::where('user_id', $purchase->user_id)->sole()->balance_kobo)->toBe(90_000);
    pucSettledOnce($purchase);
});

it('refunds exactly once when duplicate reconciliation runs see a definite failure', function () {
    $purchase = pucUnclear();

    $results = collect(pucRace(array_fill(0, 10, ['reconcile', 0, 0, 3, '-', 'failed_definite,failed_definite,failed_definite', 40])));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Failed)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(1)
        ->and(Wallet::where('user_id', $purchase->user_id)->sole()->balance_kobo)->toBe(100_000);
    pucSettledOnce($purchase);
});

it('never refunds or fails over when parallel re-checks keep getting unknown', function () {
    $purchase = pucUnclear();
    $staff = pucStaff();

    $results = collect(pucRace([
        ...array_fill(0, 5, ['reconcile', 0, 0, 3, '-', 'unknown,unknown,unknown', 30]),
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 3, '-', 'unknown,unknown,unknown', 30]),
    ]));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
        ->and($purchase->fresh()->check_count)->toBeGreaterThanOrEqual(1)
        ->and(Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count())->toBe(0)
        ->and(Wallet::where('user_id', $purchase->user_id)->sole()->balance_kobo)->toBe(90_000);
    pucSettledOnce($purchase);
});

it('produces exactly one outcome when concurrent re-checks get conflicting answers', function () {
    $purchase = pucUnclear();
    $staff = pucStaff();

    $results = collect(pucRace([
        ...array_fill(0, 5, ['recheck', $staff->id, $purchase->id, 2, '-', 'succeeded,succeeded', 50]),
        ...array_fill(0, 5, ['reconcile', 0, 0, 2, '-', 'failed_definite,failed_definite', 50]),
    ]));

    $purchase->refresh();
    $refunds = Transaction::where('idempotency_key', 'purchase-refund:'.$purchase->reference)->count();
    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($purchase->status)->toBeIn([PurchaseStatus::Successful, PurchaseStatus::Failed])
        ->and($refunds)->toBe($purchase->status === PurchaseStatus::Failed ? 1 : 0)
        ->and(Wallet::where('user_id', $purchase->user_id)->sole()->balance_kobo)->toBe($purchase->status === PurchaseStatus::Failed ? 100_000 : 90_000);
    pucSettledOnce($purchase);
});

/*
 * CP7: races through the customer Buy form (HTTP kernel, signed-in customer), against wallet
 * credits and refunds, a price change and a wallet freeze. Tests only; no application changes.
 */

it('turns a parallel double submit of one confirmed Buy form into one purchase, one debit and one provider call', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    $user = puxCustomer(100_000);
    $token = (string) Str::uuid();

    $results = collect(pucRace(array_fill(0, 10, ['submit', $user->id, $plan->id, 3, "data|{$token}|+234 801 234 5678|10000", 'succeeded', 50])));

    $purchase = Purchase::sole();
    expect($results)->toHaveCount(30)
        ->and($results->where('result', '!=', 'ok')->values()->all())->toBe([])
        ->and($results->pluck('reference')->unique()->values()->all())->toBe([$purchase->reference])
        ->and($purchase->recipient)->toBe('08012345678')
        ->and($purchase->amount_kobo)->toBe(10_000)
        ->and($purchase->status)->toBe(PurchaseStatus::Successful)
        ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(90_000);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

it('lets only one of many parallel Buy forms reusing one token with different details through', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    $user = puxCustomer(100_000);
    $token = (string) Str::uuid();
    $phones = array_map(fn ($i) => '0801234560'.$i, range(0, 9));

    $results = collect(pucRace(array_map(fn ($phone) => ['submit', $user->id, $plan->id, 2, "data|{$token}|{$phone}|10000", 'succeeded', 30], $phones)));

    $purchase = Purchase::sole();
    $winners = $results->where('result', 'ok');
    expect($results)->toHaveCount(20)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($winners->pluck('phone')->unique()->values()->all())->toBe([$purchase->recipient])
        ->and($winners->pluck('reference')->unique()->values()->all())->toBe([$purchase->reference])
        ->and($results->where('result', 'refused')->count())->toBe(18)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())
        ->toBe(['This request was already used for a different purchase. Please start again.'])
        ->and(Transaction::where('type', 'purchase')->count())->toBe(1)
        ->and(PurchaseAttempt::count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(90_000);
    pucPurchaseConsistent($purchase);
    pucWalletConsistent($user->id);
});

it('keeps one wallet exact while purchases, definite-failure refunds and credits race', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    $user = puxCustomer(200_000);

    $results = collect(pucRace([
        ...array_fill(0, 3, ['buy', $user->id, $plan->id, 10, 'ok', 'succeeded', 10]),
        ...array_fill(0, 3, ['buy', $user->id, $plan->id, 10, 'fail', 'failed_definite', 10]),
        ...array_fill(0, 2, ['credit', $user->id, 1_000, 10, '-', '-', 0]),
    ]));

    $successful = Purchase::where('status', PurchaseStatus::Successful)->count();
    $failed = Purchase::where('status', PurchaseStatus::Failed)->count();
    expect($results)->toHaveCount(80)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'credited')->count())->toBe(20)
        ->and(Purchase::count())->toBe($successful + $failed) // nothing left pending
        ->and($results->where('result', 'refused')->count())->toBe(60 - Purchase::count())
        ->and(Transaction::where('type', 'purchase')->where('direction', 'debit')->count())->toBe(Purchase::count())
        ->and(Transaction::where('type', 'purchase')->where('direction', 'credit')->count())->toBe($failed)
        ->and(PurchaseAttempt::count())->toBe(Purchase::count())
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(200_000 + 20 * 1_000 - 10_000 * $successful);
    foreach (Purchase::all() as $purchase) {
        pucPurchaseConsistent($purchase);
    }
    pucWalletConsistent($user->id);
});

it('charges every purchase exactly its confirmed price while the price changes mid-race', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    $user = puxCustomer(1_000_000);

    $results = collect(pucRace([
        ...array_fill(0, 4, ['submit', $user->id, $plan->id, 10, 'data|unique|08012345678|10000', 'succeeded', 30]),
        ...array_fill(0, 4, ['submit', $user->id, $plan->id, 10, 'data|unique|08012345678|12000', 'succeeded', 30]),
        ['reprice', $user->id, $plan->id, 1, '12000', '-', 120],
    ]));

    $ok = $results->where('result', 'ok');
    $refused = $results->where('result', 'refused');
    expect($results)->toHaveCount(81)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'repriced')->count())->toBe(1)
        ->and($ok->count() + $refused->count())->toBe(80)
        ->and($refused->pluck('message')->unique()->values()->all())->toBe(['The price has changed. Please review the new price and confirm again.'])
        ->and($ok->where('confirmed', 10_000)->count())->toBeGreaterThan(0) // bought before the change
        ->and($ok->where('confirmed', 12_000)->count())->toBeGreaterThan(0) // bought after it
        ->and(Purchase::count())->toBe($ok->count())
        ->and(Transaction::where('type', 'purchase')->count())->toBe($ok->count())
        ->and(PurchaseAttempt::count())->toBe($ok->count())
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(1_000_000 - $ok->sum('confirmed'));
    foreach ($ok as $row) {
        $purchase = Purchase::where('reference', $row['reference'])->sole();
        expect($purchase->amount_kobo)->toBe($row['confirmed'])
            ->and(Transaction::whereKey($purchase->debit_transaction_id)->sole()->amount_kobo)->toBe($row['confirmed']);
        pucPurchaseConsistent($purchase);
    }
    pucWalletConsistent($user->id);
});

it('commits no purchase debit after a wallet freeze that races with purchases', function () {
    $plan = puxPlan('data', 10_000);
    puxRoute($plan);
    $user = puxCustomer(1_000_000);

    $results = collect(pucRace([
        ...array_fill(0, 6, ['buy', $user->id, $plan->id, 10, 'f', 'succeeded', 20]),
        ['freeze', $user->id, 0, 1, '-', '-', 100],
    ]));

    $freeze = $results->firstWhere('result', 'frozen');
    $refused = $results->where('result', 'refused');
    expect($results)->toHaveCount(61)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($freeze)->not->toBeNull()
        ->and(Purchase::count())->toBeGreaterThan(0) // some bought before the freeze
        ->and($refused->count())->toBeGreaterThan(0) // and some were blocked by it
        ->and($refused->pluck('message')->unique()->values()->all())->toBe(['This wallet is frozen; debits are blocked.'])
        ->and(Purchase::count())->toBe(60 - $refused->count()) // a blocked purchase leaves no row
        ->and(PurchaseAttempt::count())->toBe(Purchase::count()) // and makes no provider call
        ->and(Transaction::where('type', 'purchase')->count())->toBe(Purchase::count())
        ->and(WalletLedgerEntry::where('id', '>', $freeze['max_entry_id'])->count())->toBe(0)
        ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(1_000_000 - 10_000 * Purchase::count());
    foreach (Purchase::all() as $purchase) {
        expect($purchase->status)->toBe(PurchaseStatus::Successful);
        pucPurchaseConsistent($purchase);
    }
    pucWalletConsistent($user->id);
});
