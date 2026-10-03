<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\Network;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One customer purchase of one plan (Phase 10). Holds snapshots of what was
 * sold and charged; those never change after creation. Money moves only
 * through WalletService: debit_transaction_id and refund_transaction_id each
 * point to one wallet transaction and are set at most once. "failed" always
 * means "refunded" (the refund is set in the same save), "successful" always
 * names the attempt that delivered. Status changes follow
 * PurchaseStatus::canTransitionTo(). Never deleted. Written only by the
 * purchase engine.
 */
class Purchase extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const IMMUTABLE = [
        'reference', 'user_id', 'wallet_id', 'plan_id', 'service_id', 'service_name', 'product_name', 'plan_name', 'network',
        'user_type', 'amount_type', 'recipient', 'face_value_kobo', 'discount_kobo', 'fee_kobo', 'amount_kobo', 'currency',
        'idempotency_key', 'request_fingerprint',
    ];

    private const SET_ONCE = ['debit_transaction_id', 'refund_transaction_id', 'successful_attempt_id'];

    protected static function booted(): void
    {
        static::saving(fn (self $purchase) => $purchase->enforceInvariants());
        static::deleting(fn () => throw new LogicException('Purchases are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'user_type' => UserType::class,
            'amount_type' => AmountType::class,
            'status' => PurchaseStatus::class,
            'face_value_kobo' => 'integer',
            'discount_kobo' => 'integer',
            'fee_kobo' => 'integer',
            'amount_kobo' => 'integer',
            'cost_kobo' => 'integer',
            'margin_kobo' => 'integer',
            'check_count' => 'integer',
            'next_check_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Fingerprint of the purchase details a request asks for. A repeated
     * idempotency key must carry the same fingerprint, otherwise it is refused.
     */
    public static function fingerprint(int $planId, string $recipient, ?int $faceValueKobo): string
    {
        return hash('sha256', implode('|', [$planId, $recipient, $faceValueKobo ?? '-']));
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

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'debit_transaction_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    /** @return BelongsTo<PurchaseAttempt, $this> */
    public function successfulAttempt(): BelongsTo
    {
        return $this->belongsTo(PurchaseAttempt::class, 'successful_attempt_id');
    }

    /** @return HasMany<PurchaseAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PurchaseAttempt::class)->orderBy('attempt_number');
    }

    /** @return HasMany<PurchaseStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(PurchaseStatusChange::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    private function enforceInvariants(): void
    {
        if ($this->exists) {
            $locked = array_intersect(array_keys($this->getDirty()), self::IMMUTABLE);
            if ($locked !== []) {
                throw new LogicException('Purchase fields are immutable: '.implode(', ', $locked).'.');
            }
            foreach (self::SET_ONCE as $field) {
                if ($this->isDirty($field) && $this->getRawOriginal($field) !== null) {
                    throw new LogicException("Purchase {$field} is set at most once.");
                }
            }
            if ($this->isDirty('status')) {
                $from = PurchaseStatus::from($this->getRawOriginal('status'));
                if (! $from->canTransitionTo($this->status)) {
                    throw new PurchaseException("A {$from->value} purchase cannot become {$this->status->value}.");
                }
            }
        } elseif ($this->status !== PurchaseStatus::Pending) {
            throw new PurchaseException('A purchase is always created pending.');
        }

        // "failed" always means refunded, a refund exists only on a failed purchase,
        // and "successful" always names the attempt that delivered.
        if (($this->status === PurchaseStatus::Failed) !== ($this->refund_transaction_id !== null)) {
            throw new PurchaseException('A purchase is failed exactly when its debit has been refunded.');
        }
        if (($this->status === PurchaseStatus::Successful) !== ($this->successful_attempt_id !== null)) {
            throw new PurchaseException('A purchase is successful exactly when a delivering attempt is recorded.');
        }
    }
}
