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
     * Permissions granted to each role. Super Admin is listed for completeness
     * but passes every check through Gate::before regardless.
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
            ],
            self::Support => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
                SystemPermission::CustomersUpdateStatus->value,
            ],
            self::Finance, self::Viewer => [
                SystemPermission::AdminAccess->value,
                SystemPermission::CustomersView->value,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
