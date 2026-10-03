<?php

namespace App\Http\Requests\Admin\Wallet;

use Illuminate\Foundation\Http\FormRequest;

/** Reversal of a manual adjustment: internal reason, explicit confirmation and one-time token. */
class ReverseAdjustmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reversal_reason' => ['required', 'string', 'min:10', 'max:500'],
            'reversal_confirm' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reversal_confirm.accepted' => 'Confirm the reversal before saving.',
            'reversal_reason.required' => 'Give a reason for the reversal.',
            'reversal_reason.min' => 'Give a reason of at least 10 characters (kept as an internal note).',
            'idempotency_key.*' => 'The form has expired. Reload the page and try again.',
        ];
    }
}
