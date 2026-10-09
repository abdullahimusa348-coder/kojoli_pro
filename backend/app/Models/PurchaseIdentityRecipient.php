<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The NIN or BVN of one NIN/BVN purchase (Phase 11). NIN/BVN purchases are
 * digital service purchases, not KYC: this is the number the service was
 * bought for, never a verified customer identity.
 * - encrypted_value: the canonical 11 digits under the encrypted cast; never
 *   serialised, and decrypted only by the purchase engine to build the
 *   provider request (integrity checks only ask isReadable());
 * - masked_value: the only form any page may show;
 * - lookup_hash and keyed_fingerprint: keyed hashes (IdentityHasher).
 * Written once, in the transaction that creates its pending purchase, with
 * every derived value recomputed and checked here; never updated or deleted.
 */
class PurchaseIdentityRecipient extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['encrypted_value', 'lookup_hash', 'keyed_fingerprint'];

    protected static function booted(): void
    {
        static::saving(fn (self $recipient) => $recipient->enforceInvariants());
        static::deleting(fn () => throw new LogicException('Identity recipients are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'encrypted_value' => 'encrypted',
            'consented_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * The number, decrypted in memory, or null when it cannot be read (key
     * missing from APP_PREVIOUS_KEYS, damaged value). Only the purchase engine
     * uses it, to build the provider request: never displayed, logged or stored.
     */
    public function number(RecipientType $type): ?string
    {
        try {
            $number = $this->encrypted_value;
        } catch (DecryptException) {
            return null;
        }

        return $type->isCanonical($number) ? $number : null;
    }

    /** Whether number() can be read, for integrity checks that must never see the number itself. */
    public function isReadable(RecipientType $type): bool
    {
        return $this->number($type) !== null;
    }

    private function enforceInvariants(): void
    {
        if ($this->exists) {
            throw new LogicException('Identity recipients never change after creation.');
        }

        $purchase = Purchase::find($this->purchase_id);
        if ($purchase === null || $purchase->status !== PurchaseStatus::Pending || ! $purchase->recipient_type->isIdentity()) {
            throw new PurchaseException('An identity recipient belongs only to a NIN or BVN purchase that is being created.');
        }
        if (self::where('purchase_id', $purchase->id)->exists()) {
            throw new PurchaseException('This purchase already has its identity recipient.');
        }

        $type = $purchase->recipient_type;
        $number = $this->encrypted_value;
        if (! $type->isCanonical($number)) {
            throw new PurchaseException('The identity number must be stored as exactly 11 digits.');
        }
        if ($this->masked_value !== $type->mask($number) || $this->lookup_hash !== IdentityHasher::lookupHash($type, $number)
            || $this->keyed_fingerprint !== IdentityHasher::keyedFingerprint($purchase->plan_id, $type, $number, $purchase->face_value_kobo)) {
            throw new PurchaseException('The masked value and keyed hashes must match the identity number.');
        }
        if ($this->consented_at === null) {
            throw new PurchaseException('A NIN or BVN purchase needs the customer\'s consent.');
        }
    }
}
