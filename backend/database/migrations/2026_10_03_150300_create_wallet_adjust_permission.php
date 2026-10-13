<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 8 adds wallet.adjust and builds the Wallet and Transactions modules.
 * Existing databases get the new permission and make sure Super Admin holds
 * every wallet.* and transactions.* permission; no other role gets them by
 * default. Fresh databases have no roles yet at this point; the seeder covers them.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['wallet.view', 'wallet.adjust', 'wallet.manage', 'transactions.view', 'transactions.manage'];

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
        Permission::where('name', 'wallet.adjust')->where('guard_name', 'admin')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
