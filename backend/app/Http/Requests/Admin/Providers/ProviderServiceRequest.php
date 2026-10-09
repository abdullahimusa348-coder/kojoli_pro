<?php

namespace App\Http\Requests\Admin\Providers;

use App\Models\ProviderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adds or edits a provider capability (supported catalog service). */
class ProviderServiceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'requires_plan_code' => ['boolean'],
            'provider_service_code' => ['nullable', 'string', 'max:100', 'regex:/^[\x21-\x7E]+(?: [\x21-\x7E]+)*$/'],
        ];

        if (! $this->route('capability') instanceof ProviderService) {
            $rules['service_id'] = ['required', 'integer', Rule::exists('services', 'id'),
                Rule::unique('provider_services', 'service_id')->where('provider_id', $this->route('provider')->id)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'service_id.unique' => 'This provider already has this service.',
            'provider_service_code.regex' => 'Use visible characters only (single spaces allowed between them).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['service_id' => 'service', 'provider_service_code' => 'provider service code'];
    }
}
