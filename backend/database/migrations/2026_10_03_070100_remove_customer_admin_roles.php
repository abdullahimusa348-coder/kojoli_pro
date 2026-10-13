<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2 briefly stored staff roles on customer accounts (`web` guard).
 * Staff now live in `system_users` on the `admin` guard, so remove the old
 * customer-side role data. Customers never hold roles or permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        DB::table($tables['model_has_roles'])->where('model_type', 'App\\Models\\User')->delete();
        DB::table($tables['model_has_permissions'])->where('model_type', 'App\\Models\\User')->delete();

        $roleIds = DB::table($tables['roles'])->where('guard_name', 'web')->pluck('id');
        $permissionIds = DB::table($tables['permissions'])->where('guard_name', 'web')->pluck('id');

        DB::table($tables['role_has_permissions'])
            ->whereIn('role_id', $roleIds)
            ->orWhereIn('permission_id', $permissionIds)
            ->delete();
        DB::table($tables['roles'])->whereIn('id', $roleIds)->delete();
        DB::table($tables['permissions'])->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not restorable: the customer-as-admin mechanism has been removed.
    }
};
