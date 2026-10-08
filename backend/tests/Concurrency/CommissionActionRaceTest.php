<?php

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Referrals\CommissionStatus;
use App\Support\Wallet\Direction;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../Support/Referrals/helpers.php';

/*
 * Phase 12 CP5 on real MariaDB: reversing and cancelling commissions under
 * concurrency and database aborts. Separate PHP processes (tests/Concurrency/
 * commission_action_worker.php) submit the Reverse and Cancel forms through
 * the HTTP kernel, spend from or freeze the referrer's wallet, or buy (CP4's
 * commission_worker.php) at the same moment. Test-only pauses on the query
 * log hold an action open so the other side really lands in the middle of
 * it. A commission gets one action, ever, and at most one reversal debit; a
 * frozen wallet is never debited; the balance never goes below zero; no
 * deadlock is ever caused. A deadlock, a lock wait timeout or a killed
 * process is never recorded as an action: the whole action is rolled back,
 * retried while attempts remain, and otherwise not recorded at all.
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

const CAR_ENV = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array'];

/** A worker spec: $staff submits $type's form for $commission with a token of its own (or $token), plus $options. */
function carAct(Commission $commission, CommissionActionType $type, SystemUser $staff, array $options = [], ?string $token = null): array
{
    return ['mode' => 'act', 'staff' => $staff->id, 'commission' => $commission->reference, 'type' => $type->value,
        'token' => $token ?? CommissionActionToken::issue($commission, $type, $staff)] + $options;
}

/** The command line and environment of one worker (`script` commission: CP4's purchase worker). */
function carCommand(string $run, array $spec): array
{
    $script = ($spec['script'] ?? 'action') === 'commission' ? 'commission_worker.php' : 'commission_action_worker.php';
    $env = CAR_ENV + (isset($spec['token']) ? ['COMMISSION_ACTION_TEST_TOKEN' => $spec['token']] : []);
    unset($spec['token'], $spec['script']);

    return [[PHP_BINARY, base_path('tests/Concurrency/'.$script), $run, $run, json_encode($spec)], $env];
}

function carCleanUp(string $run): void
{
    array_map('unlink', array_filter([$run, $run.'.locked', $run.'.partner-locked', $run.'.paused', $run.'.wallet-locked',
        ...glob($run.'.ready.*')], 'file_exists'));
}

/**
 * Starts the workers together and returns their JSON lines, each with its worker's index as `worker`.
 *
 * @param  list<array<string, mixed>>  $workers  one spec each (see commission_action_worker.php)
 * @return Collection<int, array<string, mixed>>
 */
function carRace(array $workers): Collection
{
    $run = sys_get_temp_dir().'/commission-action-race-'.uniqid();
    $pool = Process::pool(function ($pool) use ($workers, $run) {
        foreach ($workers as $index => $spec) {
            [$command, $env] = carCommand($run, $spec);
            $pool->as((string) $index)->path(base_path())->env($env)->timeout(180)->command($command);
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
        carCleanUp($run);
    }

    $lines = [];
    foreach ($results->collect() as $index => $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = ['worker' => (int) $index] + json_decode($line, true);
        }
    }

    return collect($lines);
}

/** How many deadlocks InnoDB has detected since the server started. */
function carDeadlocks(): int
{
    return (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->Value;
}

/** The successful commission reversal debits, as their commission's idempotency keys. */
function carReversalDebits(): array
{
    return Transaction::where('type', TransactionType::Commission->value)->where('direction', Direction::Debit->value)
        ->orderBy('id')->pluck('idempotency_key')->all();
}

/** The referrer's ledger adds up entry by entry to the cached balance, never below zero, and the three integrity checks pass. */
function carConsistent(User $referrer): void
{
    $wallet = Wallet::where('user_id', $referrer->id)->sole();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running)->toBeGreaterThanOrEqual(0);
    }
    expect($wallet->balance_kobo)->toBe($running);
    cmxClean();
}

