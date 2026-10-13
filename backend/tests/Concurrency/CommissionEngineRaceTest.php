<?php

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\FailedCommissionAttempt;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../Support/Referrals/helpers.php';

/*
 * Phase 12 CP4 on real MariaDB: the commission step under concurrency and
 * database aborts. Separate PHP processes (tests/Concurrency/
 * commission_worker.php) buy, re-check, freeze, disable, retype, change
 * rates, adjust wallets and log in at the same moment, through the app's own
 * services. Test-only pauses on the query log hold the commission step open
 * so the changes really land in the middle of it. Every commission is paid
 * once, at the values in force when it was credited, and none after a freeze,
 * disable, type change or rate change committed; no deadlock is ever caused.
 * A deadlock, lost connection, lock wait timeout, killed process or a
 * transaction the server rolled back is never recorded as a failure: the
 * success is rolled back as a whole, retried, and settled by a later re-check.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    cmxDrivers();
});

const CER_ENV = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

/** A commission worker process for $spec, started with $run as its run and start file. */
function cerWorker(string $run, array $spec)
{
    return Process::path(base_path())->env(CER_ENV)->timeout(180)
        ->start([PHP_BINARY, base_path('tests/Concurrency/commission_worker.php'), $run, $run, json_encode($spec)]);
}

