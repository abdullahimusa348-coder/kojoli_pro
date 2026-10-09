<?php

namespace App\Actions\Customers;

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Staff edit of a customer's safe profile fields (name, email, phone); requires
 * customers.update. Type and status are deliberately not accepted here: they
 * change only through ChangeUserType and ChangeCustomerStatus.
 */
class UpdateCustomerProfile
{
    /** @param  array{name: string, email: string, phone: string}  $data */
    public function handle(User $customer, array $data, SystemUser $actor): User
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::CustomersUpdate->value)) {
            throw new AuthorizationException('You are not allowed to edit customer details.');
        }

        $customer->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
        ]);

        $emailChanged = $customer->isDirty('email');
        if ($emailChanged) {
            $customer->email_verified_at = null;
        }

        $customer->save();

        if ($emailChanged) {
            // No-op unless email verification is switched on.
            $customer->sendEmailVerificationNotification();
        }

        return $customer;
    }
}
