<?php

namespace App\Actions\Admin\Kyc;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check for the KYC admin actions: active staff holding kyc.view plus every given permission. */
class KycRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::KycView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage KYC requirements.');
            }
        }
    }
}
