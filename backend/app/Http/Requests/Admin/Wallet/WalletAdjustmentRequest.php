<?php

namespace App\Http\Requests\Admin\Wallet;

use App\Support\Money;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use App\Support\Wallet\Direction;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual credit/debit form: direction, amount in naira (₦0.01 up to the
 * pricing.max_amount_kobo safety limit), a reason of at least 10 characters,
 * an explicit confirmation and the one-time form token (idempotency key).
 */
class WalletAdjustmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(Direction::class)],
            'amount' => ['required', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
                $kobo = KoboAmount::parse($value);
                $max = PricingLimits::maxAmountKobo();
                if ($kobo === null) {
                    $fail('Enter an amount in naira, e.g. 1,250.50.');
                } elseif ($kobo < 1) {
                    $fail('The amount must be at least ₦0.01.');
                } elseif ($kobo > $max) {
                    $fail('The amount may not be more than '.Money::format($max).' (system maximum).');
                }
            }],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function amountKobo(): int
    {
        return (int) KoboAmount::parse($this->validated('amount'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Confirm this adjustment before saving.',
            'reason.min' => 'Give a reason of at least 10 characters (kept as an internal note).',
            'idempotency_key.*' => 'The form has expired. Reload the page and try again.',
        ];
    }
}
