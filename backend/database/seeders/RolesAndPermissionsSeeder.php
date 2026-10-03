<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles and permissions. Idempotent: safe to run on every deploy.
 * Customer tiers (Subscriber, Vendor, ...) are the users.user_type column, not roles.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const SUPER_ADMIN = 'super-admin';

    public const ADMIN = 'admin';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $adminAccess = Permission::findOrCreate(User::ADMIN_ACCESS, 'web');

        // super-admin bypasses every check via Gate::before (AppServiceProvider).
        Role::findOrCreate(self::SUPER_ADMIN, 'web');
        Role::findOrCreate(self::ADMIN, 'web')->givePermissionTo($adminAccess);
    }
}
