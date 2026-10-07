<?php

namespace App\Support\Permissions;

/**
 * Admin modules that permissions are grouped under in the Roles & Permissions
 * matrix. Modules that are not built yet already have permissions defined so
 * they can be granted now and enforced when the module is built.
 */
enum PermissionModule: string
{
    case Dashboard = 'dashboard';
    case SystemUsers = 'system-users';
    case Roles = 'roles';
    case Settings = 'settings';
    case Users = 'users';
    case Wallet = 'wallet';
    case Services = 'services';
    case Pricing = 'pricing';
    case Providers = 'providers';
    case Payments = 'payments';
    case Purchases = 'purchases';
    case Transactions = 'transactions';
    case Withdrawals = 'withdrawals';
    case Referrals = 'referrals';
    case Notifications = 'notifications';
    case Support = 'support';
    case Reports = 'reports';
    case AuditLogs = 'audit-logs';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::SystemUsers => 'System Users',
            self::Roles => 'Roles & Permissions',
            self::Settings => 'Settings',
            self::Users => 'Users (customers)',
            self::Wallet => 'Wallet',
            self::Services => 'Services',
            self::Pricing => 'Pricing',
            self::Providers => 'Providers',
            self::Payments => 'Payments',
            self::Purchases => 'Purchases',
            self::Transactions => 'Transactions',
            self::Withdrawals => 'Withdrawals',
            self::Referrals => 'Referral & Commission',
            self::Notifications => 'Notifications',
            self::Support => 'Support',
            self::Reports => 'Reports',
            self::AuditLogs => 'Audit / Activity Logs',
        };
    }

    /** Whether the module's screens exist yet (its permissions are enforced today). */
    public function isBuilt(): bool
    {
        return in_array($this, [self::Dashboard, self::SystemUsers, self::Roles, self::Settings, self::Users, self::Services, self::Pricing, self::Providers, self::Wallet, self::Transactions, self::Payments, self::Purchases, self::Referrals], true);
    }
}
