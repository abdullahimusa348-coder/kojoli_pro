<?php

namespace App\Services\Referrals;

use App\Exceptions\Referrals\CommissionNotCredited;
use App\Exceptions\Wallet\BalanceLimitExceeded;
use App\Exceptions\Wallet\WalletException;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Referrals\QualifyingServices;
use App\Support\Referrals\ReferralEligibility;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Pays the Level 1 referral commission of a purchase at the moment it becomes
 * successful (Phase 12 CP4). Used only by PurchaseService, in the transaction
 * that marks the purchase successful and under its row lock, so every way a
 * purchase can succeed (its execution, a scheduled re-check, a staff
 * re-check) runs it once, and it never runs for a purchase that is pending,
 * in review, failed or already successful. Two steps:
 *
 * prepare(), before the purchase is written:
 * 1. Two facts that never change are checked first, without locks: the
 *    purchase is of a qualifying service, and its buyer has a referral link.
 *    Otherwise nothing happens.
 * 2. Everything else runs in a savepoint, taking its locks in the approved
 *    order: the referrer's Main Wallet (exclusive; found by a plain read, so
 *    only an existing row is ever locked), the buyer's and then the
 *    referrer's users row (shared), then the service's commission setting
 *    (shared). A freeze, a type or status change or a rate change waits for
 *    this success to commit, and this success waits for one in progress.
 *    They are taken before the purchase is written because MariaDB writes
 *    the purchase row through its (id, plan) unique index and so also locks
 *    the gap after it, where new purchases go: after that write, this
 *    transaction must not wait for any lock, or the referrer's own new
 *    purchase (which holds their wallet) would deadlock with it.
 * 3. Exclusions end it with no record: the purchase already has a commission
 *    or a failed attempt (a repeated call does nothing); the buyer is an API
 *    User now; the referrer is not an Active Subscriber, Vendor or Affiliate
 *    now; their wallet is frozen; the service has no setting, or a rate or
 *    cap of 0; or the commission rounds down to less than 1 kobo.
 *
 * settle(), once the purchase is marked successful:
 * 4. The commission (the rate of the purchase amount, capped and rounded
 *    down) is credited to the referrer's Main Wallet through WalletService
 *    ("Referral commission", key commission:{purchase}, no actor, no
 *    metadata) and recorded with the rate and cap it used, in a savepoint.
 * 5. Any other error, in either step, rolls its savepoint back (nothing
 *    credited or recorded) and becomes a Failed Commission Attempt (purchase,
 *    referrer, reason code and time only), written in its own savepoint; the
 *    purchase stays successful. A database abort (deadlock, lock wait
 *    timeout, lost connection, a transaction the server rolled back) is never
 *    recorded: it is passed on, so the whole success is retried, or settled
 *    later by the purchase re-checks. Nothing is retried here; staff may
 *    compensate with a wallet adjustment. Logs name the purchase reference
 *    and the reason code only.
 */
class CommissionService
{
    use DetectsConcurrencyErrors, DetectsLostConnections;

    public const DESCRIPTION = 'Referral commission';

    public function __construct(private WalletService $wallets) {}

    /**
     * Step 1, for a pending or review purchase about to be marked successful, under its row lock and before it is
     * written: the checks and the locks. Null when no commission is due.
     */
    public function prepare(Purchase $locked): ?PreparedCommission
    {
        if (! in_array($locked->status, [PurchaseStatus::Pending, PurchaseStatus::Review], true)) {
            return null; // only a purchase that is succeeding now: a later call never evaluates a purchase again
        }
        if (! QualifyingServices::includes(Service::whereKey($locked->service_id)->value('slug'))) {
            return null;
        }
        $referrerId = Referral::where('referred_user_id', $locked->user_id)->value('referrer_id');
        if ($referrerId === null) {
            return null;
        }

        $level = DB::transactionLevel();
        try {
            return DB::transaction(fn () => $this->decide($locked, (int) $referrerId));
        } catch (Throwable $e) {
            if ($this->aborted($e, $level)) {
                throw $e;
            }
            $this->holdReferrer((int) $referrerId, $level);

            return PreparedCommission::failed((int) $referrerId, $this->reasonFor($e));
        }
    }

    /**
     * Step 2, in the same transaction once $locked has just been marked successful: the credit and the commission,
     * or the failed attempt. Waits for no lock: prepare() already holds every row this needs.
     */
    public function settle(Purchase $locked, ?PreparedCommission $prepared): ?Commission
    {
        if ($prepared === null || $locked->status !== PurchaseStatus::Successful || ! $locked->wasChanged('status')) {
            return null;
        }

        $level = DB::transactionLevel();
        if ($prepared->failure !== null) {
            $this->recordFailure($locked, $prepared->referrerId, $prepared->failure, $level);

            return null;
        }
        try {
            return DB::transaction(fn () => $this->pay($locked, $prepared));
        } catch (Throwable $e) {
            if ($this->aborted($e, $level)) {
                throw $e;
            }
            $this->recordFailure($locked, $prepared->referrerId, $this->reasonFor($e), $level);

            return null;
        }
    }