/** One worker acted and the others were refused with $refusal (a message, or one of several), with no error. */
function carOneWinner(Collection $results, int $workers, string|array $refusal): array
{
    $winner = $results->where('result', 'ok')->values();
    expect($results)->toHaveCount($workers)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($winner)->toHaveCount(1)
        ->and($results->where('result', 'refused')->pluck('message')->unique()->diff((array) $refusal)->values()->all())->toBe([])
        ->and($results->where('result', 'refused'))->toHaveCount($workers - 1);

    return $winner->sole();
}

const CAR_REVERSED = 'This commission was already reversed. A commission can have only one action, ever.';
const CAR_CANCELLED = 'This commission was already cancelled. A commission can have only one action, ever.';

describe('one action, ever', function () {
    it('lets one of two staff members reverse a commission at the same moment: one action, one debit, the other refused', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['pause_commission' => 300]),
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['after' => 'paused']),
        ]);

        expect(carOneWinner($results, 2, CAR_REVERSED)['worker'])->toBe(0) // the second waited for the first one's lock
            ->and(CommissionAction::sole()->type)->toBe(CommissionActionType::Reversal)
            ->and(carReversalDebits())->toBe(['commission-reversal:'.$commission->reference])
            ->and(cmxBalance($referrer))->toBe(5_000)
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Reversed)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('lets one of two staff members cancel a commission at the same moment, moving no money', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $money = [Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)];
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Cancellation, cmxStaff(), ['pause_commission' => 300]),
            carAct($commission, CommissionActionType::Cancellation, cmxStaff(), ['after' => 'paused']),
        ]);

        expect(carOneWinner($results, 2, CAR_CANCELLED)['worker'])->toBe(0)
            ->and(CommissionAction::sole()->type)->toBe(CommissionActionType::Cancellation)
            ->and([Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)])->toBe($money)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('lets one of a reversal and a cancellation submitted at the same moment win, whichever is first', function (CommissionActionType $first) {
        [$referrer, $commission] = cmxCommission(5_000);
        $second = $first === CommissionActionType::Reversal ? CommissionActionType::Cancellation : CommissionActionType::Reversal;
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, $first, cmxStaff(), ['pause_commission' => 300]),
            carAct($commission, $second, cmxStaff(), ['after' => 'paused']),
        ]);

        carOneWinner($results, 2, $first === CommissionActionType::Reversal ? CAR_REVERSED : CAR_CANCELLED);
        expect(CommissionAction::sole()->type)->toBe($first)
            ->and(carReversalDebits())->toBe($first === CommissionActionType::Reversal ? ['commission-reversal:'.$commission->reference] : [])
            ->and(cmxBalance($referrer))->toBe($first === CommissionActionType::Reversal ? 5_000 : 6_250)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    })->with(['the reversal first' => [CommissionActionType::Reversal], 'the cancellation first' => [CommissionActionType::Cancellation]]);

    it('records one action when eight staff members reverse and cancel one commission at once (action uniqueness race)', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace(array_map(fn (int $i) => carAct($commission, $i % 2 === 0 ? CommissionActionType::Reversal : CommissionActionType::Cancellation,
            cmxStaff(), ['pause_commission' => 50]), range(0, 7)));

        carOneWinner($results, 8, [CAR_REVERSED, CAR_CANCELLED]);
        $action = CommissionAction::sole();
        expect(carReversalDebits())->toBe($action->type === CommissionActionType::Reversal ? ['commission-reversal:'.$commission->reference] : [])
            ->and(cmxBalance($referrer))->toBe($action->type === CommissionActionType::Reversal ? 5_000 : 6_250)
            ->and($results->where('result', 'refused')->pluck('message')->unique()->values()->all())
            ->toBe([$action->type === CommissionActionType::Reversal ? CAR_REVERSED : CAR_CANCELLED])
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('accepts the same form sent six times at once only once (token replay race)', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $token = CommissionActionToken::issue($commission, $type, $staff);
        $deadlocks = carDeadlocks();

        $results = carRace(array_fill(0, 6, carAct($commission, $type, $staff, ['pause_commission' => 50], $token)));

        carOneWinner($results, 6, 'This '.strtolower($type->label()).' was already recorded with this form. Nothing more was changed.');
        expect(CommissionAction::sole()->idempotency_key)->toBe(CommissionActionToken::open($token, $commission, $type, $staff))
            ->and(carReversalDebits())->toBe($type === CommissionActionType::Reversal ? ['commission-reversal:'.$commission->reference] : [])
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    })->with(['reversal' => [CommissionActionType::Reversal], 'cancellation' => [CommissionActionType::Cancellation]]);
});

