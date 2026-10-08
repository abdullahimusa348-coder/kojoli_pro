<?php

namespace App\Actions\Admin\Referrals;

use App\Exceptions\Wallet\InsufficientFunds;
use App\Exceptions\Wallet\WalletException;
use App\Exceptions\Wallet\WalletFrozen;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemPermission;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Reverses or cancels a commission (Phase 12 CP5): the one action it can ever
 * have, irreversible, recorded with its own reference, the staff member, the
 * time and their reason (10 to 500 characters, shown to staff only). Needs an
 * active staff member with referrals.view and referrals.manage (checked here
 * as well as by the routes) and the form's one-time token for this
 * commission, this action and this staff member (CommissionActionToken).
 *
 * In one transaction, retried on deadlock and otherwise never recorded: lock
 * the commission row, then refuse when it already has an action (the same
 * form again is told it was already recorded).
 * - Reverse: lock the commission's wallet (the referrer's Main Wallet), then
 *   debit the full commission amount through WalletService as a separate
 *   "Commission reversal" (key commission-reversal:{COM}, the staff member as
 *   actor, no metadata). The commission and its original credit never
 *   change. A frozen wallet, or a balance below the commission amount (there
 *   is no partial reversal), is refused with nothing written.
 * - Cancel: no money moves, also on a frozen wallet.
 * Then the action row is written. Lock order: the commission row, then the
 * wallet (the debit then share-locks the referrer's users row through its
 * foreign key). No flow takes them the other way round: purchases and
 * commission payments lock wallets, but never an existing commission row.
 */
class ActOnCommission
{
    public const DESCRIPTION = 'Commission reversal';

    public const INVALID_FORM = 'This form has expired or is not valid for this action. Reload the page and try again.';

    public const FROZEN = 'The referrer\'s wallet is frozen, so this commission cannot be reversed. Unfreeze the wallet first, or cancel the commission instead (no money moves). Nothing was changed.';

    public const TOO_LOW = 'The referrer\'s wallet holds less than this commission, and there is no partial reversal. You can cancel the commission instead (no money moves). Nothing was changed.';

    private const ATTEMPTS = 3;

    public function __construct(private ReferralRules $rules, private WalletService $wallets) {}

    public function handle(Commission $commission, CommissionActionType $type, string $reason, mixed $token, SystemUser $actor): CommissionAction
    {
        $this->rules->authorize($actor, SystemPermission::ReferralsManage);
        $length = mb_strlen($reason);
        if ($length < 10 || $length > 500) {
            throw new InvalidArgumentException('A commission action needs a reason of 10 to 500 characters.');
        }
        $key = CommissionActionToken::open($token, $commission, $type, $actor) ?? throw $this->refuse($type, self::INVALID_FORM);

        return DB::transaction(function () use ($commission, $type, $reason, $key, $actor) {
            $locked = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            $existing = CommissionAction::where('commission_id', $locked->id)->first();
            if ($existing !== null) {
                throw $this->refuse($type, $existing->idempotency_key === $key && $existing->type === $type
                    ? 'This '.strtolower($type->label()).' was already recorded with this form. Nothing more was changed.'
                    : 'This commission was already '.strtolower($existing->type->status()->label()).'. A commission can have only one action, ever.');
            }

            $debit = $type === CommissionActionType::Reversal ? $this->debit($locked, $actor) : null;

            return tap((new CommissionAction)->forceFill([
                'reference' => WalletService::reference('CMA'),
                'commission_id' => $locked->id,
                'wallet_id' => $locked->wallet_id,
                'type' => $type,
                'reversal_transaction_id' => $debit?->id,
                'reason' => $reason,
                'idempotency_key' => $key,
                'acted_by' => $actor->id,
            ]))->save();
        }, self::ATTEMPTS);
    }

    /** The reversal's own debit of the full commission amount, once the wallet is locked and checked. */
    private function debit(Commission $locked, SystemUser $actor): Transaction
    {
        $wallet = Wallet::whereKey($locked->wallet_id)->lockForUpdate()->firstOrFail();
        if ($wallet->status === WalletStatus::Frozen) {
            throw $this->refuse(CommissionActionType::Reversal, self::FROZEN);
        }
        if ($wallet->balance_kobo < $locked->amount_kobo) {
            throw $this->refuse(CommissionActionType::Reversal, self::TOO_LOW);
        }

        try {
            $result = $this->wallets->debit($wallet, $locked->amount_kobo, LedgerEntryType::CommissionReversal, TransactionType::Commission,
                self::DESCRIPTION, 'commission-reversal:'.$locked->reference, $actor);
        } catch (WalletFrozen) {
            throw $this->refuse(CommissionActionType::Reversal, self::FROZEN);
        } catch (InsufficientFunds) {
            throw $this->refuse(CommissionActionType::Reversal, self::TOO_LOW);
        } catch (WalletException $e) {
            throw $this->refuse(CommissionActionType::Reversal, 'The wallet refused the reversal: '.$e->getMessage().' Nothing was changed.');
        }
        if ($result->replayed) {
            throw new LogicException('This commission already has a reversal debit without its action.'); // never expected: commissions:verify reports it
        }

        return $result->transaction;
    }

    /** A refusal shown on the form of $type (its own error bag), with nothing written. */
    private function refuse(CommissionActionType $type, string $message): ValidationException
    {
        return ValidationException::withMessages(['action' => $message])->errorBag($type->value);
    }
}
