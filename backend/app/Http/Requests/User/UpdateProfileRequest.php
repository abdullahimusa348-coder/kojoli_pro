<?php

namespace App\Http\Requests\User;

use App\Support\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
            'email' => AccountRules::email($this->user()),
            'phone' => AccountRules::phone($this->user()),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08012345678.'];
    }
}
