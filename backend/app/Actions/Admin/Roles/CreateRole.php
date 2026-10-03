<?php

namespace App\Actions\Admin\Roles;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class CreateRole
{
    public function __construct(private RoleRules $rules) {}

    /** @param  list<string>  $permissions */
    public function handle(string $name, array $permissions, SystemUser $actor): Role
    {
        $this->rules->authorize($actor, SystemPermission::RolesCreate);
        $this->rules->ensureNoEscalation($actor, [], $permissions);

        return DB::transaction(function () use ($name, $permissions) {
            $role = Role::create(['name' => $name, 'guard_name' => RolesAndPermissionsSeeder::GUARD]);
            $role->syncPermissions($permissions);

            return $role;
        });
    }
}
