<?php

namespace App\Http\Requests\Auth;

use App\Services\Referrals\SignupReferrer;
use App\Support\Referrals\ReferralCodes;
use App\Support\Validation\AccountRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalise($this->all()));

        // An optional referral code (Phase 12): trimmed and upper-cased only, and kept as typed after a validation error.
        if (is_string($this->input('referral_code'))) {
            $this->merge(['referral_code' => ReferralCodes::normalise($this->input('referral_code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => AccountRules::name(),
            'email' => AccountRules::email(),
            'phone' => AccountRules::phone(),
            'password' => ['required', 'confirmed', Password::defaults()],
            // Every code that cannot be used gets the same answer, whatever the reason. Checked again under a lock
            // when the account is created (RegisterUser).
            'referral_code' => ['nullable', function (string $attribute, mixed $value, Closure $fail) {
                if (SignupReferrer::find($value) === null) {
                    $fail(SignupReferrer::INVALID);
                }
            }],
        ];
    }

    /** The validated referral code (upper-case), or null when none was given. */
    public function referralCode(): ?string
    {
        return $this->validated('referral_code');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08012345678.'];
    }
}