describe('the wallet decides', function () {
    it('takes the reversal and refuses the referrer\'s own spending that waited for the wallet: never below zero', function () {
        [$referrer, $commission] = cmxCommission(); // the wallet holds exactly the 1,250 kobo commission
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['pause_wallet' => 300]),
            ['mode' => 'debit', 'user' => $referrer->id, 'amount' => 1_000, 'after' => 'wallet-locked'],
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['ok', 'refused'])
            ->and($results->firstWhere('worker', 1)['class'])->toBe(InsufficientFunds::class)
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Reversed)
            ->and(carReversalDebits())->toHaveCount(1)
            ->and(cmxBalance($referrer))->toBe(0)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('refuses the reversal, writing nothing, when spending that held the wallet first leaves less than the commission', function () {
        [$referrer, $commission] = cmxCommission();
        $deadlocks = carDeadlocks();

        $results = carRace([
            ['mode' => 'hold-debit', 'user' => $referrer->id, 'amount' => 1_000, 'hold' => 400],
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['after' => 'locked']),
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['hold-debit', 'refused'])
            ->and($results->firstWhere('worker', 1)['message'])->toBe(ActOnCommission::TOO_LOW)
            ->and(CommissionAction::count())->toBe(0)
            ->and(carReversalDebits())->toBe([])
            ->and(cmxBalance($referrer))->toBe(250)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('keeps the wallet exact while a reversal races many debits and credits: one reversal at most, never below zero', function () {
        [$referrer, $commission] = cmxCommission(2_000); // 3,250 kobo in all
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['pause_commission' => 20]),
            ...array_fill(0, 3, ['mode' => 'debit', 'user' => $referrer->id, 'amount' => 700, 'count' => 3]),
            ['mode' => 'credit', 'user' => $referrer->id, 'amount' => 100, 'count' => 5],
        ]);

        $reversed = $results->firstWhere('worker', 0)['result'] === 'ok';
        $debits = $results->where('result', 'debit')->count();
        expect($results->where('result', 'error')->values()->all())->toBe([])
            ->and($results->firstWhere('worker', 0)['result'])->toBeIn(['ok', 'refused'])
            ->and($results->where('result', 'refused')->where('worker', '>', 0)->pluck('class')->unique()->diff([InsufficientFunds::class])->all())->toBe([])
            ->and(carReversalDebits())->toBe($reversed ? ['commission-reversal:'.$commission->reference] : [])
            ->and(cmxBalance($referrer))->toBe(3_250 + 5 * 100 - $debits * 700 - ($reversed ? 1_250 : 0))->toBeGreaterThanOrEqual(0)
            ->and(carDeadlocks())->toBe($deadlocks);
        if (! $reversed) {
            expect($results->firstWhere('worker', 0)['message'])->toBe(ActOnCommission::TOO_LOW)->and(CommissionAction::count())->toBe(0);
        }
        carConsistent($referrer);
    });
});

