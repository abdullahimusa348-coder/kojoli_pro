<?php

namespace App\Http\Requests\Admin\Customers;

use App\Models\User;
use App\Support\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

/** Safe customer profile fields only; same rules as the customer's own profile form. */
class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalise($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $customer */
        $customer = $this->route('customer');

        return [
            'name' => AccountRules::name(),
            'email' => AccountRules::email($customer),
            'phone' => AccountRules::phone($customer),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08012345678.'];
    }
}
