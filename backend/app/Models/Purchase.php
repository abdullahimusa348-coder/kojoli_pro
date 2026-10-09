<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Support\BusinessTime;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\Network;
use App\Support\Enums\UserType;
use App\Support\Phone\NigerianPhone;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use App\Support\Wallet\Direction;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * One customer purchase of one plan (Phase 10). Holds snapshots of what was
 * sold and charged; those never change after creation. Money moves only
 * through WalletService: debit_transaction_id and refund_transaction_id each
 * point to one wallet transaction of this purchase's wallet and are set at
 * most once. Invariants (model guards, plus composite foreign keys where the
 * database can express them):
 * - created pending, with the recipient type of its service (Phase 11),
 *   fixed afterwards: a phone purchase stores a canonical phone number and
 *   its fingerprint; a NIN/BVN purchase stores neither (both NULL) and has
 *   exactly one PurchaseIdentityRecipient, written in the same transaction;
 *   an Exam PIN purchase (Phase 11 CP4) stores neither and has no recipient
 *   at all;
 * - a NIN/BVN or Exam PIN purchase is successful only with its PurchaseResult
 *   from the delivering attempt (Phase 11 CP2, CP4), and is never refunded
 *   once it has one;
 * - "successful" and "failed" both require the recorded debit;
 * - "failed" always means refunded (refund recorded in the same save), and
 *   the refund is a purchase credit of the charged amount to the same wallet;
 * - "successful" always names the delivering attempt (same purchase,
 *   succeeded), with cost and margin written in the same save;
 * - a successful or failed purchase never changes again.
 * Status changes follow PurchaseStatus::canTransitionTo(). Never deleted.
 * Written only by the purchase engine.
 */
