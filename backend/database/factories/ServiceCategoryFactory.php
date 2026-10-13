<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Category '.fake()->unique()->numerify('####');

        return [
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
