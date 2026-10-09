<?php

namespace Database\Factories;

use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only KYC submissions. Nothing in CP1 writes them.
 *
 * @extends Factory<KycSubmission>
 */
class KycSubmissionFactory extends Factory
{
    protected $model = KycSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => 'KYC-'.strtoupper((string) Str::ulid()),
            'submitted_at' => now(),
        ];
    }
}
