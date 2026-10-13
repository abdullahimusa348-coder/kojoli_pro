<?php

namespace App\Http\Requests\Admin\Roles;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Role name + permission list. Built-in role names are fixed, so the name is
 * only validated for new or custom roles. Permissions must be catalog entries.
 */
class RoleRequest extends FormRequest
{
    protected function target(): ?Role
    {
        $role = $this->route('adminRole');

        return $role instanceof Role ? $role : null;
    }

    public function namesEditable(): bool
    {
        return $this->target() === null || ! SystemRole::isBuiltIn($this->target()->name);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? preg_replace('/\s+/', ' ', trim($this->input('name'))) : $this->input('name'),
            'permissions' => $this->input('permissions', []),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(SystemPermission::values())],
        ];

        if ($this->namesEditable()) {
            $rules['name'] = [
                'required', 'string', 'min:3', 'max:50',
                'regex:/^[A-Za-z0-9][A-Za-z0-9 &\-]*$/',
                function (string $attribute, mixed $value, Closure $fail) {
                    $reserved = array_map('mb_strtolower', [...SystemRole::values(), ...array_map(fn (SystemRole $r) => $r->label(), SystemRole::cases())]);
                    if (in_array(mb_strtolower((string) $value), $reserved, true)) {
                        $fail('That name is reserved for a built-in role.');
                    }
                },
                Rule::unique('roles', 'name')->where('guard_name', 'admin')->ignore($this->target()?->getKey()),
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Use letters, numbers, spaces, "&" or "-" only.',
            'permissions.*.in' => 'One of the selected permissions does not exist.',
        ];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_values($this->validated('permissions', []));
    }

    public function roleName(): ?string
    {
        return $this->namesEditable() ? $this->validated('name') : null;
    }
}
