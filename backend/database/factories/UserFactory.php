<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '080'.fake()->unique()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => UserType::Subscriber,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UserStatus::Disabled]);
    }

    public function ofType(UserType $type): static
    {
        return $this->state(fn (array $attributes) => ['user_type' => $type]);
    }
}
