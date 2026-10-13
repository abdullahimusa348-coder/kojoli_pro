<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 13 CP1 adds the KYC and virtual-account permissions (SystemPermission)
 * with their approved grants. The virtual-account permissions are defined now,
 * as the other unbuilt modules' are, so roles can be prepared; no virtual-account
 * screen exists yet. RolesAndPermissionsSeeder only applies defaults when a role
 * is first created, so existing databases get the grants here. Super Admin holds
 * every permission regardless. Fresh databases have no roles yet at this point;
 * the seeder covers them. This runs before the KYC tables are created, so a
 * refused rollback of those tables stops before anything here changes.
 */
return new class extends Migration
{
    /** Role name => the permissions granted to it here. */
    private const GRANTS = [
        'super-admin' => ['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents',
            'virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers', 'virtual-accounts.credentials'],
        'manager' => ['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents', 'virtual-accounts.view'],
        'support' => ['kyc.view'],
        'finance' => ['virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers'],
    ];

    public function up(): void
    {
        // Fresh databases have no roles yet: the seeder creates these permissions together with the roles.
        if (! Role::where('guard_name', 'admin')->exists()) {
            return;
        }

        foreach (self::names() as $name) {
            Permission::findOrCreate($name, 'admin');
        }

        foreach (self::GRANTS as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'admin')->first();

            if ($role !== null) {
                $role->givePermissionTo($names);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (Permission::whereIn('name', self::names())->where('guard_name', 'admin')->get() as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private static function names(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::GRANTS))));
    }
};
