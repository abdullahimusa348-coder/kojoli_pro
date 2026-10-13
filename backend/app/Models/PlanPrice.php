<?php

namespace App\Models;

use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer selling price of a plan for one customer type (kobo). Fixed plans
 * use price_kobo; variable plans use discount_bps and fee_kobo on the face
 * value the customer enters. Not a cost/provider price and not a commission.
 * Values change only through SavePlanPrices / SetPlanPriceStatus, which also
 * write the append-only history.
 */
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'price_kobo' => 'integer',
            'discount_bps' => 'integer',
            'fee_kobo' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'updated_by')->withTrashed();
    }

    /** @return HasMany<PlanPriceChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(PlanPriceChange::class);
    }

    /** "₦1,250.00" for fixed prices, "2.5% off + ₦50.00 fee" for variable ones. */
    public function summary(): string
    {
        return self::describe($this->price_kobo, $this->discount_bps, $this->fee_kobo);
    }

    public static function describe(?int $priceKobo, ?int $discountBps, ?int $feeKobo): string
    {
        if ($priceKobo !== null) {
            return Money::format($priceKobo);
        }
        if ($discountBps === null && $feeKobo === null) {
            return '—';
        }

        return BasisPoints::label($discountBps ?? 0).' off + '.Money::format($feeKobo ?? 0).' fee';
    }
}