function cerWaitFor(string $file): void
{
    $deadline = microtime(true) + 60;
    while (! file_exists($file) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(file_exists($file))->toBeTrue("timeout waiting for {$file}");
}

function cerCleanUp(string $run): void
{
    array_map('unlink', array_filter([$run, $run.'.locked', $run.'.partner-locked', $run.'.paused', ...glob($run.'.ready.*')], 'file_exists'));
}

/**
 * Starts the workers together and returns their JSON lines.
 *
 * @param  list<array<string, mixed>>  $workers  one spec each (see commission_worker.php)
 * @return list<array<string, mixed>>
 */
function cerRace(array $workers): array
{
    $run = sys_get_temp_dir().'/commission-race-'.uniqid();
    $pool = Process::pool(function ($pool) use ($workers, $run) {
        foreach ($workers as $spec) {
            $pool->path(base_path())->env(CER_ENV)->timeout(180)
                ->command([PHP_BINARY, base_path('tests/Concurrency/commission_worker.php'), $run, $run, json_encode($spec)]);
        }
    })->start();

    try {
        $deadline = microtime(true) + 60;
        while (count(glob($run.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(count(glob($run.'.ready.*')))->toBe(count($workers));
        touch($run);
        $results = $pool->wait();
    } finally {
        cerCleanUp($run);
    }

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/** How many deadlocks InnoDB has detected since the server started. */
function cerDeadlocks(): int
{
    return (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->Value;
}

/**
 * A referrer with a Main Wallet, $count customers they referred (₦10,000 each), Data at 2.5% capped at ₦1,000, and a
 * ₦100 Data plan: each successful purchase pays 250 kobo.
 *
 * @return array{0: User, 1: list<int>, 2: Plan}
 */
function cerSetup(int $count): array
{
    $referrer = cmxReferrer();
    $buyers = array_map(fn () => cmxReferred($referrer)->id, range(1, $count));
    cmxSetting('data', 250, 100_000);

    return [$referrer, $buyers, cmxPlan('data', 10_000)];
}

/** Every worker succeeded: no error, refusal or problem report. */
function cerNoErrors(Collection $results): void
{
    expect($results->whereIn('result', ['error', 'refused', 'problems'])->values()->all())->toBe([]);
}

/** The referrer's ledger adds up entry by entry to the cached balance, and the three integrity checks pass. */
function cerConsistent(User $referrer): void
{
    $wallet = Wallet::where('user_id', $referrer->id)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running);
    }
    expect($wallet->balance_kobo)->toBe($running);
    cmxClean();
}

/** The purchase is still pending with its unclear attempt, and no commission, failed attempt or commission money exists. */
function cerNothingSettled(Purchase $purchase, PurchaseAttemptStatus $attempt = PurchaseAttemptStatus::Unknown): void
{
    expect($purchase->fresh()->status)->toBe(PurchaseStatus::Pending)
        ->and(PurchaseAttempt::where('purchase_id', $purchase->id)->sole()->status)->toBe($attempt)
        ->and(Commission::count())->toBe(0)
        ->and(FailedCommissionAttempt::count())->toBe(0)
        ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0);
}

/** A referred customer's unclear ₦100 Data purchase, due for its re-check: [referrer, purchase]. */
function cerUnclear(?User $referrer = null): array
{
    $referrer ??= cmxReferrer();
    cmxSetting('data', 250, 100_000);
    $purchase = cmxBuy(cmxReferred($referrer), cmxPlan('data', 10_000), 'timeout');
    $purchase->forceFill(['next_check_at' => now()->subMinute()])->save();

    return [$referrer, $purchase->fresh()];
}

/** The re-check that finally settles $purchase pays its commission once. */
function cerPaysOnce(User $referrer, Purchase $purchase): void
{
    expect(cmxRecheck($purchase->fresh())->status)->toBe(PurchaseStatus::Successful)
        ->and(Commission::sole()->purchase_id)->toBe($purchase->id)
        ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(1)
        ->and(FailedCommissionAttempt::count())->toBe(0)
        ->and(cmxBalance($referrer))->toBe(250);
    cerConsistent($referrer);
}

describe('concurrent successes', function () {
    it('pays twenty referred customers\' parallel purchases to one referrer exactly once each, with an exact wallet and no deadlock', function () {
        [$referrer, $buyers, $plan] = cerSetup(20);
        $deadlocks = cerDeadlocks();

        $results = collect(cerRace(array_map(fn (array $pair) => ['mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 2,
            'provider_ms' => 20, 'pause' => 30], array_chunk($buyers, 2))));

        cerNoErrors($results);
        expect($results)->toHaveCount(20)
            ->and($results->pluck('status')->unique()->values()->all())->toBe(['successful'])
            ->and(Commission::count())->toBe(20)
            ->and(Commission::pluck('purchase_id')->sort()->values()->all())->toBe(Purchase::pluck('id')->sort()->values()->all())
            ->and(FailedCommissionAttempt::count())->toBe(0)
            ->and(cmxBalance($referrer))->toBe(20 * 250)
            ->and(cerDeadlocks())->toBe($deadlocks);
        cerConsistent($referrer);
    });

    it('settles one purchase three ways at once and pays its commission once', function () {
        $staff = cmxStaff();
        [$referrer, $purchase] = cerUnclear();

        $results = collect(cerRace([
            ...array_fill(0, 4, ['mode' => 'reconcile', 'count' => 2, 'answer' => 'succeeded', 'provider_ms' => 40, 'pause' => 50]),
            ...array_fill(0, 4, ['mode' => 'recheck', 'staff' => $staff->id, 'purchase' => $purchase->id, 'count' => 2, 'answer' => 'succeeded',
                'provider_ms' => 40, 'pause' => 50]),
            ...array_fill(0, 2, ['mode' => 'execute', 'purchase' => $purchase->id, 'count' => 2]),
        ]));

        cerNoErrors($results);
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and(Commission::sole()->purchase_id)->toBe($purchase->id)
            ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(1)
            ->and(FailedCommissionAttempt::count())->toBe(0)
            ->and(cmxBalance($referrer))->toBe(250);
        cerConsistent($referrer);
    });

    it('records one failed attempt, and never a commission, when a purchase whose commission cannot be credited is settled three ways at once', function () {
        $staff = cmxStaff();
        $referrer = User::factory()->create(); // no Main Wallet
        [, $purchase] = cerUnclear($referrer);

        $results = collect(cerRace([
            ...array_fill(0, 4, ['mode' => 'reconcile', 'count' => 2, 'answer' => 'succeeded', 'provider_ms' => 40, 'pause' => 50]),
            ...array_fill(0, 4, ['mode' => 'recheck', 'staff' => $staff->id, 'purchase' => $purchase->id, 'count' => 2, 'answer' => 'succeeded',
                'provider_ms' => 40, 'pause' => 50]),
        ]));

        cerNoErrors($results);
        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
            ->and(FailedCommissionAttempt::sole()->only(['purchase_id', 'referrer_id', 'reason_code']))->toBe(['purchase_id' => $purchase->id,
                'referrer_id' => $referrer->id, 'reason_code' => CommissionFailureReason::WalletUnavailable])
            ->and(Commission::count())->toBe(0)
            ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0);
        cmxClean();
    });

    it('keeps the referrer\'s wallet exact, with no deadlock, while commissions, their own purchases, adjustments, logins and checks race (T4)', function () {
        [$referrer, $buyers, $plan] = cerSetup(10);
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($referrer), 1_000_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
        $deadlocks = cerDeadlocks();

        $results = collect(cerRace([
            ...array_map(fn (array $pair) => ['mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 6, 'provider_ms' => 10, 'pause' => 10],
                array_chunk($buyers, 2)),
            ...array_fill(0, 2, ['mode' => 'buy', 'buyers' => [$referrer->id], 'plan' => $plan->id, 'count' => 6, 'provider_ms' => 10]),
            ['mode' => 'credit', 'user' => $referrer->id, 'amount' => 1_000, 'count' => 10],
            ['mode' => 'debit', 'user' => $referrer->id, 'amount' => 500, 'count' => 10],
            ...array_fill(0, 2, ['mode' => 'login', 'user' => $referrer->id, 'count' => 40]),
            ['mode' => 'verify', 'count' => 10],
        ]));

        cerNoErrors($results);
        expect($results->where('result', 'clean'))->toHaveCount(10) // commissions:verify found nothing while they committed
            ->and(Commission::count())->toBe(30)
            ->and(Purchase::where('user_id', $referrer->id)->count())->toBe(12) // their own purchases earn no one anything
            ->and(Purchase::where('status', '!=', PurchaseStatus::Successful->value)->count())->toBe(0)
            ->and(cmxBalance($referrer))->toBe(1_000_000 + 30 * 250 + 10 * 1_000 - 10 * 500 - 12 * 10_000)
            ->and(cerDeadlocks())->toBe($deadlocks);
        cerConsistent($referrer);
    });
});

describe('changes in the middle of the commission step', function () {
    it('credits no commission after a freeze of the referrer\'s wallet that races with the commission step, recording nothing for the rest', function () {
        $staff = cmxStaff();
        [$referrer, $buyers, $plan] = cerSetup(12);

        $results = collect(cerRace([
            ...array_map(fn (array $pair) => ['mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 2, 'provider_ms' => 20, 'pause' => 150],
                array_chunk($buyers, 2)),
            ['mode' => 'freeze', 'user' => $referrer->id, 'staff' => $staff->id],
        ]));

        $freeze = $results->firstWhere('result', 'freeze');
        $credits = fn () => WalletLedgerEntry::where('wallet_id', Wallet::where('user_id', $referrer->id)->value('id'))
            ->where('entry_type', LedgerEntryType::CommissionCredit->value);
        cerNoErrors($results);
        expect($freeze)->not->toBeNull()
            ->and(Purchase::where('status', PurchaseStatus::Successful->value)->count())->toBe(12)
            ->and(Commission::count())->toBeGreaterThan(0)->toBeLessThan(12) // some were credited before the freeze, the rest never
            ->and($credits()->count())->toBe(Commission::count())
            ->and($credits()->where('id', '>', $freeze['max_entry_id'])->count())->toBe(0) // nothing credited after the freeze committed
            ->and(FailedCommissionAttempt::count())->toBe(0) // a frozen wallet is an exclusion, not a failure
            ->and(Wallet::where('user_id', $referrer->id)->sole()->status)->toBe(WalletStatus::Frozen);
        cerConsistent($referrer);
    });

    it('credits no commission after the referrer is disabled in the middle, recording nothing for the rest', function () {
        $staff = cmxStaff();
        [$referrer, $buyers, $plan] = cerSetup(12);

        $results = collect(cerRace([
            ...array_map(fn (array $pair) => ['mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 2, 'provider_ms' => 20, 'pause' => 150],
                array_chunk($buyers, 2)),
            ['mode' => 'disable', 'user' => $referrer->id, 'staff' => $staff->id],
        ]));

        $disable = $results->firstWhere('result', 'disable');
        cerNoErrors($results);
        expect($disable)->not->toBeNull()
            ->and(Purchase::where('status', PurchaseStatus::Successful->value)->count())->toBe(12)
            ->and(Commission::count())->toBeGreaterThan(0)->toBeLessThan(12)
            ->and(Commission::where('id', '>', $disable['max_commission_id'])->count())->toBe(0) // none after the disable committed
            ->and(FailedCommissionAttempt::count())->toBe(0)
            ->and($referrer->fresh()->status)->toBe(UserStatus::Disabled);
        cerConsistent($referrer);
    });

    it('credits no commission for a buyer\'s purchases after they become an API User in the middle', function () {
        $staff = cmxStaff();
        [$referrer, [$buyer], $plan] = cerSetup(1);

        $results = collect(cerRace([
            ...array_fill(0, 6, ['mode' => 'buy', 'buyers' => [$buyer], 'plan' => $plan->id, 'count' => 2, 'provider_ms' => 20, 'pause' => 150]),
            ['mode' => 'retype', 'user' => $buyer, 'type' => UserType::ApiUser->value, 'staff' => $staff->id],
        ]));

        $retype = $results->firstWhere('result', 'retype');
        expect($results->where('result', 'error')->values()->all())->toBe([])
            ->and($retype)->not->toBeNull()
            ->and(Commission::count())->toBeGreaterThan(0)
            ->and(Commission::where('id', '>', $retype['max_commission_id'])->count())->toBe(0) // none after the type change committed
            ->and(FailedCommissionAttempt::count())->toBe(0)
            ->and(User::findOrFail($buyer)->user_type)->toBe(UserType::ApiUser)
            // Purchases started after the change are refused (no API User price): they never get as far as a commission.
            ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())->toBeIn([[], ['No price for API User.']]);
        cerConsistent($referrer);
    });

    it('pays every commission at the rate in force when it was credited, while the rate changes in the middle', function () {
        $staff = cmxStaff();
        [$referrer, $buyers, $plan] = cerSetup(12);

        $results = collect(cerRace([
            ...array_map(fn (array $pair) => ['mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 2, 'provider_ms' => 20, 'pause' => 150],
                array_chunk($buyers, 2)),
            ['mode' => 'rate', 'service' => 'data', 'rate' => 500, 'cap' => 100_000, 'staff' => $staff->id],
        ]));

        cerNoErrors($results);
        $rate = $results->firstWhere('result', 'rate');
        $old = Commission::where('rate_bps', 250);
        $new = Commission::where('rate_bps', 500);
        expect($rate)->not->toBeNull()
            ->and(Commission::count())->toBe(12)
            ->and($old->max('id'))->toBeLessThanOrEqual($rate['max_commission_id']) // every old-rate commission committed before the new rate
            ->and($old->count())->toBeGreaterThan(0)
            ->and($new->count())->toBeGreaterThan(0)
            ->and($old->count() + $new->count())->toBe(12)
            ->and($old->max('id'))->toBeLessThan($new->min('id')) // nothing credited at the old rate once the new one was saved
            ->and(Commission::all()->every(fn (Commission $c) => $c->amount_kobo === Commission::amountFor($c->base_amount_kobo, $c->rate_bps, $c->cap_kobo)))->toBeTrue()
            ->and(cmxBalance($referrer))->toBe($old->count() * 250 + $new->count() * 500);
        cerConsistent($referrer);
    });
});

describe('database aborts in the commission step', function () {
    it('passes a real deadlock on: the success is rolled back and retried, paying once, with no failed attempt', function () {
        [$referrer, [$buyer], $plan] = cerSetup(1);
        $wallet = Wallet::where('user_id', $referrer->id)->value('id');
        $deadlocks = cerDeadlocks();

        $results = collect(cerRace([
            ['mode' => 'buy', 'buyers' => [$buyer], 'plan' => $plan->id, 'wait_wallet' => $wallet],
            ['mode' => 'partner', 'user' => $buyer, 'wallet' => $wallet],
        ]));

        expect($results->firstWhere('result', 'ok')['status'] ?? null)->toBe('successful')
            ->and($results->firstWhere('result', 'partner'))->not->toBeNull() // the partner survived: the commission step was the victim
            ->and(cerDeadlocks())->toBe($deadlocks + 1)
            ->and(Commission::sole()->purchase_id)->toBe(Purchase::sole()->id)
            ->and(FailedCommissionAttempt::count())->toBe(0)
            ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(1)
            ->and(DB::table('cache')->count())->toBe(0);
        cerConsistent($referrer);
    });

    it('passes a deadlock on every try: after three the purchase stays pending with nothing recorded, and a later re-check pays once', function () {
        [$referrer, $purchase] = cerUnclear();
        $tries = 0;
        CommissionSetting::retrieved(function () use (&$tries) {
            if ($tries < 3) {
                $tries++;
                throw cmxDatabaseError('40001', 'Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        expect(fn () => cmxRecheck($purchase))->toThrow(DeadlockException::class)
            ->and($tries)->toBe(3) // the whole success was tried three times
            ->and(DB::transactionLevel())->toBe(0);
        cerNothingSettled($purchase);
        cerPaysOnce($referrer, $purchase);
    });

    it('passes a lost connection on: nothing is recorded, the purchase stays pending, and a later re-check pays once', function () {
        [$referrer, $purchase] = cerUnclear();
        config(['database.connections.killer' => config('database.connections.mysql')]);
        $connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $killed = false;
        CommissionSetting::retrieved(function () use (&$killed, $connection) {
            if (! $killed) {
                $killed = true;
                DB::connection('killer')->statement("KILL {$connection}");
            }
        });

        try {
            cmxRecheck($purchase);
            $thrown = null;
        } catch (Throwable $e) {
            $thrown = $e;
        }
        DB::purge();

        expect($killed)->toBeTrue()
            ->and($thrown)->not->toBeNull()
            ->and((new LostConnectionDetector)->causedByLostConnection($thrown))->toBeTrue();
        cerNothingSettled($purchase);
        cerPaysOnce($referrer, $purchase);
    });

    it('passes a lock wait timeout on: the success is retried, then stays pending with nothing recorded, and pays once when the wallet is free', function () {
        [$referrer, $purchase] = cerUnclear();
        $run = sys_get_temp_dir().'/commission-hold-'.uniqid();
        $holder = cerWorker($run, ['mode' => 'hold', 'user' => $referrer->id, 'hold' => 8_000]);

        try {
            cerWaitFor($run.'.ready.'.$holder->id());
            touch($run);
            cerWaitFor($run.'.locked');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            expect(fn () => cmxRecheck($purchase))->toThrow(DeadlockException::class, 'Lock wait timeout exceeded');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            cerNothingSettled($purchase);
            expect($holder->wait()->successful())->toBeTrue();
        } finally {
            cerCleanUp($run);
        }

        cerPaysOnce($referrer, $purchase);
    });

    it('passes on an error after which the server has rolled the transaction back, recording nothing, and a later re-check pays once', function () {
        [$referrer, $purchase] = cerUnclear();
        $armed = true;
        Commission::creating(function () use (&$armed) {
            if ($armed) { // after the purchase was marked successful and the credit posted
                $armed = false;
                DB::getPdo()->exec('ROLLBACK'); // the transaction ends behind Laravel's back, as when the server aborts it
                throw new RuntimeException('Unexpected test failure');
            }
        });

        try {
            cmxRecheck($purchase);
            $thrown = null;
        } catch (Throwable $e) {
            $thrown = $e;
        }
        DB::purge();

        expect($armed)->toBeFalse()
            ->and($thrown)->toBeInstanceOf(PDOException::class)
            ->and($thrown->getMessage())->toContain('SAVEPOINT trans2 does not exist');
        cerNothingSettled($purchase);
        cerPaysOnce($referrer, $purchase);
    });

    it('records nothing when the process dies in the middle of the commission step, and a later re-check pays once', function () {
        [$referrer, [$buyer], $plan] = cerSetup(1);
        $run = sys_get_temp_dir().'/commission-kill-'.uniqid();
        $worker = cerWorker($run, ['mode' => 'buy', 'buyers' => [$buyer], 'plan' => $plan->id, 'die' => true]);

        try {
            cerWaitFor($run.'.ready.'.$worker->id());
            touch($run);
            cerWaitFor($run.'.locked'); // inside the commission step, holding its locks
            $worker->signal(9); // SIGKILL
            $worker->wait();
        } finally {
            cerCleanUp($run);
        }

        $purchase = Purchase::sole();
        cerNothingSettled($purchase, PurchaseAttemptStatus::Started); // the provider's answer was never applied
        $this->travel(config('purchases.stale_attempt_minutes') + 1)->minutes();
        cerPaysOnce($referrer, $purchase);
    });
});
