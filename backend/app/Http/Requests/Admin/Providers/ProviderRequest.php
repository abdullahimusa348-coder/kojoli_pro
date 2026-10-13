<?php

namespace App\Http\Requests\Admin\Providers;

use App\Actions\Admin\Providers\SaveProvider;
use App\Models\Provider;
use App\Support\Providers\CredentialKey;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Provider details and non-secret settings. The code is generated from the
 * name on create (must be unique) and locked afterwards. No credential
 * values are accepted here.
 */
class ProviderRequest extends FormRequest
{
    private function creating(): bool
    {
        return ! $this->route('provider') instanceof Provider;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->creating() || ! is_string($value)) {
                    return;
                }
                $code = SaveProvider::codeFor($value);
                if ($code === '') {
                    $fail('The name must contain letters or numbers.');
                } elseif (Provider::where('code', $code)->exists()) {
                    $fail("This name is already used (the code “{$code}” is taken).");
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'driver' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'base_url' => ['nullable', 'string', 'max:255', 'url:https'],
            'required_credentials' => ['nullable', 'array'],
            'required_credentials.*' => ['string', Rule::in(CredentialKey::values())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'driver.regex' => 'The driver must be a lowercase identifier such as "example_driver" (letters, numbers, underscores).',
            'base_url.url' => 'The base URL must be a valid https:// address.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['base_url' => 'base URL', 'required_credentials.*' => 'required credential'];
    }
}