describe('a frozen wallet', function () {
    it('refuses a reversal when the wallet is frozen while the reversal is under way, writing nothing', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, $staff, ['pause_commission' => 300]),
            ['mode' => 'freeze', 'staff' => $staff->id, 'user' => $referrer->id, 'after' => 'paused'],
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['refused', 'freeze'])
            ->and($results->firstWhere('worker', 0)['message'])->toBe(ActOnCommission::FROZEN)
            ->and(CommissionAction::count())->toBe(0)
            ->and(carReversalDebits())->toBe([])
            ->and(cmxBalance($referrer))->toBe(6_250)
            ->and(Wallet::where('user_id', $referrer->id)->sole()->status)->toBe(WalletStatus::Frozen)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('finishes a reversal that locked the wallet before the freeze, which then waits its turn', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, $staff, ['pause_wallet' => 300]),
            ['mode' => 'freeze', 'staff' => $staff->id, 'user' => $referrer->id, 'after' => 'wallet-locked'],
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['ok', 'freeze'])
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Reversed)
            ->and(cmxBalance($referrer))->toBe(5_000)
            ->and(Wallet::where('user_id', $referrer->id)->sole()->status)->toBe(WalletStatus::Frozen)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });

    it('cancels while the wallet is being frozen, moving no money either way', function (string $order) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace($order === 'cancellation first' ? [
            carAct($commission, CommissionActionType::Cancellation, $staff, ['pause_commission' => 300]),
            ['mode' => 'freeze', 'staff' => $staff->id, 'user' => $referrer->id, 'after' => 'paused'],
        ] : [
            ['mode' => 'freeze', 'staff' => $staff->id, 'user' => $referrer->id],
            carAct($commission, CommissionActionType::Cancellation, $staff, ['pause_commission' => 300]),
        ]);

        expect($results->pluck('result')->sort()->values()->all())->toBe(['freeze', 'ok'])
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Cancelled)
            ->and(cmxBalance($referrer))->toBe(6_250)
            ->and(carReversalDebits())->toBe([])
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    })->with(['cancellation first', 'freeze first']);
});

describe('alongside commission payments (CP4)', function () {
    it('reverses and cancels earlier commissions while new ones are credited to the same referrer, with no deadlock', function () {
        $referrer = cmxReferrer();
        $commissions = array_map(fn () => cmxCommission(0, $referrer)[1], range(1, 6)); // 7,500 kobo
        $buyers = array_map(fn () => cmxReferred($referrer)->id, range(1, 6));
        $plan = cmxPlan('data', 10_000); // ₦100 at 2.5%: 250 kobo each
        $staff = cmxStaff();
        $deadlocks = carDeadlocks();

        $results = carRace([
            ...array_map(fn (Commission $commission, int $i) => carAct($commission, $i < 3 ? CommissionActionType::Reversal : CommissionActionType::Cancellation,
                $staff, ['pause_commission' => 20, 'pause_wallet' => 20]), $commissions, array_keys($commissions)),
            ...array_map(fn (array $pair) => ['script' => 'commission', 'mode' => 'buy', 'buyers' => $pair, 'plan' => $plan->id, 'count' => 4,
                'provider_ms' => 10, 'pause' => 10], array_chunk($buyers, 2)),
        ]);

        expect($results->where('result', 'error')->values()->all())->toBe([])
            ->and($results->where('result', 'ok')->whereNotNull('message'))->toHaveCount(6)
            ->and($results->whereNotNull('purchase')->pluck('status')->unique()->values()->all())->toBe([PurchaseStatus::Successful->value])
            ->and(Purchase::count())->toBe(6 + 12)
            ->and(Commission::count())->toBe(6 + 12)
            ->and(CommissionAction::count())->toBe(6)
            ->and(carReversalDebits())->toHaveCount(3)
            ->and(cmxBalance($referrer))->toBe(7_500 + 12 * 250 - 3 * 1_250)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);
    });
});

