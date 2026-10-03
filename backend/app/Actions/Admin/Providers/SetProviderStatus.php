<?php

namespace App\Actions\Admin\Providers;

use App\Models\Provider;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Providers\ProviderStatus;

/** Sets a provider to active, maintenance or inactive (providers.update). Route flags are never changed. */
class SetProviderStatus
{
    public function __construct(private ProviderRules $rules) {}

    public function handle(Provider $provider, ProviderStatus $status, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ProvidersUpdate);

        $provider->forceFill(['status' => $status])->save();
    }
}
