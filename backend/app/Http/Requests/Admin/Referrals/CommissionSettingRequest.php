<?php

namespace App\Http\Requests\Admin\Referrals;

use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The edit form of one qualifying service's commission: the rate in percent
 * (0 to 99.99, up to 2 decimal places), the cap in naira (₦0 up to the
 * pricing.max_amount_kobo safety limit), a reason of 10 to 500 characters,
 * an explicit confirmation and the form's fingerprint (stale-form check).
 */
class CommissionSettingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rate' => ['bail', 'required', 'string', 'max:10', function (string $attribute, mixed $value, Closure $fail) {
                if (BasisPoints::parse($value) === null) {
                    $fail('Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).');
                }
            }],
            'cap' => ['bail', 'required', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail) {
                $kobo = KoboAmount::parse($value);
                $max = PricingLimits::maxAmountKobo();
                if ($kobo === null) {
                    $fail('Enter an amount in naira, e.g. 1,250.50.');
                } elseif ($kobo > $max) {
                    $fail('The cap may not be more than '.Money::format($max).' (system maximum).');
                }
            }],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['accepted'],
            'fingerprint' => ['required', 'string', 'size:40'],
        ];
    }

    public function rateBps(): int
    {
        return (int) BasisPoints::parse($this->validated('rate'));
    }

    public function capKobo(): int
    {
        return (int) KoboAmount::parse($this->validated('cap'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rate.required' => 'Enter a rate (use 0 for no commission).',
            'rate.max' => 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).',
            'cap.required' => 'Enter a cap in naira (use 0 for no commission).',
            'cap.max' => 'Enter an amount in naira, e.g. 1,250.50.',
            'reason.required' => 'Give a reason for this change.',
            'reason.min' => 'Give a reason of at least 10 characters (kept in the rate history).',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
            'confirm.accepted' => 'Confirm these values before saving.',
            'fingerprint.*' => 'The form has expired. Reload the page and try again.',
        ];
    }
}
