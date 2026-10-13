<?php

namespace App\Actions\Admin\Roles;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Renames (custom roles only) and sets the exact permission list of a role. */
class UpdateRole
{
    public function __construct(private RoleRules $rules) {}

    /** @param  list<string>  $permissions */
    public function handle(Role $role, ?string $name, array $permissions, SystemUser $actor): Role
    {
        $this->rules->authorize($actor, SystemPermission::RolesUpdate);
        $this->rules->ensureEditable($role);
        $this->rules->ensureNotOwnRole($actor, $role);
        $this->rules->ensureNoEscalation($actor, $role->permissions->pluck('name')->all(), $permissions);

        if ($name !== null && $name !== $role->name && SystemRole::isBuiltIn($role->name)) {
            throw ValidationException::withMessages(['name' => 'Built-in role names cannot be changed.']);
        }

        return DB::transaction(function () use ($role, $name, $permissions) {
            if ($name !== null && $name !== $role->name) {
                $role->name = $name;
                $role->save();
            }

            // Takes effect for every staff member with this role on their next request.
            $role->syncPermissions($permissions);

            return $role;
        });
    }
}
