<?php

namespace App\Actions\Admin\Providers;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check shared by the provider actions: active staff holding every given permission. */
class ProviderRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::ProvidersView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage providers.');
            }
        }
    }
}
