<?php

namespace App\Models;

use App\Support\Pricing\BasisPoints;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The current referral commission rate (basis points, 0 to 9999: 0–99.99%)
 * and per-purchase cap (kobo) of one qualifying service (Phase 12). Set only
 * by authorized staff, each change recorded in CommissionSettingChange; never
 * moved to another service or deleted. A missing setting, or a rate or cap
 * of 0, means no commission.
 */
class CommissionSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::saving(fn (self $setting) => $setting->enforceInvariants());
        static::deleting(fn () => throw new LogicException('Commission settings are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'rate_bps' => 'integer',
            'cap_kobo' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'updated_by')->withTrashed();
    }

    /** @return HasMany<CommissionSettingChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(CommissionSettingChange::class);
    }

    private function enforceInvariants(): void
    {
        if ($this->exists && $this->isDirty('service_id')) {
            throw new LogicException('A commission setting always belongs to the same service.');
        }
        if ($this->rate_bps === null || $this->rate_bps < 0 || $this->rate_bps > BasisPoints::MAX) {
            throw new LogicException('A commission rate is 0 to 9999 basis points (0–99.99%).');
        }
        if ($this->cap_kobo === null || $this->cap_kobo < 0) {
            throw new LogicException('A commission cap is a whole number of kobo, 0 or more.');
        }
    }
}
