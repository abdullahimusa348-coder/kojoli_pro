<?php

namespace App\Actions\Admin\Referrals;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check for the admin referral actions: active staff holding referrals.view plus every given permission. */
class ReferralRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::ReferralsView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage referral commissions.');
            }
        }
    }
}
