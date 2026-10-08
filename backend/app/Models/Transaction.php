<?php

namespace App\Models;

use App\Exceptions\Wallet\InvalidTransactionState;
use App\Support\Wallet\Direction;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A customer transaction: one financial operation as the customer sees it,
 * owning its ledger entries. Only the status (along allowed transitions),
 * completed_at and updated_at may change after creation; the reference,
 * amount, wallet, direction, type and idempotency key are immutable. A
 * referral commission transaction (its credit and its reversal debit) is
 * never reversed: a commission changes only through its own action. Never
 * deleted.
 */
class Transaction extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const MUTABLE = ['status', 'completed_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $transaction) {
            $locked = array_diff(array_keys($transaction->getDirty()), self::MUTABLE);
            if ($locked !== []) {
                throw new LogicException('Transaction fields are immutable: '.implode(', ', $locked).'.');
            }
            if ($transaction->isDirty('status')) {
                $from = TransactionStatus::from($transaction->getRawOriginal('status'));
                if (! $from->canTransitionTo($transaction->status)) {
                    throw new InvalidTransactionState("A {$from->value} transaction cannot become {$transaction->status->value}.");
                }
                if ($transaction->type === TransactionType::Commission && $transaction->status === TransactionStatus::Reversed) {
                    throw new InvalidTransactionState('A commission transaction is never reversed: a commission changes only through its own action.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Transactions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'direction' => Direction::class,
            'status' => TransactionStatus::class,
            'amount_kobo' => 'integer',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return HasMany<WalletLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class)->orderBy('id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'created_by')->withTrashed();
    }

    /** Internal staff note (admin only; never shown to the customer). */
    public function internalReason(): ?string
    {
        return $this->metadata['reason'] ?? null;
    }
}
