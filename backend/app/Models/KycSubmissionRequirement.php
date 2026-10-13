<?php

namespace App\Models;

use App\Support\Kyc\KycRequirementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One requirement that applied when a KYC submission was sent (Phase 13 CP2a): a copy of its key, type and name at that moment, and
 * for a phone requirement a keyed fingerprint of the phone number then, so a later change of phone is detectable. Written with the
 * submission and never changed: the requirement may be renamed or switched off later, and the submission still shows what it asked
 * for. A phone fingerprint is never a verification.
 */
class KycSubmissionRequirement extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['phone_fingerprint'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC requirement snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('KYC requirement snapshots are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_submission_id' => 'integer',
            'kyc_requirement_id' => 'integer',
            'requirement_type' => KycRequirementType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /** @return BelongsTo<KycRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(KycRequirement::class, 'kyc_requirement_id');
    }

    private function enforceInvariants(): void
    {
        if (KycReview::where('kyc_submission_id', $this->kyc_submission_id)->exists()) {
            throw new LogicException('A KYC submission takes no new requirement once it has a decision.');
        }
        $submission = KycSubmission::find($this->kyc_submission_id);
        $requirement = KycRequirement::find($this->kyc_requirement_id);
        if ($submission === null || $requirement === null) {
            throw new LogicException('A KYC requirement snapshot belongs to a submission and to a KYC requirement.');
        }

        $type = KycRequirementType::tryFrom((string) ($this->attributes['requirement_type'] ?? ''));
        if ($type === null || $requirement->key !== $this->requirement_key || $requirement->requirementType() !== $type) {
            throw new LogicException('A KYC requirement snapshot keeps the key and type of its requirement.');
        }
        if (! $requirement->isRequiredFor($submission->user_type)) {
            throw new LogicException('A KYC requirement snapshot holds only the requirements that applied when the submission was sent.');
        }

        $length = mb_strlen((string) $this->label);
        if ($length < 2 || $length > 120 || $this->label !== $requirement->label) {
            throw new LogicException('A KYC requirement snapshot keeps the name its requirement had when the submission was sent.');
        }

        $fingerprint = $this->attributes['phone_fingerprint'] ?? null;
        if ($type === KycRequirementType::Phone) {
            if (! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
                throw new LogicException('A phone requirement keeps a fingerprint of the phone number it was sent with.');
            }
        } elseif ($fingerprint !== null) {
            throw new LogicException('Only a phone requirement keeps a phone fingerprint.');
        }
    }
}
