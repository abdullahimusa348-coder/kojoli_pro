<?php

namespace App\Http\Requests\Auth;

use App\Support\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalise($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => AccountRules::name(),
            'email' => AccountRules::email(),
            'phone' => AccountRules::phone(),
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08012345678.'];
    }
}