    /** In prepare()'s savepoint: the locks and the exclusions. Throws on any failure. */
    private function decide(Purchase $locked, int $referrerId): ?PreparedCommission
    {
        if ($this->settled($locked)) {
            return null;
        }

        $walletId = Wallet::where('user_id', $referrerId)->where('type', WalletType::Main->value)->value('id');
        $wallet = $walletId === null ? null : Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
        $buyer = User::whereKey($locked->user_id)->sharedLock()->firstOrFail();
        $referrer = User::whereKey($referrerId)->sharedLock()->firstOrFail();
        $settingId = CommissionSetting::where('service_id', $locked->service_id)->value('id');
        $setting = $settingId === null ? null : CommissionSetting::whereKey($settingId)->sharedLock()->firstOrFail();

        if (! ReferralEligibility::buyerGeneratesCommission($buyer) || ! ReferralEligibility::isActiveReferrer($referrer)
            || $wallet?->status === WalletStatus::Frozen || $setting === null || $setting->rate_bps < 1 || $setting->cap_kobo < 1) {
            return null;
        }
        $amount = Commission::amountFor($locked->amount_kobo, $setting->rate_bps, $setting->cap_kobo);
        if ($amount < 1) {
            return null;
        }
        if ($wallet === null) {
            throw new CommissionNotCredited(CommissionFailureReason::WalletUnavailable);
        }

        return PreparedCommission::payable($referrerId, $wallet, $setting->rate_bps, $setting->cap_kobo, $amount);
    }

    /** In settle()'s savepoint: the credit, then the commission. Throws on any failure. */
    private function pay(Purchase $locked, PreparedCommission $prepared): ?Commission
    {
        if ($this->settled($locked)) {
            return null; // a repeated call does nothing
        }

        $credit = $this->wallets->credit($prepared->wallet, $prepared->amountKobo, LedgerEntryType::CommissionCredit, TransactionType::Commission,
            self::DESCRIPTION, 'commission:'.$locked->reference);
        if ($credit->replayed) {
            throw new CommissionNotCredited(CommissionFailureReason::WalletRefused); // an earlier credit: never a commission on it
        }

        return tap((new Commission)->forceFill([
            'reference' => WalletService::reference('COM'),
            'purchase_id' => $locked->id,
            'referrer_id' => $prepared->referrerId,
            'wallet_id' => $prepared->wallet->id,
            'credit_transaction_id' => $credit->transaction->id,
            'base_amount_kobo' => $locked->amount_kobo,
            'rate_bps' => $prepared->rateBps,
            'cap_kobo' => $prepared->capKobo,
            'amount_kobo' => $prepared->amountKobo,
            'credited_at' => $credit->transaction->completed_at,
        ]))->save();
    }

    /** Whether the purchase already has its commission or its failed attempt. */
    private function settled(Purchase $locked): bool
    {
        return Commission::where('purchase_id', $locked->id)->exists() || FailedCommissionAttempt::where('purchase_id', $locked->id)->exists();
    }

    /**
     * After an error in prepare(), the referrer's users row is still share-locked now, before the purchase is
     * written, so that the failed attempt's foreign key check later waits for nothing (no model is read).
     */
    private function holdReferrer(int $referrerId, int $level): void
    {
        try {
            User::whereKey($referrerId)->sharedLock()->toBase()->value('id');
        } catch (Throwable $e) {
            if ($this->aborted($e, $level)) {
                throw $e;
            }
        }
    }

    /** The failed attempt, in its own savepoint; when even that cannot be written (other than an abort), an error is logged. */
    private function recordFailure(Purchase $locked, int $referrerId, CommissionFailureReason $reason, int $level): void
    {
        try {
            $recorded = DB::transaction(function () use ($locked, $referrerId, $reason) {
                if ($this->settled($locked)) {
                    return false; // a repeated call does nothing
                }
                (new FailedCommissionAttempt)->forceFill(['purchase_id' => $locked->id, 'referrer_id' => $referrerId, 'reason_code' => $reason])->save();

                return true;
            });
        } catch (Throwable $e) {
            if ($this->aborted($e, $level)) {
                throw $e;
            }
            Log::error('Referral commission failure could not be recorded', ['purchase' => $locked->reference, 'reason' => $reason->value]);

            return;
        }
        if ($recorded) {
            Log::warning('Referral commission not credited', ['purchase' => $locked->reference, 'reason' => $reason->value]);
        }
    }

    private function reasonFor(Throwable $e): CommissionFailureReason
    {
        return match (true) {
            $e instanceof CommissionNotCredited => $e->reason,
            $e instanceof BalanceLimitExceeded => CommissionFailureReason::WalletBalanceLimit,
            $e instanceof WalletException => CommissionFailureReason::WalletRefused,
            default => CommissionFailureReason::UnexpectedError,
        };
    }

    /**
     * A database abort, after which the transaction may be gone on the server, so nothing may be written: a deadlock
     * or lock wait timeout (inside a savepoint Laravel throws DeadlockException without rolling it back), a lost
     * connection, the SQL connection-exception and transaction-rollback classes (08, 40), or any error after which
     * the savepoint could not be rolled back (the transaction level is no longer the caller's).
     */
    private function aborted(Throwable $e, int $level): bool
    {
        return $e instanceof DeadlockException
            || $this->causedByConcurrencyError($e)
            || $this->causedByLostConnection($e)
            || ($e instanceof PDOException && in_array(substr((string) $e->getCode(), 0, 2), ['08', '40'], true))
            || DB::transactionLevel() !== $level;
    }
}
