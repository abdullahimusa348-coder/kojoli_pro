<?php

namespace App\Models;

use App\Support\Catalog\CatalogIcon;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of catalog services (e.g. Telecom). slug and is_active are set only
 * by the catalog actions: slug once at creation, is_active through the status action.
 */
class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'icon', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'icon' => CatalogIcon::class,
        ];
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    /** @param  Builder<ServiceCategory>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
