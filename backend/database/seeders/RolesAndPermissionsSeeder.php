<?php

namespace Database\Seeders;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles and permissions on the `admin` guard. Idempotent: safe to run on
 * every deploy, and it resets each role to exactly its defined permissions.
 * Customers (users table) never hold roles; their tier is users.user_type.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'admin';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        foreach (SystemRole::cases() as $role) {
            Role::findOrCreate($role->value, self::GUARD)->syncPermissions($role->permissions());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
