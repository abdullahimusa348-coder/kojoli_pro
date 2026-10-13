<?php

namespace App\Models;

use App\Support\Pricing\BasisPoints;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of commission rates and caps (Phase 12): one row per
 * saved change of a service's setting, with the old and new rate and cap
 * (old values empty on the first save), the staff member, the reason (10 to
 * 500 characters) and the time. Written after the setting itself, so its new
 * values are the setting's values. Never updated or deleted, and existing
 * commissions are never recalculated from it.
 */
class CommissionSettingChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $change) => $change->enforceInvariants());
        static::updating(fn () => throw new LogicException('Commission rate and cap history is append-only.'));
        static::deleting(fn () => throw new LogicException('Commission rate and cap history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commission_setting_id' => 'integer',
            'service_id' => 'integer',
            'old_rate_bps' => 'integer',
            'new_rate_bps' => 'integer',
            'old_cap_kobo' => 'integer',
            'new_cap_kobo' => 'integer',
            'changed_by' => 'integer',
        ];
    }

    /** @return BelongsTo<CommissionSetting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(CommissionSetting::class, 'commission_setting_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }

    private function enforceInvariants(): void
    {
        $setting = CommissionSetting::find($this->commission_setting_id);
        if ($setting === null || $setting->service_id !== $this->service_id) {
            throw new LogicException('A rate and cap change belongs to its setting and that setting\'s service.');
        }
        if ($this->new_rate_bps !== $setting->rate_bps || $this->new_cap_kobo !== $setting->cap_kobo) {
            throw new LogicException('A rate and cap change records the values the setting now has.');
        }
        if ($this->old_rate_bps !== null && ($this->old_rate_bps < 0 || $this->old_rate_bps > BasisPoints::MAX)) {
            throw new LogicException('A commission rate is 0 to 9999 basis points (0–99.99%).');
        }
        if (($this->old_rate_bps === null) !== ($this->old_cap_kobo === null)) {
            throw new LogicException('The old rate and cap are both recorded, or both empty on a service\'s first save.');
        }
        $length = mb_strlen((string) $this->reason);
        if ($length < 10 || $length > 500) {
            throw new LogicException('A rate or cap change needs a reason of 10 to 500 characters.');
        }
        if ($this->changed_by === null) {
            throw new LogicException('A rate or cap change records the staff member who made it.');
        }
    }
}
