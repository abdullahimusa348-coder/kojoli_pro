<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Illuminate\Auth\Events\Registered;

/**
 * Creates a customer account. Shared by web registration and (later) the mobile API.
 * Public sign-ups are always Subscribers; other tiers are assigned by an admin.
 */
class RegisterUser
{
    /**
     * @param  array{name: string, email: string, phone: string, password: string}  $data
     */
    public function handle(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
        ]);
        $user->user_type = UserType::Subscriber;
        $user->status = UserStatus::Active;
        $user->save();

        event(new Registered($user));

        return $user;
    }
}
