<?php

namespace App\Models;

use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The BVN or NIN a KYC submission carried (Phase 13 CP2a). It keeps the number only encrypted (Laravel's encrypted cast, under the
 * app key), with its mask and a keyed lookup hash beside it, and never as a searchable column. It is hidden from serialisation, and
 * its debug output shows the mask only. Written with its submission and never changed: a correction is a new submission with its own
 * rows, so a value an earlier approval accepted stays exactly where it was.
 */
class KycSubmissionIdentity extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['ciphertext', 'lookup_hash'];

    protected static function booted(): void
    {
        static::creating(fn (self $identity) => $identity->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC identity values are immutable.'));
        static::deleting(fn () => throw new LogicException('KYC identity values are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_submission_id' => 'integer',
            'ciphertext' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Debug output shows what may be displayed. A plain dump would otherwise print the stored ciphertext and the lookup hash.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'kyc_submission_id' => $this->kyc_submission_id,
            'requirement_key' => $this->requirement_key,
            'masked_value' => $this->masked_value,
        ];
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /**
     * The rows that carry this number under this type: the duplicate check. KYC rows only, and the caller decides what to show.
     *
     * @param  Builder<KycSubmissionIdentity>  $query
     */
    public function scopeMatching(Builder $query, KycRequirementType $type, #[\SensitiveParameter] string $canonical): void
    {
        $query->where('requirement_key', $type->value)
            ->whereIn('lookup_hash', KycIdentityNumber::lookupHashes($type, $canonical));
    }

    /**
     * The value a customer's latest approved submission accepted for this type, or null. An approved correction replaces the
     * value before it; the earlier rows stay as they were.
     */
    public static function acceptedFor(User $customer, KycRequirementType $type): ?self
    {
        return self::query()
            ->join('kyc_submissions', 'kyc_submissions.id', '=', 'kyc_submission_identities.kyc_submission_id')
            ->join('kyc_reviews', 'kyc_reviews.kyc_submission_id', '=', 'kyc_submissions.id')
            ->where('kyc_submissions.user_id', $customer->id)
            ->where('kyc_submission_identities.requirement_key', $type->value)
            ->where('kyc_reviews.decision', 'approved')
            ->orderByDesc('kyc_reviews.decided_at')
            ->orderByDesc('kyc_reviews.id')
            ->select('kyc_submission_identities.*')
            ->first();
    }

    private function enforceInvariants(): void
    {
        if (KycReview::where('kyc_submission_id', $this->kyc_submission_id)->exists()) {
            throw new LogicException('A KYC submission takes no new identity value once it has a decision.');
        }
        $type = KycRequirementType::tryFrom((string) $this->requirement_key);
        if (! in_array($type, [KycRequirementType::Bvn, KycRequirementType::Nin], true)) {
            throw new LogicException('A KYC identity value is a BVN or a NIN.');
        }
        if (! KycSubmissionRequirement::where('kyc_submission_id', $this->kyc_submission_id)->where('requirement_key', $type->value)->exists()) {
            throw new LogicException('A KYC identity value belongs to a requirement its submission asked for.');
        }

        // Decrypted in memory for these checks only; nothing is returned, logged or put in a message.
        $number = $this->ciphertext;
        if (! KycIdentityNumber::isCanonical($number)) {
            throw new LogicException('A KYC identity value is exactly 11 digits.');
        }
        if ($this->masked_value !== KycIdentityNumber::mask($number)) {
            throw new LogicException('A KYC identity value keeps its mask.');
        }
        if ($this->lookup_hash !== KycIdentityNumber::lookupHash($type, $number)) {
            throw new LogicException('A KYC identity value keeps its keyed lookup hash.');
        }
    }
}
