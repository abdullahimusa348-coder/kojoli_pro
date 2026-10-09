<?php

namespace App\Http\Requests\User;

use App\Support\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer's own Account details. Customers may change their name and email
 * only. The phone number is locked until SMS verification exists (Phase 15):
 * any submitted phone is rejected. Account type and status are never accepted.
 * Staff change these through the admin Users area instead.
 */
class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalise($this->only('email')));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => AccountRules::name(),
            'email' => AccountRules::email($this->user()),
            // Reject any phone sent; "exclude" also keeps an empty value out of validated() data.
            'phone' => ['prohibited', 'exclude'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.prohibited' => 'Your phone number cannot be changed here yet. Phone changes will need SMS verification.'];
    }
}
