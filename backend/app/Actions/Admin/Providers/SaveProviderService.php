<?php

namespace App\Actions\Admin\Providers;

use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;

/**
 * Adds or updates a provider capability (the provider supports a catalog
 * service). Switching a capability off or on (setActive) leaves the routes'
 * own flags unchanged; the resolver skips them while it is off.
 */
class SaveProviderService
{
    public function __construct(private ProviderRules $rules) {}

    /** @param  array{service_id: int, requires_plan_code?: bool, provider_service_code?: ?string}  $data */
    public function create(Provider $provider, array $data, SystemUser $actor): ProviderService
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        $capability = (new ProviderService)->forceFill([
            'provider_id' => $provider->id,
            'service_id' => (int) $data['service_id'],
            'is_active' => true,
            'requires_plan_code' => (bool) ($data['requires_plan_code'] ?? false),
            'provider_service_code' => $data['provider_service_code'] ?? null,
        ]);
        $capability->save();

        return $capability;
    }

    /** @param  array{requires_plan_code?: bool, provider_service_code?: ?string}  $data */
    public function update(ProviderService $capability, array $data, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        $capability->forceFill([
            'requires_plan_code' => (bool) ($data['requires_plan_code'] ?? false),
            'provider_service_code' => $data['provider_service_code'] ?? null,
        ])->save();
    }

    public function setActive(ProviderService $capability, bool $active, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        $capability->forceFill(['is_active' => $active])->save();
    }
}
