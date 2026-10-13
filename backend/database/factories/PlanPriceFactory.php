<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only prices. Nothing seeds real prices.
 *
 * @extends Factory<PlanPrice>
 */
class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'user_type' => UserType::Subscriber,
            'price_kobo' => 10_000,
            'discount_bps' => null,
            'fee_kobo' => null,
            'is_active' => true,
        ];
    }

    public function variable(int $discountBps = 0, int $feeKobo = 0): static
    {
        return $this->state(fn () => ['price_kobo' => null, 'discount_bps' => $discountBps, 'fee_kobo' => $feeKobo]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
