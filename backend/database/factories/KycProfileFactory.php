<?php

namespace Database\Factories;

use App\Models\KycProfile;
use App\Models\User;
use App\Support\Kyc\KycStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only KYC status rows. Nothing in CP1 writes them.
 *
 * @extends Factory<KycProfile>
 */
class KycProfileFactory extends Factory
{
    protected $model = KycProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => KycStatus::NotStarted->value,
            'status_changed_at' => null,
        ];
    }

    public function status(KycStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value, 'status_changed_at' => now()]);
    }
}
