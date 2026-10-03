<?php

namespace App\Models;

use App\Support\Catalog\AmountType;
use App\Support\Catalog\ValidityPeriod;
use App\Support\Enums\UserType;
use App\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific option within a product (e.g. "MTN SME 1GB – 30 days").
 * Customer-type selling prices live in plan_prices (Phase 6); provider routes
 * come later in their own tables. Variable-amount plans carry face-value
 * limits (min/max kobo the customer may enter); these are not prices.
 * Available only when the plan, product, service and category are all active.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['product_id', 'name', 'amount_type', 'validity_period', 'validity_days', 'data_volume_mb', 'min_amount_kobo', 'max_amount_kobo', 'description', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'validity_days' => 'integer',
            'data_volume_mb' => 'integer',
            'min_amount_kobo' => 'integer',
            'max_amount_kobo' => 'integer',
            'amount_type' => AmountType::class,
            'validity_period' => ValidityPeriod::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** @return HasMany<PlanPrice, $this> */
    public function activePrices(): HasMany
    {
        return $this->prices()->where('is_active', true);
    }

    /** @return HasMany<PlanPriceChange, $this> */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(PlanPriceChange::class);
    }

    public function isVariable(): bool
    {
        return $this->amount_type === AmountType::Variable;
    }

    /** "₦50.00 – ₦50,000.00" for variable plans with limits, else null. */
    public function amountLimitsLabel(): ?string
    {
        if ($this->min_amount_kobo === null || $this->max_amount_kobo === null) {
            return null;
        }

        return Money::format($this->min_amount_kobo).' – '.Money::format($this->max_amount_kobo);
    }

    /** Number of customer types with an active price, out of count(UserType::cases()). */
    public function pricedCount(): int
    {
        return $this->active_prices_count ?? $this->activePrices()->count();
    }

    public static function customerTypeCount(): int
    {
        return count(UserType::cases());
    }

    public function isAvailable(): bool
    {
        return $this->is_active && (bool) $this->product?->isAvailable();
    }

    /** Own status, or the nearest disabled parent that makes it unavailable. */
    public function statusLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Disabled',
            ! $this->product?->is_active => 'Active (product disabled)',
            ! $this->product?->service?->is_active => 'Active (service disabled)',
            ! $this->product?->service?->category?->is_active => 'Active (category disabled)',
            default => 'Active',
        };
    }

    /** "1 GB", "500 MB", or null. */
    public function dataVolumeLabel(): ?string
    {
        $mb = $this->data_volume_mb;

        return match (true) {
            $mb === null => null,
            $mb >= 1024 => rtrim(rtrim(number_format($mb / 1024, 2), '0'), '.').' GB',
            default => $mb.' MB',
        };
    }

    /** "Monthly · 30 days", "7 days", or null. */
    public function validityLabel(): ?string
    {
        $parts = array_filter([
            $this->validity_period?->label(),
            $this->validity_days ? $this->validity_days.' '.($this->validity_days === 1 ? 'day' : 'days') : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** @param  Builder<Plan>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('product', fn (Builder $q) => $q->available());
    }

    /** @param  Builder<Plan>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
