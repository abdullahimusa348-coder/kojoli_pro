<?php

namespace App\Actions\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

class CreateSystemUser
{
    public function __construct(private SystemUserRules $rules) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, password: string, role: string, status?: string}  $data
     */
    public function handle(array $data, SystemUser $actor): SystemUser
    {
        $role = SystemRole::from($data['role']);

        $this->rules->authorize($actor);
        $this->rules->ensureCanTouch($actor, role: $role);

        return DB::transaction(function () use ($data, $role) {
            $staff = new SystemUser([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $staff->status = UserStatus::tryFrom($data['status'] ?? '') ?? UserStatus::Active;
            $staff->save();

            $staff->syncRoles([$role->value]);

            return $staff;
        });
    }
}
