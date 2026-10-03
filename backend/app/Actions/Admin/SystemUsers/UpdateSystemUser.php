<?php

namespace App\Actions\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Updates profile details, role and (optionally) password. Status has its own action. */
class UpdateSystemUser
{
    public function __construct(private SystemUserRules $rules) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, role: string, password?: ?string}  $data
     */
    public function handle(SystemUser $staff, array $data, SystemUser $actor): SystemUser
    {
        $role = SystemRole::from($data['role']);
        $currentRole = $staff->primaryRole();

        $this->rules->authorize($actor);
        $this->rules->ensureCanTouch($actor, $staff, $role);

        if ($role !== $currentRole) {
            if ($actor->is($staff)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }
            if ($currentRole === SystemRole::SuperAdmin) {
                $this->rules->ensureNotLastActiveSuperAdmin($staff, 'This is the only active Super Admin; their role cannot be changed.');
            }
        }

        return DB::transaction(function () use ($staff, $data, $role) {
            $staff->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            if (! empty($data['password'])) {
                $staff->password = $data['password'];
                // Ends "remember me" logins that used the old password.
                $staff->remember_token = Str::random(60);
            }

            $staff->save();
            $staff->syncRoles([$role->value]);

            return $staff;
        });
    }
}