describe('database aborts', function () {
    it('retries an action as a whole after a deadlock, and records nothing when every attempt fails', function (int $deadlocks, CommissionActionType $type) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $before = [Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)];
        $left = $deadlocks;
        CommissionAction::creating(function () use (&$left) {
            if ($left-- > 0) {
                throw cmxDatabaseError('40001', 'Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });
        $act = fn () => app(ActOnCommission::class)->handle($commission, $type, 'Taken back after a review of the purchase.',
            CommissionActionToken::issue($commission, $type, $staff), $staff);

        if ($deadlocks >= 3) {
            expect($act)->toThrow(QueryException::class, 'Deadlock found')
                ->and(CommissionAction::count())->toBe(0)
                ->and([Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)])->toBe($before); // no debit without its action
        } else {
            expect($act()->type)->toBe($type)
                ->and(CommissionAction::count())->toBe(1)
                ->and(carReversalDebits())->toBe($type === CommissionActionType::Reversal ? ['commission-reversal:'.$commission->reference] : [])
                ->and(cmxBalance($referrer))->toBe($type === CommissionActionType::Reversal ? 5_000 : 6_250);
        }
        expect(DB::transactionLevel())->toBe(0);
        carConsistent($referrer);
    })->with(['one deadlock, then success' => [1], 'a deadlock on every attempt' => [3]])->with(['reversal' => [CommissionActionType::Reversal],
        'cancellation' => [CommissionActionType::Cancellation]]);

    it('retries a reversal chosen as a real deadlock victim, recording it once', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $wallet = Wallet::where('user_id', $referrer->id)->sole();
        $deadlocks = carDeadlocks();

        $results = carRace([
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['wait_commission' => true]),
            ['mode' => 'partner', 'wallet' => $wallet->id, 'commission_id' => $commission->id],
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['ok', 'partner'])
            ->and(carDeadlocks())->toBe($deadlocks + 1) // caused on purpose by the partner, and the action was retried
            ->and(CommissionAction::sole()->type)->toBe(CommissionActionType::Reversal)
            ->and(carReversalDebits())->toBe(['commission-reversal:'.$commission->reference])
            ->and(cmxBalance($referrer))->toBe(5_000);
        carConsistent($referrer);
    });

    it('records nothing when the wallet stays locked past the lock wait timeout on every attempt', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $deadlocks = carDeadlocks();

        $results = carRace([
            ['mode' => 'hold', 'user' => $referrer->id, 'hold' => 6_000],
            carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['after' => 'locked', 'lock_wait_timeout' => 1]),
        ]);

        expect($results->sortBy('worker')->pluck('result')->all())->toBe(['hold', 'error'])
            ->and($results->firstWhere('worker', 1))->toMatchArray(['status' => 500, 'class' => QueryException::class])
            ->and(CommissionAction::count())->toBe(0)
            ->and(carReversalDebits())->toBe([])
            ->and(cmxBalance($referrer))->toBe(6_250)
            ->and(carDeadlocks())->toBe($deadlocks);
        carConsistent($referrer);

        expect(carRace([carAct($commission, CommissionActionType::Reversal, cmxStaff())])->sole()['result'])->toBe('ok'); // nothing was left behind
        carConsistent($referrer);
    });

    it('records nothing when the process dies after the debit was written but before the action, and the reversal can then be made', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $entries = WalletLedgerEntry::count();
        $run = sys_get_temp_dir().'/commission-action-race-'.uniqid();
        [$command, $env] = carCommand($run, carAct($commission, CommissionActionType::Reversal, cmxStaff(), ['die' => true]));
        $process = Process::path(base_path())->env($env)->timeout(180)->start($command);

        try {
            $deadline = microtime(true) + 60;
            while (count(glob($run.'.ready.*')) < 1 && microtime(true) < $deadline) {
                usleep(10_000);
            }
            touch($run);
            while (! file_exists($run.'.locked') && microtime(true) < $deadline) {
                usleep(10_000);
            }
            expect(file_exists($run.'.locked'))->toBeTrue();
            $process->signal(9); // SIGKILL
            $process->wait();
        } finally {
            carCleanUp($run);
        }

        // The server rolls the killed connection's transaction back: nothing it wrote was ever committed.
        expect(CommissionAction::count())->toBe(0)
            ->and(carReversalDebits())->toBe([])
            ->and(WalletLedgerEntry::count())->toBe($entries)
            ->and(cmxBalance($referrer))->toBe(6_250);
        carConsistent($referrer);

        expect(carRace([carAct($commission, CommissionActionType::Reversal, cmxStaff())])->sole()['result'])->toBe('ok');
        expect(carReversalDebits())->toBe(['commission-reversal:'.$commission->reference])->and(cmxBalance($referrer))->toBe(5_000);
        carConsistent($referrer);
    });
});
