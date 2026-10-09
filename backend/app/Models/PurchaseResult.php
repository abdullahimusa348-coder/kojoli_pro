<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * The result a provider delivered for one NIN/BVN purchase (Phase 11 CP2) or
 * Exam PIN purchase (CP4): the text fields of ProviderResultFields, encrypted
 * with the app key; for NIN/BVN with the purchased number already replaced by
 * its mask. NIN/BVN purchases are digital service purchases, not KYC: this is
 * what the customer bought, never a verified identity of anyone. For Exam PIN
 * it holds what was bought (such as the PIN and its serial), so an Exam PIN
 * result always has at least one value that is not blank.
 * - Written once, by the purchase engine, in the transaction that marks its
 *   purchase successful: for a purchase that requires a result and is still
 *   being settled, from that purchase's own succeeded delivering attempt.
 *   Never updated or deleted.
 * - encrypted_fields is never serialised; staff only ever see that a result
 *   exists and its field count. fields() is for the buyer's own result page.
 */
class PurchaseResult extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['encrypted_fields'];

    protected static function booted(): void
    {
        static::saving(fn (self $result) => $result->enforceInvariants());
        static::deleting(fn () => throw new LogicException('Purchase results are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'purchase_attempt_id' => 'integer',
            'field_count' => 'integer',
            'encrypted_fields' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<PurchaseAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PurchaseAttempt::class, 'purchase_attempt_id');
    }

    /**
     * The delivered fields, decrypted in memory, or null when they cannot be
     * read (key missing from APP_PREVIOUS_KEYS, damaged or invalid value, or a
     * count that does not match). For the buyer's own result page only:
     * never logged, stored elsewhere or shown to staff.
     *
     * @return list<array{key: string, label: string, value: string}>|null
     */
    public function fields(): ?array
    {
        try {
            $stored = $this->encrypted_fields;
            if (! is_array($stored)) {
                return null;
            }
            $fields = (new ProviderResultFields($stored))->all();
        } catch (DecryptException|InvalidArgumentException) {
            return null;
        }

        return count($fields) === $this->field_count ? $fields : null;
    }

    /** Whether fields() can be read, for integrity checks that must never see the values. */
    public function canBeRead(): bool
    {
        return $this->fields() !== null;
    }

    /**
     * Whether at least one value holds something other than spaces or
     * invisible formatting characters: a result whose values are all blank
     * delivered nothing (Exam PIN, CP4). Answers yes or no only.
     *
     * @param  list<array{key: string, label: string, value: string}>  $fields
     */
    public static function hasValue(#[\SensitiveParameter] array $fields): bool
    {
        foreach ($fields as $field) {
            if (preg_match('/\A[\p{Z}\p{Cf}]*\z/u', $field['value']) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function enforceInvariants(): void
    {
        if ($this->exists) {
            throw new LogicException('Purchase results never change after creation.');
        }

        $purchase = Purchase::find($this->purchase_id);
        if ($purchase === null || ! $purchase->recipient_type->requiresResult()
            || ! in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Review], true)) {
            throw new PurchaseException('A result belongs only to a NIN, BVN or Exam PIN purchase that is being settled.');
        }
        if (self::where('purchase_id', $purchase->id)->exists()) {
            throw new PurchaseException('This purchase already has its result.');
        }
        $attempt = PurchaseAttempt::find($this->purchase_attempt_id);
        if ($attempt === null || $attempt->purchase_id !== $purchase->id || $attempt->status !== PurchaseAttemptStatus::Succeeded) {
            throw new PurchaseException('A result is stored only from the purchase\'s own succeeded delivering attempt.');
        }
        $fields = $this->fields();
        if ($fields === null) {
            throw new PurchaseException('A result needs valid fields and their exact count.');
        }
        if (! $purchase->recipient_type->isIdentity() && ! self::hasValue($fields)) {
            throw new PurchaseException('An Exam PIN result needs at least one value that is not blank.');
        }
    }
}
