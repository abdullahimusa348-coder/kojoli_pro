<?php

namespace App\Actions\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Safety rules shared by the System User actions. They live here, not in a
 * policy, because Gate::before lets Super Admin skip policies, and these
 * rules must hold for Super Admin too (e.g. never lock everyone out).
 */
class SystemUserRules
{
    /** Actor must be active and hold system-users.manage. */
    public function authorize(SystemUser $actor): void
    {
        if (! $actor->isActive() || ! $actor->can(SystemPermission::SystemUsersManage->value)) {
            throw new AuthorizationException('You are not allowed to manage system users.');
        }
    }

    /** Only a Super Admin may grant the Super Admin role or change a Super Admin account. */
    public function ensureCanTouch(SystemUser $actor, ?SystemUser $target = null, ?SystemRole $role = null): void
    {
        $involvesSuperAdmin = $role === SystemRole::SuperAdmin || ($target?->isSuperAdmin() ?? false);

        if ($involvesSuperAdmin && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can manage Super Admin accounts.');
        }
    }

    public function ensureNotSelf(SystemUser $actor, SystemUser $target, string $message): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages(['system_user' => $message]);
        }
    }

    /** Refuse anything that would leave no active Super Admin. */
    public function ensureNotLastActiveSuperAdmin(SystemUser $target, string $message): void
    {
        if (! $target->isSuperAdmin() || ! $target->isActive()) {
            return;
        }

        $others = SystemUser::role(SystemRole::SuperAdmin->value, 'admin')
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($target->getKey())
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages(['system_user' => $message]);
        }
    }
}
