<?php

namespace App\Actions\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Enums\UserStatus;

/**
 * Activates or deactivates a staff account. A deactivated account cannot
 * sign in, and an open admin session ends on its next request.
 */
class ChangeSystemUserStatus
{
    public function __construct(private SystemUserRules $rules) {}

    public function handle(SystemUser $staff, UserStatus $status, SystemUser $actor): SystemUser
    {
        $this->rules->authorize($actor);
        $this->rules->ensureCanTouch($actor, $staff);

        if ($status === UserStatus::Disabled) {
            $this->rules->ensureNotSelf($actor, $staff, 'You cannot deactivate your own account.');
            $this->rules->ensureNotLastActiveSuperAdmin($staff, 'This is the only active Super Admin and cannot be deactivated.');
        }

        $staff->status = $status;
        $staff->save();

        return $staff;
    }
}
