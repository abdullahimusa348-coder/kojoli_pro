<?php

namespace Database\Factories;

use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<SystemUser>
 */
class SystemUserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Disabled]);
    }

    /** Requires roles to exist (RolesAndPermissionsSeeder). */
    public function withRole(SystemRole $role): static
    {
        return $this->afterCreating(fn (SystemUser $user) => $user->assignRole($role->value));
    }
}
