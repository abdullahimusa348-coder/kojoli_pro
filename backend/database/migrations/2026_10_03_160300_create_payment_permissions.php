<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 9 adds payments.gateways and payments.credentials and builds the
 * Payments module. Existing databases get the new permissions and make sure
 * Super Admin holds every payments.* permission; no other role gets them by
 * default. Fresh databases have no roles yet at this point; the seeder covers them.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['payments.view', 'payments.manage', 'payments.gateways', 'payments.credentials'];

    public function up(): void
    {
        $super = Role::where('name', 'super-admin')->where('guard_name', 'admin')->first();

        if ($super === null) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            $super->givePermissionTo(Permission::findOrCreate($name, 'admin'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', ['payments.gateways', 'payments.credentials'])->where('guard_name', 'admin')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
