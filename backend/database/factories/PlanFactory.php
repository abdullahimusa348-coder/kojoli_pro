<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Plan '.fake()->unique()->numerify('#####');

        return [
            'product_id' => Product::factory(),
            'name' => $name,
            'code' => Str::slug($name),
            'amount_type' => 'fixed',
            'validity_period' => null,
            'validity_days' => null,
            'data_volume_mb' => null,
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
