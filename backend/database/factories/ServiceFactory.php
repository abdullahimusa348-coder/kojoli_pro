<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Service '.fake()->unique()->numerify('####');

        return [
            'category_id' => ServiceCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'icon' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
