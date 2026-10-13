<?php

namespace App\Http\Requests\Admin\Kyc;

use App\Support\Kyc\KycStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A staff decision on a KYC submission (Phase 13 CP2b-1): one of the three decisions, the reason, the confirmation and the form's key.
 * The reason is trimmed as the ledger trims it, so padding never counts, and a reason that is not text is refused rather than dropped.
 * Only its shape and its maximum length are checked here. The ledger applies the rest (a rejection or a request for more information needs
 * 10 to 500 characters with a letter, an approval takes up to 500, and no reason may hold an eleven-digit number), so each rule lives in one
 * place. The confirmation is an explicit tick, as on the other admin forms that change permanent state. The reason is never flashed back
 * (bootstrap/app.php), so a number typed into it cannot reach the session. A validation failure goes back to the submission's own page.
 */
class KycDecisionRequest extends FormRequest
{
    /** The decisions a staff member can record: approved, rejected, or more information requested. */
    public const DECISIONS = [KycStatus::Approved, KycStatus::Rejected, KycStatus::MoreInfoRequested];

    private const INVALID_FORM = 'This form is not valid. Reload the page and try again.';

    protected function prepareForValidation(): void
    {
        // Only text is trimmed. A reason that is not text is left as it is, so the rules refuse it rather than dropping it.
        $reason = $this->input('decision_reason');

        if (is_string($reason)) {
            $trimmed = trim($reason);
            $this->merge(['decision_reason' => $trimmed === '' ? null : $trimmed]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(array_map(fn (KycStatus $status) => $status->value, self::DECISIONS))],
            'decision_reason' => ['nullable', 'string', 'max:500'],
            'form_key' => ['required', 'string', 'size:36', 'uuid'],
            'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Choose a decision.',
            'decision.string' => 'Choose a decision.',
            'decision.in' => 'Choose one of the decisions shown.',
            'decision_reason.string' => 'Enter the reason as text.',
            'decision_reason.max' => 'Keep the reason to 500 characters or fewer.',
            'form_key.required' => self::INVALID_FORM,
            'form_key.string' => self::INVALID_FORM,
            'form_key.size' => self::INVALID_FORM,
            'form_key.uuid' => self::INVALID_FORM,
            'confirm.accepted' => 'Confirm this decision before recording it.',
        ];
    }

    /**
     * A validation failure goes back to the submission's own page, which carries the form and shows its errors. The Referer header is not
     * used: the client sends it, so it is not a safe place to send the staff member back to.
     */
    protected function getRedirectUrl(): string
    {
        return route('admin.kyc.submissions.show', $this->route('submission'));
    }

    public function decision(): KycStatus
    {
        return KycStatus::from((string) $this->validated('decision'));
    }

    public function reason(): ?string
    {
        return $this->validated('decision_reason');
    }

    public function formKey(): string
    {
        return (string) $this->validated('form_key');
    }
}
