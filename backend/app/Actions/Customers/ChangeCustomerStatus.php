<?php

namespace App\Actions\Customers;

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only supported way to enable or disable a customer account (requires
 * customers.update-status). Disabling also revokes every Sanctum API token and
 * ends "remember me" logins; open web sessions are signed out on their next
 * request by EnsureUserIsActive. Customers are never deleted.
 */
class ChangeCustomerStatus
{
    public function handle(User $customer, UserStatus $status, SystemUser $actor): User
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::CustomersUpdateStatus->value)) {
            throw new AuthorizationException('You are not allowed to change customer status.');
        }

        DB::transaction(function () use ($customer, $status) {
            $customer->forceFill(['status' => $status]);

            if ($status === UserStatus::Disabled) {
                $customer->remember_token = Str::random(60);
                $customer->tokens()->delete();
            }

            $customer->save();
        });

        return $customer;
    }
}
