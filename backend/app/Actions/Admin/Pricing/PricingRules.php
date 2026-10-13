<?php

namespace App\Actions\Admin\Pricing;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check for pricing changes: active staff with services.view, pricing.view and pricing.update. */
class PricingRules
{
    public function authorizeUpdate(SystemUser $actor): void
    {
        foreach ([SystemPermission::ServicesView, SystemPermission::PricingView, SystemPermission::PricingUpdate] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage prices.');
            }
        }
    }
}
