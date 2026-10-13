<?php

namespace App\Models;

use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only price history: one row per change to a plan price (old and new
 * values, who and when). Rows are never updated or deleted.
 */
class PlanPriceChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Price history is append-only.'));
        static::deleting(fn () => throw new LogicException('Price history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'old_price_kobo' => 'integer',
            'new_price_kobo' => 'integer',
            'old_discount_bps' => 'integer',
            'new_discount_bps' => 'integer',
            'old_fee_kobo' => 'integer',
            'new_fee_kobo' => 'integer',
            'old_is_active' => 'boolean',
            'new_is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PlanPrice, $this> */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }

    public function oldSummary(): string
    {
        return $this->old_is_active === null ? 'Not priced' : PlanPrice::describe($this->old_price_kobo, $this->old_discount_bps, $this->old_fee_kobo).($this->old_is_active ? '' : ' (disabled)');
    }

    public function newSummary(): string
    {
        return PlanPrice::describe($this->new_price_kobo, $this->new_discount_bps, $this->new_fee_kobo).($this->new_is_active ? '' : ' (disabled)');
    }
}
