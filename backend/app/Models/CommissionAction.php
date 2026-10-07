<?php

namespace App\Models;

use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * The one staff action a commission can ever have (Phase 12): a reversal,
 * which took the money back with its own separate debit on the commission's
 * wallet (the original credit is never changed), or a cancellation, which
 * moves no money. Keeps its own reference, the staff member, the time, the
 * internal reason (never shown to the customer) and the one-time form token.
 * Never changed or deleted.
 */
class CommissionAction extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $action) => $action->enforceInvariants());
        static::updating(fn () => throw new LogicException('Commission actions never change.'));
        static::deleting(fn () => throw new LogicException('Commission actions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commission_id' => 'integer',
            'wallet_id' => 'integer',
            'type' => CommissionActionType::class,
            'reversal_transaction_id' => 'integer',
            'acted_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Commission, $this> */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'acted_by')->withTrashed();
    }

    private function enforceInvariants(): void
    {
        if (! is_string($this->reference) || preg_match('/\ACMA-[0-9A-Z]{26}\z/', $this->reference) !== 1) {
            throw new LogicException('A commission action reference is CMA- followed by a ULID.');
        }
        $commission = Commission::find($this->commission_id);
        if ($commission === null) {
            throw new LogicException('A commission action belongs to a commission.');
        }
        if (self::where('commission_id', $commission->id)->exists()) {
            throw new LogicException('A commission can have only one action, ever.');
        }
        if ($this->wallet_id !== $commission->wallet_id) {
            throw new LogicException('A commission action is on the commission\'s own wallet.');
        }
        $length = mb_strlen((string) $this->reason);
        if ($length < 10 || $length > 500) {
            throw new LogicException('A commission action needs a reason of 10 to 500 characters.');
        }
        if (! is_string($this->idempotency_key) || strlen($this->idempotency_key) !== 36 || ! Str::isUuid($this->idempotency_key)) {
            throw new LogicException('A commission action keeps its one-time form token (a UUID).');
        }
        if ($this->acted_by === null) {
            throw new LogicException('A commission action records the staff member who took it.');
        }

        if ($this->type === CommissionActionType::Reversal) {
            $this->assertReversalDebit($commission);
        } elseif ($this->type === CommissionActionType::Cancellation) {
            if ($this->reversal_transaction_id !== null) {
                throw new LogicException('A cancellation moves no money, so it has no wallet transaction.');
            }
        } else {
            throw new LogicException('A commission action is a reversal or a cancellation.');
        }
    }

    /** A reversal's debit: one successful commission reversal debit of the commission amount on its wallet, separate from the credit. */
    private function assertReversalDebit(Commission $commission): void
    {
        $debit = $this->reversal_transaction_id === null ? null : Transaction::find($this->reversal_transaction_id);
        $entries = $debit?->entries()->pluck('entry_type')->map(fn (LedgerEntryType $type) => $type->value)->all();
        if ($debit === null || $debit->id === $commission->credit_transaction_id || $debit->wallet_id !== $commission->wallet_id
            || $debit->user_id !== $commission->referrer_id || $debit->type !== TransactionType::Commission || $debit->direction !== Direction::Debit
            || $debit->status !== TransactionStatus::Successful || $debit->amount_kobo !== $commission->amount_kobo
            || $entries !== [LedgerEntryType::CommissionReversal->value]) {
            throw new LogicException('A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.');
        }
    }
}
