<?php

namespace App\Actions\Customers;

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserType;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The only supported way to move a customer between Subscriber, Vendor,
 * Affiliate and API User. Requires an active staff member with
 * customers.change-type (Super Admin and Manager by default).
 */
class ChangeUserType
{
    public function handle(User $customer, UserType $type, SystemUser $actor): User
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::CustomersChangeType->value)) {
            throw new AuthorizationException('You are not allowed to change customer types.');
        }

        $customer->forceFill(['user_type' => $type])->save();

        return $customer;
    }
}
