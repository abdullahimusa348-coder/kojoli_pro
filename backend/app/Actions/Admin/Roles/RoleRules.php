<?php

namespace App\Actions\Admin\Roles;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Safety rules for role management, enforced in the actions so they hold even
 * for Super Admin (who skips policies through Gate::before):
 * - the Super Admin role is locked (always full access, cannot be edited or deleted);
 * - built-in role names are fixed and built-in roles cannot be deleted;
 * - staff other than Super Admin cannot edit the role they hold, and can only
 *   grant or remove permissions they hold themselves (no privilege escalation);
 * - a role still assigned to staff cannot be deleted.
 */
class RoleRules
{
    public function authorize(SystemUser $actor, SystemPermission $permission): void
    {
        if (! $actor->isActive() || ! $actor->can($permission->value)) {
            throw new AuthorizationException('You are not allowed to manage roles.');
        }
    }

    public function ensureEditable(Role $role): void
    {
        if ($role->name === SystemRole::SuperAdmin->value) {
            throw ValidationException::withMessages(['role' => 'The Super Admin role always has full access and cannot be changed.']);
        }
    }

    public function ensureNotOwnRole(SystemUser $actor, Role $role): void
    {
        if (! $actor->isSuperAdmin() && $actor->hasRole($role)) {
            throw ValidationException::withMessages(['role' => 'You cannot change a role you hold.']);
        }
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function ensureNoEscalation(SystemUser $actor, array $before, array $after): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $changed = array_merge(array_diff($after, $before), array_diff($before, $after));
        $notHeld = array_values(array_filter($changed, fn (string $p) => ! $actor->can($p)));

        if ($notHeld !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You can only grant or remove permissions you hold yourself: '.implode(', ', $notHeld).'.',
            ]);
        }
    }

    public function ensureDeletable(Role $role): void
    {
        if (SystemRole::isBuiltIn($role->name)) {
            throw ValidationException::withMessages(['role' => 'Built-in roles cannot be deleted.']);
        }

        if (SystemUser::withTrashed()->role($role->name, $role->guard_name)->exists()) {
            throw ValidationException::withMessages(['role' => 'This role is still assigned to staff. Move them to another role first.']);
        }
    }
}
