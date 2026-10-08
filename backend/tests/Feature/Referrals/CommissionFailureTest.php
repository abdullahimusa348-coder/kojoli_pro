<?php

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 CP4: a payable commission that cannot be credited, on SQLite.
 * The commission step runs in its own savepoint: any error there rolls back
 * whatever it did (no credit without a commission) and is recorded as one
 * Failed Commission Attempt (purchase, referrer, reason code, time), while
 * the purchase stays successful. Exclusions never make an attempt, nothing
 * is retried, and logs name only the purchase reference and reason code.
 * A database abort is passed on and never recorded; on SQLite every
 * transaction here is nested in the test's own, so how the whole success is
 * rolled back, retried and later settled is tested on MariaDB
 * (tests/Concurrency/CommissionEngineRaceTest.php).
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
    cftArmed(false);
});

/** Whether the test's failure is switched on: its model events check this, so that the checks after the re-check run normally. */
function cftArmed(?bool $armed = null): bool
{
    static $state = false;
    if ($armed !== null) {
        $state = $armed;
    }

    return $state;
}

/** The commission log lines among $logs. */
function cftCommissionLogs(ArrayObject $logs): array
{
    return array_values(array_filter($logs->getArrayCopy(), fn (string $line) => str_contains($line, 'ommission')));
}

/** A customer of $referrer's (a new one with a Main Wallet when null) with an unclear ₦500 Data purchase at 2.5%: [referrer, purchase]. */
function cftUnclear(?User $referrer = null): array
{
    $referrer ??= cmxReferrer();
    cmxSetting('data', 250, 100_000);

    return [$referrer, cmxBuy(cmxReferred($referrer), cmxPlan('data', 50_000), 'timeout')];
}

/** The purchase is successful, with no commission and one failed attempt naming the referrer and $reason. */
function cftFailedWith(Purchase $purchase, User $referrer, CommissionFailureReason $reason): void
{
    expect($purchase->fresh()->status)->toBe(PurchaseStatus::Successful)
        ->and(FailedCommissionAttempt::sole()->only(['purchase_id', 'referrer_id', 'reason_code']))
        ->toBe(['purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'reason_code' => $reason])
        ->and(Commission::count())->toBe(0);
}

it('records wallet_unavailable when the referrer has no Main Wallet, keeps the purchase successful, and never retries it', function () {
    $logs = cmxRecordLogs();
    $referrer = User::factory()->create(); // never opened their Referral page, so they have no Main Wallet
    [, $purchase] = cftUnclear($referrer);

    expect(cmxRecheck($purchase)->status)->toBe(PurchaseStatus::Successful);
    cftFailedWith($purchase, $referrer, CommissionFailureReason::WalletUnavailable);
    expect(array_keys(FailedCommissionAttempt::sole()->getAttributes()))->toBe(['id', 'purchase_id', 'referrer_id', 'reason_code', 'created_at'])
        ->and(Wallet::where('user_id', $referrer->id)->exists())->toBeFalse() // no wallet or balance is invented
        ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0)
        ->and(cftCommissionLogs($logs))->toBe(['Referral commission not credited {"purchase":"'.$purchase->reference.'","reason":"wallet_unavailable"}']);

    // Never retried: a wallet made later and further re-checks change nothing (staff may compensate with an ordinary adjustment).
    app(WalletService::class)->walletFor($referrer);
    cmxRecheck($purchase->fresh());
    puxService()->reconcile();
    expect(FailedCommissionAttempt::count())->toBe(1)->and(Commission::count())->toBe(0)->and(cmxBalance($referrer))->toBe(0);
    cmxClean();
});

it('records nothing for a referrer without a Main Wallet when nothing would be payable anyway', function (Closure $exclude) {
    $staff = cmxStaff();
    $referrer = User::factory()->create();
    [, $purchase] = cftUnclear($referrer);
    $exclude($referrer, $purchase, $staff);

    expect(cmxRecheck($purchase)->status)->toBe(PurchaseStatus::Successful)
        ->and(FailedCommissionAttempt::count())->toBe(0)
        ->and(Commission::count())->toBe(0)
        ->and(Wallet::where('user_id', $referrer->id)->exists())->toBeFalse();
    cmxClean();
})->with([
    'the referrer is an API User' => [fn (User $referrer, Purchase $purchase, SystemUser $staff) => app(ChangeUserType::class)->handle($referrer, UserType::ApiUser, $staff)],
    'the referrer is disabled' => [fn (User $referrer, Purchase $purchase, SystemUser $staff) => app(ChangeCustomerStatus::class)->handle($referrer, UserStatus::Disabled, $staff)],
    'the buyer is an API User' => [fn (User $referrer, Purchase $purchase, SystemUser $staff) => app(ChangeUserType::class)->handle($purchase->user, UserType::ApiUser, $staff)],
    'the rate is 0' => [fn () => cmxSetting('data', 0, 100_000)],
    'the cap is 0' => [fn () => cmxSetting('data', 250, 0)],
]);

it('records wallet_balance_limit when the credit would take the referrer past the balance limit, crediting nothing', function () {
    $referrer = cmxReferrer();
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->walletFor($referrer), WalletService::MAX_BALANCE_KOBO - 1_000, LedgerEntryType::AdjustmentCredit,
        TransactionType::Adjustment, 'Test funding');
    [, $purchase] = cftUnclear($referrer);

    cmxRecheck($purchase); // 1,250 kobo would pass the limit by 250

    cftFailedWith($purchase, $referrer, CommissionFailureReason::WalletBalanceLimit);
    expect(cmxBalance($referrer))->toBe(WalletService::MAX_BALANCE_KOBO - 1_000);
    cmxClean();
});

