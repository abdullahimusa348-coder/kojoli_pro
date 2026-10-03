<?php

namespace App\Models;

use App\Support\Catalog\CatalogIcon;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A catalog service (e.g. Data, NIN). Catalog entry only: plans, pricing,
 * providers and purchasing come in later phases. A service is available
 * only when it and its category are both active.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['category_id', 'name', 'description', 'icon', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'icon' => CatalogIcon::class,
        ];
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && (bool) $this->category?->is_active;
    }

    /** "Active", "Disabled", or "Active (category disabled)". */
    public function statusLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Disabled',
            ! $this->category?->is_active => 'Active (category disabled)',
            default => 'Active',
        };
    }

    /**
     * Services that are active and whose category is active.
     *
     * @param  Builder<Service>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('category', fn (Builder $q) => $q->where('is_active', true));
    }

    /** @param  Builder<Service>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
