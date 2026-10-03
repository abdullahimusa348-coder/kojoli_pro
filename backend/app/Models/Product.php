<?php

namespace App\Models;

use App\Support\Catalog\Network;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of plans inside a service (e.g. "MTN SME" under Data). code and
 * is_active are set only by the catalog actions. No prices or providers.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['service_id', 'name', 'network', 'description', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'network' => Network::class,
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /** Active, and its service and category are available. */
    public function isAvailable(): bool
    {
        return $this->is_active && (bool) $this->service?->isAvailable();
    }

    /** Own status, or the nearest disabled parent that makes it unavailable. */
    public function statusLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Disabled',
            ! $this->service?->is_active => 'Active (service disabled)',
            ! $this->service?->category?->is_active => 'Active (category disabled)',
            default => 'Active',
        };
    }

    /** @param  Builder<Product>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('service', fn (Builder $q) => $q->available());
    }

    /** @param  Builder<Product>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
