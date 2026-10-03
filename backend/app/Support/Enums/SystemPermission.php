<?php

namespace App\Support\Enums;

use App\Support\Permissions\PermissionModule;

/**
 * Staff permissions (spatie permissions on the `admin` guard), grouped by
 * admin module. This enum is the permission catalog: RolesAndPermissionsSeeder
 * stores every case, and the Roles & Permissions matrix lists them.
 *
 * Naming: "<module>.<action>" with actions view, create, update, delete,
 * manage, or a specific action where clearer (e.g. customers.change-type).
 * Permissions for modules that are not built yet are defined so roles can be
 * prepared; they take effect when the module is built.
 */
enum SystemPermission: string
{
    // Dashboard
    case AdminAccess = 'admin.access';

    // System Users
    case SystemUsersManage = 'system-users.manage';

    // Roles & Permissions
    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    // Settings
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // Users (customers)
    case CustomersView = 'customers.view';
    case CustomersUpdateStatus = 'customers.update-status';
    case CustomersChangeType = 'customers.change-type';
    case CustomersUpdate = 'customers.update';
    case CustomersResetPassword = 'customers.reset-password';

    // Wallet
    case WalletView = 'wallet.view';
    case WalletAdjust = 'wallet.adjust';
    case WalletManage = 'wallet.manage';

    // Services
    case ServicesView = 'services.view';
    case ServicesCreate = 'services.create';
    case ServicesUpdate = 'services.update';
    case ServicesDelete = 'services.delete';

    // Pricing (customer selling prices for catalog plans)
    case PricingView = 'pricing.view';
    case PricingUpdate = 'pricing.update';

    // Providers
    case ProvidersView = 'providers.view';
    case ProvidersCreate = 'providers.create';
    case ProvidersUpdate = 'providers.update';
    case ProvidersDelete = 'providers.delete';
    case ProvidersCredentials = 'providers.credentials';

    // Payments
    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';
    case PaymentsGateways = 'payments.gateways';
    case PaymentsCredentials = 'payments.credentials';

    // Transactions
    case TransactionsView = 'transactions.view';
    case TransactionsManage = 'transactions.manage';

    // Withdrawals
    case WithdrawalsView = 'withdrawals.view';
    case WithdrawalsManage = 'withdrawals.manage';

    // Referral & Commission
    case ReferralsView = 'referrals.view';
    case ReferralsManage = 'referrals.manage';

    // Notifications
    case NotificationsView = 'notifications.view';
    case NotificationsManage = 'notifications.manage';

    // Support
    case SupportView = 'support.view';
    case SupportManage = 'support.manage';

    // Reports
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // Audit / Activity Logs
    case AuditLogsView = 'audit-logs.view';

    public function module(): PermissionModule
    {
        return match ($this) {
            self::AdminAccess => PermissionModule::Dashboard,
            self::SystemUsersManage => PermissionModule::SystemUsers,
            self::RolesView, self::RolesCreate, self::RolesUpdate, self::RolesDelete => PermissionModule::Roles,
            self::SettingsView, self::SettingsUpdate => PermissionModule::Settings,
            self::CustomersView, self::CustomersUpdateStatus, self::CustomersChangeType,
            self::CustomersUpdate, self::CustomersResetPassword => PermissionModule::Users,
            self::WalletView, self::WalletAdjust, self::WalletManage => PermissionModule::Wallet,
            self::ServicesView, self::ServicesCreate, self::ServicesUpdate, self::ServicesDelete => PermissionModule::Services,
            self::PricingView, self::PricingUpdate => PermissionModule::Pricing,
            self::ProvidersView, self::ProvidersCreate, self::ProvidersUpdate, self::ProvidersDelete, self::ProvidersCredentials => PermissionModule::Providers,
            self::PaymentsView, self::PaymentsManage, self::PaymentsGateways, self::PaymentsCredentials => PermissionModule::Payments,
            self::TransactionsView, self::TransactionsManage => PermissionModule::Transactions,
            self::WithdrawalsView, self::WithdrawalsManage => PermissionModule::Withdrawals,
            self::ReferralsView, self::ReferralsManage => PermissionModule::Referrals,
            self::NotificationsView, self::NotificationsManage => PermissionModule::Notifications,
            self::SupportView, self::SupportManage => PermissionModule::Support,
            self::ReportsView, self::ReportsExport => PermissionModule::Reports,
            self::AuditLogsView => PermissionModule::AuditLogs,
        };
    }

    /** Checkbox label in the permission matrix. */
    public function label(): string
    {
        return match ($this) {
            self::AdminAccess => 'Access admin area & dashboard',
            self::SystemUsersManage => 'Manage staff accounts',
            self::CustomersUpdateStatus => 'Enable / disable customers',
            self::CustomersChangeType => 'Change customer type',
            self::CustomersUpdate => 'Edit customer details',
            self::CustomersResetPassword => 'Send password reset',
            self::ProvidersCredentials => 'Manage credentials',
            self::WalletAdjust => 'Credit / debit / reverse',
            self::WalletManage => 'Freeze / unfreeze',
            self::PaymentsManage => 'Recheck / resolve review',
            self::PaymentsGateways => 'Manage gateways',
            self::PaymentsCredentials => 'Manage gateway credentials',
            self::WithdrawalsManage => 'Approve / manage',
            self::ReportsExport => 'Export',
            default => match (substr($this->value, strrpos($this->value, '.') + 1)) {
                'view' => 'View',
                'create' => 'Create',
                'update' => 'Update',
                'delete' => 'Delete',
                'manage' => 'Manage',
                default => ucfirst($this->value),
            },
        };
    }

    /** Route middleware enforcing this permission on the admin guard. */
    public function middleware(): string
    {
        return 'permission:'.$this->value.',admin';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, list<self>> permissions grouped by module value, in module order */
    public static function byModule(): array
    {
        $groups = [];
        foreach (PermissionModule::cases() as $module) {
            $groups[$module->value] = array_values(array_filter(self::cases(), fn (self $p) => $p->module() === $module));
        }

        return $groups;
    }
}