class Purchase extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const IMMUTABLE = [
        'reference', 'user_id', 'wallet_id', 'plan_id', 'service_id', 'service_name', 'product_name', 'plan_name', 'network',
        'user_type', 'amount_type', 'recipient_type', 'recipient', 'face_value_kobo', 'discount_kobo', 'fee_kobo', 'amount_kobo', 'currency',
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
            'recipient_type' => RecipientType::class,
            'status' => PurchaseStatus::class,
            'user_id' => 'integer',
            'wallet_id' => 'integer',
            'plan_id' => 'integer',
            'debit_transaction_id' => 'integer',
            'refund_transaction_id' => 'integer',
            'successful_attempt_id' => 'integer',
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
     * Phone purchases only; NIN/BVN purchases use IdentityHasher::keyedFingerprint()
     * and Exam PIN purchases recipientlessFingerprint().
     */
    public static function fingerprint(int $planId, string $recipient, ?int $faceValueKobo): string
    {
        $canonical = NigerianPhone::normalize($recipient)
            ?? throw new InvalidArgumentException('The recipient is not an accepted phone number format.');

        return hash('sha256', implode('|', [$planId, $canonical, $faceValueKobo ?? '-']));
    }

    /**
     * Fingerprint of an Exam PIN request (Phase 11 CP4): the plan only, as
     * there is no recipient and no face value. Never stored (the purchase's
     * request_fingerprint stays NULL): a repeated key is compared by
     * recomputing it from the purchase's own immutable plan.
     */
    public static function recipientlessFingerprint(int $planId): string
    {
        return hash('sha256', implode('|', [$planId, RecipientType::None->value, '-']));
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

    /**
     * The NIN or BVN of a NIN/BVN purchase (none for phone purchases).
     *
     * @return HasOne<PurchaseIdentityRecipient, $this>
     */
    public function identityRecipient(): HasOne
    {
        return $this->hasOne(PurchaseIdentityRecipient::class);
    }

    /**
     * What the provider delivered for a NIN/BVN or Exam PIN purchase (none for phone purchases).
     *
     * @return HasOne<PurchaseResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(PurchaseResult::class);
    }

    /**
     * The recipient as pages may show it (Phase 11 CP3): the canonical phone of
     * a phone purchase, exactly as before; the masked number of a NIN/BVN
     * purchase, never the number itself; a dash for an Exam PIN purchase,
     * which has no recipient (CP4). Lists load the masked values with
     * withMaskedRecipients().
     */
    public function displayRecipient(): ?string
    {
        if ($this->recipient_type === RecipientType::None) {
            return '—';
        }

        return $this->recipient_type?->isIdentity() ? $this->identityRecipient?->masked_value : $this->recipient;
    }

    /**
     * Loads only the masked number of the NIN/BVN purchases in $purchases (no
     * other identity column, and no query at all for a phone-only list).
     *
     * @param  EloquentCollection<int, self>  $purchases
     * @return EloquentCollection<int, self>
     */
    public static function withMaskedRecipients(EloquentCollection $purchases): EloquentCollection
    {
        if ($purchases->contains(fn (self $purchase) => $purchase->recipient_type?->isIdentity())) {
            $purchases->load('identityRecipient:id,purchase_id,masked_value');
        }

        return $purchases;
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

    /**
     * Purchases that reached their final outcome (successful or failed)
     * during the current business day (BusinessTime). One definition for
     * Today's Sales and the matching admin Purchases filter.
     */
    public function scopeCompletedToday(Builder $query): void
    {
        [$start, $end] = BusinessTime::today();
        $query->where('completed_at', '>=', $start)->where('completed_at', '<', $end);
    }

    /**
     * When reconciliation will next check this purchase, as reconcile() decides
     * what is due: its scheduled check, or, for a pending purchase never
     * scheduled, the minimum age after creation. Null once it is final.
     */
    public function checkDueAt(): ?Carbon
    {
        if ($this->isFinal()) {
            return null;
        }

        return $this->next_check_at ?? $this->created_at?->copy()->addMinutes(config('purchases.reconcile_min_age_minutes'));
    }

    /**
     * Pending or review purchases whose check has been due for longer than
     * purchases.overdue_after_minutes (the same due rule as checkDueAt()).
     */
    public function scopeCheckOverdue(Builder $query): void
    {
        $limit = now()->subMinutes(config('purchases.overdue_after_minutes'));
        $query->whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Review->value])
            ->where(fn (Builder $due) => $due->where('next_check_at', '<', $limit)
                ->orWhere(fn (Builder $never) => $never->whereNull('next_check_at')
                    ->where('created_at', '<', $limit->copy()->subMinutes(config('purchases.reconcile_min_age_minutes')))));
    }

    private function enforceInvariants(): void
    {
        if ($this->exists) {
            $from = PurchaseStatus::from($this->getRawOriginal('status'));
            if ($from->isFinal() && $this->isDirty()) {
                throw new LogicException('A successful or failed purchase can no longer change.');
            }
            $locked = array_intersect(array_keys($this->getDirty()), self::IMMUTABLE);
            if ($locked !== []) {
                throw new LogicException('Purchase fields are immutable: '.implode(', ', $locked).'.');
            }
            foreach (self::SET_ONCE as $field) {
                if ($this->isDirty($field) && $this->getRawOriginal($field) !== null) {
                    throw new LogicException("Purchase {$field} is set at most once.");
                }
            }
            if ($this->isDirty('status') && ! $from->canTransitionTo($this->status)) {
                throw new PurchaseException("A {$from->value} purchase cannot become {$this->status->value}.");
            }
            if ($this->isDirty(['cost_kobo', 'margin_kobo']) && ! ($this->isDirty('status') && $this->status === PurchaseStatus::Successful)) {
                throw new PurchaseException('Cost and margin are written only together with success.');
            }
        } else {
            if ($this->status !== PurchaseStatus::Pending) {
                throw new PurchaseException('A purchase is always created pending.');
            }
            $this->recipient_type ??= RecipientType::Phone; // the column default: a purchase created without a type is a phone purchase
            $slug = Service::whereKey($this->service_id)->value('slug');
            if ($slug === null || RecipientType::forServiceSlug($slug) !== $this->recipient_type) {
                throw new PurchaseException('The recipient type must be the one the purchase\'s service uses.');
            }
            if ($this->recipient_type === RecipientType::Phone) {
                if (! NigerianPhone::isCanonical($this->recipient)) {
                    throw new PurchaseException('The recipient must be stored in canonical phone format.');
                }
                if ($this->request_fingerprint !== self::fingerprint($this->plan_id, $this->recipient, $this->face_value_kobo)) {
                    throw new PurchaseException('The request fingerprint does not match the purchase details.');
                }
            } elseif ($this->recipient !== null || $this->request_fingerprint !== null) {
                throw new PurchaseException($this->recipient_type->isIdentity()
                    ? 'A NIN or BVN purchase stores no phone recipient or phone fingerprint.'
                    : 'An Exam PIN purchase stores no recipient or fingerprint.');
            }
        }

        $final = in_array($this->status, [PurchaseStatus::Successful, PurchaseStatus::Failed], true);
        if ($final && $this->debit_transaction_id === null) {
            throw new PurchaseException('A purchase can only succeed or fail after its debit is recorded.');
        }
        if (($this->status === PurchaseStatus::Failed) !== ($this->refund_transaction_id !== null)) {
            throw new PurchaseException('A purchase is failed exactly when its debit has been refunded.');
        }
        if (($this->status === PurchaseStatus::Successful) !== ($this->successful_attempt_id !== null)) {
            throw new PurchaseException('A purchase is successful exactly when a delivering attempt is recorded.');
        }
        if ($this->status !== PurchaseStatus::Successful && ($this->cost_kobo !== null || $this->margin_kobo !== null)) {
            throw new PurchaseException('Cost and margin exist only on a successful purchase.');
        }

        if ($this->isDirty('debit_transaction_id') && $this->debit_transaction_id !== null) {
            $this->assertWalletTransaction($this->debit_transaction_id, Direction::Debit, 'debit');
        }
        if ($this->isDirty('refund_transaction_id') && $this->refund_transaction_id !== null) {
            if ($this->refund_transaction_id === $this->debit_transaction_id) {
                throw new PurchaseException('The refund must be a separate wallet transaction.');
            }
            $this->assertWalletTransaction($this->refund_transaction_id, Direction::Credit, 'refund');
            if ($this->recipient_type->requiresResult() && PurchaseResult::where('purchase_id', $this->id)->exists()) {
                throw new PurchaseException('A purchase whose result was delivered is never refunded.');
            }
        }
        if ($this->isDirty('successful_attempt_id') && $this->successful_attempt_id !== null) {
            $this->assertSuccess();
        }
    }

    /** The debit or refund must be a successful purchase transaction of the charged amount on this wallet. */
    private function assertWalletTransaction(int $id, Direction $direction, string $what): void
    {
        $tx = Transaction::find($id);
        if ($tx === null || $tx->wallet_id !== $this->wallet_id || $tx->user_id !== $this->user_id || $tx->type !== TransactionType::Purchase
            || $tx->direction !== $direction || $tx->amount_kobo !== $this->amount_kobo || $tx->status !== TransactionStatus::Successful) {
            throw new PurchaseException("The {$what} must be a successful purchase {$direction->value} of the charged amount on this purchase's wallet.");
        }
    }

    /** The delivering attempt belongs to this purchase and succeeded; cost and margin match it. */
    private function assertSuccess(): void
    {
        $attempt = PurchaseAttempt::find($this->successful_attempt_id);
        if ($attempt === null || $attempt->purchase_id !== $this->id || $attempt->status !== PurchaseAttemptStatus::Succeeded) {
            throw new PurchaseException('The delivering attempt must be a succeeded attempt of this purchase.');
        }

        $cost = $attempt->costKobo($this->face_value_kobo);
        $margin = $cost === null ? null : $this->amount_kobo - $cost;
        if ($this->cost_kobo !== $cost || $this->margin_kobo !== $margin) {
            throw new PurchaseException('Cost and margin must be the delivering route\'s cost snapshot and the charged amount minus that cost.');
        }
        if ($this->recipient_type->requiresResult()
            && ! PurchaseResult::where('purchase_id', $this->id)->where('purchase_attempt_id', $attempt->id)->exists()) {
            throw new PurchaseException($this->recipient_type->isIdentity()
                ? 'A NIN or BVN purchase is successful only with its stored result.'
                : 'An Exam PIN purchase is successful only with its stored result.');
        }
    }
}