it('records wallet_refused when the wallet refuses the credit or answers with an earlier one, and never makes a commission of it', function (string $earlier) {
    [$referrer, $purchase] = cftUnclear();
    // An earlier transaction under this purchase's commission key: impossible through the app, planted here to reach the wallet's answer.
    [$amount, $entry, $type] = $earlier === 'conflicting'
        ? [999, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment]
        : [1_250, LedgerEntryType::CommissionCredit, TransactionType::Commission];
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->walletFor($referrer), $amount, $entry, $type, 'Referral commission', 'commission:'.$purchase->reference);

    cmxRecheck($purchase);

    cftFailedWith($purchase, $referrer, CommissionFailureReason::WalletRefused);
    expect(Transaction::where('user_id', $referrer->id)->count())->toBe(1) // nothing posted besides the planted one
        ->and(cmxBalance($referrer))->toBe($amount)
        ->and(Artisan::call('wallet:verify'))->toBe(0)
        ->and(Artisan::call('purchases:verify'))->toBe(0);
})->with(['a conflicting transaction' => 'conflicting', 'the same credit, already posted' => 'replayed']);

it('records unexpected_error when something unexpected fails, the savepoint leaving no credit behind and the purchase successful', function (Closure $break) {
    [$referrer, $purchase] = cftUnclear();
    $break(new RuntimeException('Unexpected test failure'));
    cftArmed(true);

    $settled = cmxRecheck($purchase);
    cftArmed(false);

    expect($settled->status)->toBe(PurchaseStatus::Successful);
    cftFailedWith($purchase, $referrer, CommissionFailureReason::UnexpectedError);
    expect(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0)
        ->and(WalletLedgerEntry::where('entry_type', LedgerEntryType::CommissionCredit->value)->count())->toBe(0)
        ->and(cmxBalance($referrer))->toBe(0);
    cmxClean();
})->with([
    'reading the buyer and referrer' => [fn (Throwable $e) => User::retrieved(fn () => cftArmed() ? throw $e : null)],
    'reading the commission setting' => [fn (Throwable $e) => CommissionSetting::retrieved(fn () => cftArmed() ? throw $e : null)],
    'posting the wallet credit' => [fn (Throwable $e) => WalletLedgerEntry::creating(fn () => cftArmed() ? throw $e : null)],
    'saving the commission, after its credit' => [fn (Throwable $e) => Commission::creating(fn () => cftArmed() ? throw $e : null)],
]);

it('passes a database abort on and records nothing: a deadlock, a lock wait timeout, a lost connection, or a connection or rollback error', function (string $sqlstate, string $message) {
    $logs = cmxRecordLogs();
    [, $purchase] = cftUnclear();
    $abort = cmxDatabaseError($sqlstate, $message);
    CommissionSetting::retrieved(fn () => cftArmed() ? throw $abort : null);
    cftArmed(true);

    expect(fn () => cmxRecheck($purchase))->toThrow(PDOException::class);
    cftArmed(false);

    expect(FailedCommissionAttempt::count())->toBe(0)
        ->and(Commission::count())->toBe(0)
        ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0)
        ->and(cftCommissionLogs($logs))->toBe([]);
})->with([
    'a deadlock' => ['40001', 'Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'],
    'a lock wait timeout' => ['HY000', 'General error: 1205 Lock wait timeout exceeded; try restarting transaction'],
    'a lost connection' => ['HY000', 'General error: 2006 MySQL server has gone away'],
    'a connection exception (SQLSTATE class 08)' => ['08004', 'Connection rejected: 1040 Too many connections'],
    'a transaction rollback (SQLSTATE class 40)' => ['40000', 'Transaction rollback: 1180 Got error 1 during COMMIT'],
]);

it('logs an error, and keeps the purchase successful, when even the failed attempt cannot be written', function () {
    $logs = cmxRecordLogs();
    $referrer = User::factory()->create();
    [, $purchase] = cftUnclear($referrer);
    FailedCommissionAttempt::creating(fn () => cftArmed() ? throw new RuntimeException('Unexpected test failure') : null);
    cftArmed(true);

    expect(cmxRecheck($purchase)->status)->toBe(PurchaseStatus::Successful);
    cftArmed(false);

    expect(FailedCommissionAttempt::count())->toBe(0)
        ->and(Commission::count())->toBe(0)
        ->and(cftCommissionLogs($logs))->toBe(['Referral commission failure could not be recorded {"purchase":"'.$purchase->reference.'","reason":"wallet_unavailable"}']);
    cmxClean();
});

it('passes on a database abort while writing the failed attempt, recording nothing', function () {
    $logs = cmxRecordLogs();
    $referrer = User::factory()->create();
    [, $purchase] = cftUnclear($referrer);
    $abort = cmxDatabaseError('40001', 'Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    FailedCommissionAttempt::creating(fn () => cftArmed() ? throw $abort : null);
    cftArmed(true);

    expect(fn () => cmxRecheck($purchase))->toThrow(PDOException::class);
    cftArmed(false);

    expect(FailedCommissionAttempt::count())->toBe(0)->and(Commission::count())->toBe(0)->and(cftCommissionLogs($logs))->toBe([]);
});
