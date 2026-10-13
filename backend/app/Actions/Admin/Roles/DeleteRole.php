<?php

namespace App\Actions\Admin\Roles;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Spatie\Permission\Models\Role;

/** Deletes a custom role that no staff member (including deleted staff) holds. */
class DeleteRole
{
    public function __construct(private RoleRules $rules) {}

    public function handle(Role $role, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::RolesDelete);
        $this->rules->ensureEditable($role);
        $this->rules->ensureNotOwnRole($actor, $role);
        $this->rules->ensureDeletable($role);

        $role->delete();
    }
}
