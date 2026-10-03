<?php

namespace App\Support\Enums;

/** Staff permissions (spatie permissions on the `admin` guard). */
enum SystemPermission: string
{
    case AdminAccess = 'admin.access';
    case CustomersView = 'customers.view';
    case CustomersUpdateStatus = 'customers.update-status';
    case CustomersChangeType = 'customers.change-type';
    case SystemUsersManage = 'system-users.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
