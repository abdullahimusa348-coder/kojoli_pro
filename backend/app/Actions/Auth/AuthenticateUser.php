<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Verifies login credentials (email or phone + password), with brute-force
 * throttling and account-status checks. Does not start a session or issue a
 * token: the web and API layers do that with the returned user.
 */
class AuthenticateUser
{
    public const MAX_ATTEMPTS = 5;

    public function handle(string $login, string $password, string $ip): User
    {
        $key = $this->throttleKey($login, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            event(new Lockout(request()));

            throw ValidationException::withMessages([
                'login' => trans('auth.throttle', [
                    'seconds' => $seconds = RateLimiter::availableIn($key),
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        $user = User::findByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            if (! $user) {
                // Spend comparable hashing time so response timing does not reveal unknown accounts.
                Hash::check($password, '$2y$12$'.str_repeat('a', 53));
            }
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        RateLimiter::clear($key);

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

    private function throttleKey(string $login, string $ip): string
    {
        return Str::transliterate(Str::lower(trim($login)).'|'.$ip);
    }
}
