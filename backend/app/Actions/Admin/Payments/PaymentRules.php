<?php

namespace App\Actions\Admin\Payments;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side check shared by the payment and gateway actions: active staff holding payments.view plus every given permission. */
class PaymentRules
{
    public function authorize(SystemUser $actor, SystemPermission ...$permissions): void
    {
        foreach ([SystemPermission::PaymentsView, ...$permissions] as $permission) {
            if (! $actor->isActive() || ! $actor->can($permission->value)) {
                throw new AuthorizationException('You are not allowed to manage payments.');
            }
        }
    }
}
