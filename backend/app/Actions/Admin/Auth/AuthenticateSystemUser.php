<?php

namespace App\Actions\Admin\Auth;

use App\Models\SystemUser;
use App\Support\Auth\LoginThrottle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies staff credentials (email + password) against system_users only.
 * Has its own throttle counters, separate from customer logins.
 */
class AuthenticateSystemUser
{
    private LoginThrottle $throttle;

    public function __construct()
    {
        $this->throttle = new LoginThrottle('admin|');
    }

    public function handle(string $email, string $password, string $ip): SystemUser
    {
        $this->throttle->ensureNotLocked($email, $ip, 'email');

        $staff = SystemUser::where('email', mb_strtolower(trim($email)))->first();

        if (! $staff || ! Hash::check($password, $staff->password)) {
            if (! $staff) {
                Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            }
            $this->throttle->hit($email, $ip);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $this->throttle->clear($email, $ip);

        // Same generic message for disabled or role-less staff: do not reveal account state on the admin login.
        if (! $staff->canAccessAdmin()) {
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $staff->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        return $staff;
    }
}
