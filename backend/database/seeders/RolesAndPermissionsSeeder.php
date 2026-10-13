<?php

namespace Database\Seeders;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles and permissions on the `admin` guard. Safe to run on every deploy:
 * - every SystemPermission is stored;
 * - built-in roles that do not exist yet are created with their default permissions;
 * - existing roles keep the permissions staff gave them in the admin area;
 * - Super Admin always holds every permission.
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

        // DatabaseSeeder runs WithoutModelEvents, so spatie's cache-refresh events do not
        // fire for the permissions just created: reload them before assigning to roles.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            $existing = Role::where('name', $role->value)->where('guard_name', self::GUARD)->first();

            if ($existing === null) {
                Role::create(['name' => $role->value, 'guard_name' => self::GUARD])->syncPermissions($role->permissions());
            } elseif ($role === SystemRole::SuperAdmin) {
                $existing->syncPermissions($role->permissions());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
