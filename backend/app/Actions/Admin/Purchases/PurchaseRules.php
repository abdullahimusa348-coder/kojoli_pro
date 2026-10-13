<?php

namespace App\Actions\Admin\Purchases;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check for staff purchase actions: active staff holding purchases.view plus every given permission. */
class PurchaseRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::PurchasesView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage purchases.');
            }
        }
    }
}
