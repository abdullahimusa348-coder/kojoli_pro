<?php

namespace App\Models;

use App\Support\Catalog\AmountType;
use App\Support\Catalog\ValidityPeriod;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific option within a product (e.g. "MTN SME 1GB – 30 days"). Later
 * phases attach customer-type prices and provider routes to plans in their
 * own tables. Available only when the plan, product, service and category
 * are all active.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['product_id', 'name', 'amount_type', 'validity_period', 'validity_days', 'data_volume_mb', 'description', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'validity_days' => 'integer',
            'data_volume_mb' => 'integer',
            'amount_type' => AmountType::class,
            'validity_period' => ValidityPeriod::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
