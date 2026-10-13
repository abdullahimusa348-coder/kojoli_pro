<?php

namespace Database\Factories;

use App\Models\KycRequirement;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycRequirementType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only KYC requirements. The application creates the four real ones with
 * KycRequirementsSeeder; nothing outside the tests uses this factory.
 *
 * @extends Factory<KycRequirement>
 */
class KycRequirementFactory extends Factory
{
    protected $model = KycRequirement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'doc-'.fake()->unique()->numerify('####'),
            'type' => KycRequirementType::Document->value,
            'label' => 'Test requirement '.fake()->unique()->numerify('####'),
            'description' => null,
            'is_enabled' => false,
            'user_types' => [],
            'purposes' => [],
            'position' => fake()->unique()->numberBetween(100, 9999),
        ];
    }

    public function ofType(KycRequirementType $type): static
    {
        return $this->state(fn () => ['key' => $type->value.'-'.fake()->unique()->numerify('####'), 'type' => $type->value]);
    }

    /** Turned on for the given customer types, listed in the usual order. */
    public function enabledFor(UserType ...$types): static
    {
        $values = array_map(fn (UserType $type) => $type->value, $types);

        return $this->state(fn () => ['is_enabled' => true, 'user_types' => array_values(array_intersect(UserType::values(), $values))]);
    }
}
