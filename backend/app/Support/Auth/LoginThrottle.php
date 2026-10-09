<?php

namespace App\Support\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brute-force protection for a login form: MAX_ATTEMPTS failures per
 * login + IP lock that pair out. The prefix keeps separate areas (customer,
 * staff) from sharing counters.
 */
class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private string $prefix = '') {}

    public function ensureNotLocked(string $login, string $ip, string $field = 'login'): void
    {
        $key = $this->key($login, $ip);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        throw ValidationException::withMessages([
            $field => trans('auth.throttle', [
                'seconds' => $seconds = RateLimiter::availableIn($key),
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function hit(string $login, string $ip): void
    {
        RateLimiter::hit($this->key($login, $ip));
    }

    public function clear(string $login, string $ip): void
    {
        RateLimiter::clear($this->key($login, $ip));
    }

    public function key(string $login, string $ip): string
    {
        return $this->prefix.Str::transliterate(Str::lower(trim($login)).'|'.$ip);
    }
}
