<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Product '.fake()->unique()->numerify('#####');

        return [
            'service_id' => Service::factory(),
            'name' => $name,
            'code' => Str::slug($name),
            'network' => null,
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
