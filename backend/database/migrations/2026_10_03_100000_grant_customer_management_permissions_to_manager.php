<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 3 Step 5 adds customers.update and customers.reset-password, granted
 * to Manager by default. RolesAndPermissionsSeeder only applies defaults when a
 * role is first created, so existing databases get the one-time grant here.
 * Fresh databases have no roles yet at this point; the seeder covers them.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['customers.update', 'customers.reset-password'];

    public function up(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'admin')->first();

        if ($manager === null) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            $manager->givePermissionTo(Permission::findOrCreate($name, 'admin'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $manager = Role::where('name', 'manager')->where('guard_name', 'admin')->first();

        foreach (self::PERMISSIONS as $name) {
            if ($manager?->hasPermissionTo($name, 'admin')) {
                $manager->revokePermissionTo($name);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
