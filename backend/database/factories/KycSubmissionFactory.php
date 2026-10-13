<?php

namespace Database\Factories;

use App\Models\KycSubmission;
use App\Models\User;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-only KYC submission rows, written directly (no requirement snapshot, identity value or review). Production code writes
 * submissions through KycSubmissionLedger only.
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
            'kind' => KycSubmissionKind::Initial->value,
            'user_type' => UserType::Subscriber->value,
            'form_key' => (string) Str::uuid(),
            'submitted_at' => now(),
        ];
    }

    /** The customer's own type, as the submission records it. */
    public function forCustomer(User $customer): static
    {
        return $this->state(fn () => ['user_id' => $customer->id, 'user_type' => $customer->user_type->value]);
    }
}
