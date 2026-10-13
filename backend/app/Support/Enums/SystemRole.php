<?php

namespace App\Support\Enums;

/**
 * Staff roles (spatie roles on the `admin` guard). Customers never hold roles.
 */
enum SystemRole: string
{
    case SuperAdmin = 'super-admin';
    case Manager = 'manager';
    case Support = 'support';
    case Finance = 'finance';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Manager => 'Manager',
            self::Support => 'Support',
            self::Finance => 'Finance',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Default permissions for each built-in role, applied when the role is first
     * created. Staff with roles.update can change them later in the admin area
     * (except Super Admin, which always has every permission and passes every
     * check through Gate::before).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => SystemPermission::values(),
            self::Manager => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
                SystemPermission::CustomersUpdateStatus->value,
                SystemPermission::CustomersChangeType->value,
                SystemPermission::CustomersUpdate->value,
                SystemPermission::CustomersResetPassword->value,
                SystemPermission::KycView->value,
                SystemPermission::KycReview->value,
                SystemPermission::KycRequirements->value,
                SystemPermission::KycDocuments->value,
                SystemPermission::VirtualAccountsView->value,
            ],
            self::Support => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
                SystemPermission::CustomersUpdateStatus->value,
                SystemPermission::KycView->value,
            ],
            self::Finance => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
                SystemPermission::VirtualAccountsView->value,
                SystemPermission::VirtualAccountsManage->value,
                SystemPermission::VirtualAccountsProviders->value,
            ],
            self::Viewer => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
            ],
        };
    }

    /** Display name for any admin role name: built-in label, or the custom role's own name. */
    public static function labelFor(string $name): string
    {
        return self::tryFrom($name)?->label() ?? $name;
    }

    public static function isBuiltIn(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
