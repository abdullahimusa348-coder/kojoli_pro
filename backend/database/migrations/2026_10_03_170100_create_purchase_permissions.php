<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 10 adds purchases.view and purchases.manage (Purchases module).
 * Existing databases get both permissions on Super Admin; no other role gets
 * them by default. Fresh databases have no roles yet at this point; the
 * seeder covers them.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['purchases.view', 'purchases.manage'];

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
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'admin')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
