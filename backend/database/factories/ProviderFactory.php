<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only providers. No providers are seeded.
 *
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Provider '.fake()->unique()->numerify('####');

        return [
            'name' => $name,
            'code' => Str::slug($name),
            'description' => null,
            'status' => 'active',
            'driver' => null,
            'settings' => null,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
