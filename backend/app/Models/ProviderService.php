<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider capability: the provider supports this catalog service. Routes for
 * the service's plans need an active capability; switching it off makes those
 * routes ineligible without touching their own flags. When requires_plan_code
 * is false, routes may omit the provider plan code (provider_service_code may
 * identify the whole service instead).
 */
class ProviderService extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_plan_code' => 'boolean'];
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
