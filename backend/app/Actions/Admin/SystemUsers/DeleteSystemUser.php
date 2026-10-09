<?php

namespace App\Actions\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

/**
 * Removes a staff account from the admin area. The row is soft deleted (kept
 * for history, e.g. settings.updated_by) and set to disabled, so it can never
 * sign in again. Its email stays reserved.
 */
class DeleteSystemUser
{
    public function __construct(private SystemUserRules $rules) {}

    public function handle(SystemUser $staff, SystemUser $actor): void
    {
        $this->rules->authorize($actor);
        $this->rules->ensureCanTouch($actor, $staff);
        $this->rules->ensureNotSelf($actor, $staff, 'You cannot delete your own account.');
        $this->rules->ensureNotLastActiveSuperAdmin($staff, 'This is the only active Super Admin and cannot be deleted.');

        DB::transaction(function () use ($staff) {
            $staff->status = UserStatus::Disabled;
            $staff->save();
            $staff->delete();
        });
    }
}
