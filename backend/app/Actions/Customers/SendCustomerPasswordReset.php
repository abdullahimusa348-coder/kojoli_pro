<?php

namespace App\Actions\Customers;

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sends the customer the standard password-reset email (requires
 * customers.reset-password). Staff never see, set or retrieve the password;
 * the customer chooses a new one through the existing reset flow.
 */
class SendCustomerPasswordReset
{
    public function handle(User $customer, SystemUser $actor): void
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::CustomersResetPassword->value)) {
            throw new AuthorizationException('You are not allowed to send password resets.');
        }

        if (! $customer->isActive()) {
            throw ValidationException::withMessages(['customer' => 'This account is disabled. Enable it before sending a password reset.']);
        }

        $status = Password::broker('users')->sendResetLink(['email' => $customer->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['customer' => __($status)]);
        }
    }
}
