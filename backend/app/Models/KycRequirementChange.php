<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of KYC requirement changes (Phase 13 CP1): one row per
 * saved change, with the configuration before and after, the reason (10 to 500
 * characters), the staff member and the time. Written after the requirement
 * itself, so its new state is the requirement's current state. Never updated or
 * deleted.
 */
class KycRequirementChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $change) => $change->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC requirement history is append-only.'));
        static::deleting(fn () => throw new LogicException('KYC requirement history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_requirement_id' => 'integer',
            'old_state' => 'array',
            'new_state' => 'array',
            'changed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KycRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(KycRequirement::class, 'kyc_requirement_id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }

    private function enforceInvariants(): void
    {
        $requirement = KycRequirement::find($this->kyc_requirement_id);
        if ($requirement === null) {
            throw new LogicException('A KYC requirement change belongs to a KYC requirement.');
        }
        $length = mb_strlen((string) $this->reason);
        if ($length < 10 || $length > 500) {
            throw new LogicException('A KYC requirement change needs a reason of 10 to 500 characters.');
        }
        if ($this->changed_by === null) {
            throw new LogicException('A KYC requirement change records the staff member who made it.');
        }

        $keys = array_keys($requirement->snapshot());
        if (array_keys((array) $this->old_state) !== $keys || array_keys((array) $this->new_state) !== $keys) {
            throw new LogicException('A KYC requirement change records the configuration before and after, in full.');
        }
        if ($this->old_state === $this->new_state) {
            throw new LogicException('A KYC requirement change records a real change.');
        }
        if ($this->new_state !== $requirement->snapshot()) {
            throw new LogicException('A KYC requirement change records the configuration the requirement now has.');
        }
    }
}
