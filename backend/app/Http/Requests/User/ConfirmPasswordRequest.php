<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Current-password confirmation for the Security page's "all" actions
 * (log out all other browsers, revoke all apps). Errors go to a bag named
 * after the action so each form shows its own message.
 */
class ConfirmPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->routeIs('security.tokens.destroy-all') ? 'revokeTokens' : 'logoutOthers';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'current_password:web']];
    }
}
