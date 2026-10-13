<?php

namespace App\Http\Requests\Admin\Referrals;

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Support\Referrals\CommissionActionType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A commission's Reverse or Cancel form: the reason (10 to 500 characters,
 * kept permanently and shown to staff only), an explicit confirmation and the
 * form's one-time token. Which action it is comes from the route; the token
 * itself is checked by ActOnCommission. Each form keeps its own errors, as
 * both are on the commission page.
 */
class CommissionActionRequest extends FormRequest
{
    public function actionType(): CommissionActionType
    {
        return $this->routeIs('admin.referrals.commissions.cancel') ? CommissionActionType::Cancellation : CommissionActionType::Reversal;
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->actionType()->value;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['accepted'],
            'token' => ['required', 'string', 'max:4096'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for this action.',
            'reason.string' => 'Give the reason for this action.',
            'reason.min' => 'Give a reason of at least 10 characters (kept permanently, shown to staff only).',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
            'confirm.accepted' => 'Confirm this action before submitting it.',
            'token.*' => ActOnCommission::INVALID_FORM,
        ];
    }
}
