<?php

namespace App\Http\Requests\Admin\SystemUsers;

use App\Models\SystemUser;
use App\Support\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Shared validation for creating and editing staff accounts. */
abstract class SystemUserRequest extends FormRequest
{
    abstract protected function passwordRequired(): bool;

    protected function target(): ?SystemUser
    {
        $staff = $this->route('systemUser');

        return $staff instanceof SystemUser ? $staff : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AccountRules::normalise($this->all()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $ignore = $this->target()?->getKey();

        return [
            'name' => ['required', 'string', 'max:255'],
            // Deleted staff keep their email reserved (unique across all rows).
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(SystemUser::class, 'email')->ignore($ignore)],
            'phone' => ['nullable', 'string', 'regex:'.AccountRules::PHONE_REGEX, Rule::unique(SystemUser::class, 'phone')->ignore($ignore)],
            // Any staff role (built-in or custom) on the admin guard.
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'admin')],
            'password' => [$this->passwordRequired() ? 'required' : 'nullable', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08012345678.'];
    }
}
