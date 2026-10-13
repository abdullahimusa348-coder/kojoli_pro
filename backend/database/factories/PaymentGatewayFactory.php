<?php

namespace Database\Factories;

use App\Models\PaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only gateway records. No gateways are seeded.
 *
 * @extends Factory<PaymentGateway>
 */
class PaymentGatewayFactory extends Factory
{
    protected $model = PaymentGateway::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Gateway '.fake()->unique()->numerify('####');

        return [
            'name' => $name,
            'code' => Str::slug($name),
            'driver' => 'fake',
            'status' => 'active',
            'mode' => 'sandbox',
            'priority' => fake()->unique()->numberBetween(1, 60000),
            'wallet_funding' => true,
            'settings' => null,
        ];
    }
}
