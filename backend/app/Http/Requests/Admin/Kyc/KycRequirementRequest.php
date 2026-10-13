<?php

namespace App\Http\Requests\Admin\Kyc;

use App\Support\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The edit form of one KYC requirement (Phase 13 CP1): its name (2 to 120
 * characters), an optional description (up to 500), on or off, the customer
 * types it applies to (at least one before it can be turned on), a reason of 10
 * to 500 characters, an explicit confirmation and the form's fingerprint (the
 * stale-form check).
 */
class KycRequirementRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $label = $this->input('label');
        $description = $this->input('description');

        $this->merge([
            'label' => is_string($label) ? trim($label) : $label,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'enabled' => $this->boolean('enabled'),
            'user_types' => $this->input('user_types') ?? [],
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
            'user_types' => ['array'],
            'user_types.*' => ['string', 'distinct', Rule::in(UserType::values())],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['accepted'],
            'fingerprint' => ['required', 'string', 'size:40'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->boolean('enabled') && $this->selectedTypes() === [] && ! $validator->errors()->has('user_types')) {
                $validator->errors()->add('user_types', 'Choose at least one customer type before turning this requirement on.');
            }
        }];
    }

    public function label(): string
    {
        return (string) $this->validated('label');
    }

    public function description(): ?string
    {
        return $this->validated('description');
    }

    public function enabled(): bool
    {
        return (bool) $this->validated('enabled');
    }

    /** @return list<string> */
    public function customerTypes(): array
    {
        return array_values((array) $this->validated('user_types'));
    }

    /** @return list<string> the customer types ticked on the form, before validation */
    private function selectedTypes(): array
    {
        return array_values((array) $this->input('user_types', []));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'Enter a name for this requirement.',
            'label.min' => 'Give the requirement a name of at least 2 characters.',
            'label.max' => 'Keep the name to 120 characters or fewer.',
            'description.max' => 'Keep the description to 500 characters or fewer.',
            'user_types.*.in' => 'Choose only the customer types shown.',
            'user_types.*.distinct' => 'Choose each customer type once.',
            'reason.required' => 'Give a reason for this change.',
            'reason.min' => 'Give a reason of at least 10 characters (kept in the change history).',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
            'confirm.accepted' => 'Confirm these settings before saving.',
            'fingerprint.*' => 'The form has expired. Reload the page and try again.',
        ];
    }
}
