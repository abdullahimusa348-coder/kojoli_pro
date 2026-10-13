<?php

namespace App\Http\Requests\Admin\Providers;

use App\Support\Providers\CredentialKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Write-only credential form: credentials[<key>] for known keys only. Blank
 * fields keep the stored value. Messages never include the submitted value,
 * and the "credentials" input is excluded from old input (bootstrap/app.php).
 */
class ProviderCredentialsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['credentials' => ['required', 'array:'.implode(',', CredentialKey::values())]];
        foreach (CredentialKey::values() as $key) {
            $rules["credentials.$key"] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'credentials.required' => 'Enter at least one credential value.',
            'credentials.array' => 'Unknown credential field.',
            'credentials.*.string' => 'Credential values must be text.',
            'credentials.*.max' => 'Credential values may not be longer than 2000 characters.',
        ];
    }

    /** @return array<string, ?string> */
    public function values(): array
    {
        return (array) $this->validated('credentials');
    }
}
