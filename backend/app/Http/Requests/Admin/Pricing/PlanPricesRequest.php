<?php

namespace App\Http\Requests\Admin\Pricing;

use App\Models\Plan;
use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The edit-prices form: one row per customer type, posted as
 * prices[<type>][price] (fixed plans, naira) or prices[<type>][discount]
 * (percent) and prices[<type>][fee] (naira) for variable plans. A type left
 * blank stays unpriced; a type that already has a price cannot be blanked
 * (disable it instead). Requires an explicit confirmation.
 */
class PlanPricesRequest extends FormRequest
{
    private function plan(): Plan
    {
        return $this->route('plan');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'prices' => ['nullable', 'array'],
            'confirm' => ['accepted'],
            'fingerprint' => ['required', 'string', 'size:40'],
        ];

        foreach (UserType::values() as $type) {
            if ($this->plan()->isVariable()) {
                $rules["prices.$type.price"] = ['prohibited'];
                $rules["prices.$type.discount"] = ['nullable', "required_with:prices.$type.fee", $this->percentRule()];
                $rules["prices.$type.fee"] = ['nullable', "required_with:prices.$type.discount", $this->amountRule(0)];
            } else {
                $rules["prices.$type.price"] = ['nullable', $this->amountRule(1)];
                $rules["prices.$type.discount"] = ['prohibited'];
                $rules["prices.$type.fee"] = ['prohibited'];
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $plan = $this->plan();
            $existing = $plan->prices()->pluck('user_type')->map(fn (UserType $t) => $t->value)->all();
            $values = $this->priceValues();

            foreach (UserType::cases() as $type) {
                if (in_array($type->value, $existing, true) && ! array_key_exists($type->value, $values)) {
                    $field = $plan->isVariable() ? 'discount' : 'price';
                    $validator->errors()->add("prices.{$type->value}.$field", "{$type->label()} already has a price. Enter a value, or disable it instead.");
                }
            }

            if ($plan->isVariable() && $values !== [] && ($plan->min_amount_kobo === null || $plan->max_amount_kobo === null)) {
                $validator->errors()->add('prices', 'Set the minimum and maximum amount on the plan before pricing it.');
            }
        }];
    }

    private function amountRule(int $min): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min) {
            $kobo = KoboAmount::parse($value);
            $max = PricingLimits::maxAmountKobo();
            if ($kobo === null) {
                $fail('Enter an amount in naira, e.g. 1,250.50.');
            } elseif ($kobo < $min) {
                $fail('The amount must be at least '.Money::format($min).'.');
            } elseif ($kobo > $max) {
                $fail('The amount may not be more than '.Money::format($max).' (system maximum).');
            }
        };
    }

    private function percentRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (BasisPoints::parse($value) === null) {
                $fail('Enter a discount from 0 to 99.99 (percent, up to 2 decimal places).');
            }
        };
    }

    /**
     * Entered values in kobo / basis points, keyed by customer type; types left blank are omitted.
     *
     * @return array<string, array{price_kobo: ?int, discount_bps: ?int, fee_kobo: ?int}>
     */
    public function priceValues(): array
    {
        $input = (array) $this->input('prices', []);
        $values = [];

        foreach (UserType::values() as $type) {
            $row = (array) ($input[$type] ?? []);
            if ($this->plan()->isVariable()) {
                if (($row['discount'] ?? null) === null && ($row['fee'] ?? null) === null) {
                    continue;
                }
                $values[$type] = ['price_kobo' => null, 'discount_bps' => BasisPoints::parse($row['discount'] ?? null), 'fee_kobo' => KoboAmount::parse($row['fee'] ?? null)];
            } else {
                if (($row['price'] ?? null) === null) {
                    continue;
                }
                $values[$type] = ['price_kobo' => KoboAmount::parse($row['price']), 'discount_bps' => null, 'fee_kobo' => null];
            }
        }

        return $values;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Confirm that these prices are correct before saving.',
            'prices.*.price.prohibited' => 'Variable-amount plans use a discount and fee, not a fixed price.',
            'prices.*.discount.prohibited' => 'Fixed plans use a fixed price, not a discount.',
            'prices.*.fee.prohibited' => 'Fixed plans use a fixed price, not a fee.',
            'prices.*.discount.required_with' => 'Enter both a discount and a fee (use 0 for none).',
            'prices.*.fee.required_with' => 'Enter both a discount and a fee (use 0 for none).',
        ];
    }
}
