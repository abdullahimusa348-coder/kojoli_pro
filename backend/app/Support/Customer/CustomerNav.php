<?php

namespace App\Support\Customer;

use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchases\PurchaseCatalog;
use App\Support\Referrals\ReferralEligibility;

/**
 * Customer-area navigation: the single list behind the desktop top bar, the
 * mobile bottom bar and the account menu (mirrors App\Support\Admin\AdminModule).
 *
 * Only pages that exist are listed. Future modules (services, referrals,
 * support, ...) are added here in the phase that builds them; there are no
 * placeholder or "coming soon" entries. Wallet arrived in Phase 8; Buy and
 * My purchases in Phase 10 (Buy only while something can actually be bought);
 * Referrals in Phase 12 (Subscribers, Vendors and Affiliates only, never API Users).
 */
enum CustomerNav: string
{
    case Dashboard = 'dashboard';
    case Wallet = 'wallet';
    case Buy = 'buy';
    case Purchases = 'purchases';
    case Referrals = 'referrals';
    case Account = 'account';
    case Security = 'security';
    case EmailVerification = 'email-verification';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Wallet => 'Wallet',
            self::Buy => 'Buy',
            self::Purchases => 'My purchases',
            self::Referrals => 'Referrals',
            self::Account => 'Account',
            self::Security => 'Security',
            self::EmailVerification => 'Email verification',
        };
    }

    /** Primary items sit in the bottom bar (mobile) and top bar (desktop); the rest live in the menu. */
    public function isPrimary(): bool
    {
        return in_array($this, [self::Dashboard, self::Wallet, self::Buy, self::Account], true);
    }

    public function url(): string
    {
        return match ($this) {
            self::Dashboard => route('dashboard'),
            self::Wallet => route('wallet'),
            self::Buy => route('buy'),
            self::Purchases => route('purchases'),
            self::Referrals => route('referrals'),
            self::Account => route('profile.edit'),
            self::Security => route('security'),
            self::EmailVerification => route('verification.notice'),
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Dashboard => request()->routeIs('dashboard'),
            self::Wallet => request()->routeIs('wallet', 'wallet.*'),
            self::Buy => request()->routeIs('buy', 'buy.*'),
            self::Purchases => request()->routeIs('purchases', 'purchases.*'),
            self::Referrals => request()->routeIs('referrals'),
            self::Account => request()->routeIs('profile.*'),
            self::Security => request()->routeIs('security'),
            self::EmailVerification => request()->routeIs('verification.*'),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return match ($this) {
            self::EmailVerification => User::emailVerificationRequired(),
            // Shown only while something can actually be bought (a real provider adapter is installed).
            self::Buy => app(PurchaseCatalog::class)->hasAnything($user),
            self::Purchases => Purchase::where('user_id', $user->id)->exists() || app(PurchaseCatalog::class)->hasAnything($user),
            self::Referrals => ReferralEligibility::canRefer($user),
            default => true,
        };
    }

    /** Heroicons (outline, 24px) path data. */
    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
            self::Wallet => 'Wallet',
            self::Buy => 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
            self::Purchases => 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z',
            self::Referrals => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
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
