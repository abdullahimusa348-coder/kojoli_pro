<?php

namespace App\Support\Customer;

use App\Models\User;

/**
 * Customer-area navigation: the single list behind the desktop top bar, the
 * mobile bottom bar and the account menu (mirrors App\Support\Admin\AdminModule).
 *
 * Only pages that exist are listed. Future modules (services, wallet,
 * transactions, referrals, support, ...) are added here in the phase that
 * builds them; there are no placeholder or "coming soon" entries.
 */
enum CustomerNav: string
{
    case Dashboard = 'dashboard';
    case Account = 'account';
    case Security = 'security';
    case EmailVerification = 'email-verification';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Account => 'Account',
            self::Security => 'Security',
            self::EmailVerification => 'Email verification',
        };
    }

    /** Primary items sit in the bottom bar (mobile) and top bar (desktop); the rest live in the menu. */
    public function isPrimary(): bool
    {
        return in_array($this, [self::Dashboard, self::Account], true);
    }

    public function url(): string
    {
        return match ($this) {
            self::Dashboard => route('dashboard'),
            self::Account => route('profile.edit'),
            self::Security => route('security'),
            self::EmailVerification => route('verification.notice'),
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Dashboard => request()->routeIs('dashboard'),
            self::Account => request()->routeIs('profile.*'),
            self::Security => request()->routeIs('security'),
            self::EmailVerification => request()->routeIs('verification.*'),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return match ($this) {
            self::EmailVerification => User::emailVerificationRequired(),
            default => true,
        };
    }

    /** Heroicons (outline, 24px) path data. */
    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
            self::Account => 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            self::Security => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
            self::EmailVerification => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        };
    }

    /** @return list<self> */
    public static function primaryFor(User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $item) => $item->isPrimary() && $item->isVisibleTo($user)));
    }

    /** @return list<self> */
    public static function menuFor(User $user): array
    {
        return array_values(array_filter(self::cases(), fn (self $item) => ! $item->isPrimary() && $item->isVisibleTo($user)));
    }
}
