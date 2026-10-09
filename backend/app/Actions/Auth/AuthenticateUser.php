<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\LoginThrottle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies customer login credentials (email or phone + password), with
 * brute-force throttling and account-status checks. Does not start a session
 * or issue a token: the web and API layers do that with the returned user.
 * Staff sign in through AuthenticateSystemUser instead.
 */
class AuthenticateUser
{
    public const MAX_ATTEMPTS = LoginThrottle::MAX_ATTEMPTS;

    private LoginThrottle $throttle;

    public function __construct()
    {
        $this->throttle = new LoginThrottle;
    }

    public function handle(string $login, string $password, string $ip): User
    {
        $this->throttle->ensureNotLocked($login, $ip);

        $user = User::findByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            if (! $user) {
                // Spend comparable hashing time so response timing does not reveal unknown accounts.
                Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            }
            $this->throttle->hit($login, $ip);

            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        $this->throttle->clear($login, $ip);

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'login' => 'This account has been disabled. Please contact support.',
            ]);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        return $user;
    }
}
