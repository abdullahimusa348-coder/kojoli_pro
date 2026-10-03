<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Plan;
use App\Models\Product;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\ValidityPeriod;
use App\Support\Money;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan fields (structure only: no price, cost or provider fields exist).
 * On create the code is generated from the product code and the name and
 * must be unique; on update the code is locked. Variable-amount plans have
 * no fixed data volume. Face-value limits (min/max amount, typed in naira,
 * stored in kobo) are for variable plans only. The amount type cannot change
 * once the plan has prices.
 */
class PlanRequest extends FormRequest
{
    private function creating(): bool
    {
        return ! $this->route('plan') instanceof Plan;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'name' => ['required', 'string', 'min:2', 'max:150', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->creating() || ! is_string($value)) {
                    return;
                }
                $product = Product::find($this->input('product_id'));
                if ($product === null) {
                    return; // product_id error is reported separately
                }
                $code = CatalogSlug::planCode($product->code, $value);
                if ($code === '') {
                    $fail('The name must contain letters or numbers.');
                } elseif (Plan::where('code', $code)->exists()) {
                    $fail("This name is already used in this product (the code “{$code}” is taken).");
                }
            }],
            'amount_type' => ['required', Rule::enum(AmountType::class), function (string $attribute, mixed $value, Closure $fail) {
                $plan = $this->route('plan');
                if ($plan instanceof Plan && $plan->amount_type->value !== $value && $plan->prices()->exists()) {
                    $fail('The amount type cannot be changed because this plan already has prices.');
                }
            }],
            'validity_period' => ['nullable', Rule::enum(ValidityPeriod::class)],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'data_volume_mb' => ['nullable', 'integer', 'min:1', 'max:10000000', 'prohibited_if:amount_type,variable'],
            'min_amount' => ['nullable', 'prohibited_if:amount_type,fixed', 'required_with:max_amount', $this->amountRule()],
            'max_amount' => ['nullable', 'prohibited_if:amount_type,fixed', 'required_with:min_amount', $this->amountRule(), function (string $attribute, mixed $value, Closure $fail) {
                $min = KoboAmount::parse($this->input('min_amount'));
                $max = KoboAmount::parse($value);
                if ($min !== null && $max !== null && $max < $min) {
                    $fail('The maximum amount must be at least the minimum amount.');
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];

        if ($this->creating()) {
            $rules['is_active'] = ['boolean'];
        }

        return $rules;
    }

    /** Naira amount between ₦0.01 and the configured system maximum. */
    private function amountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $kobo = KoboAmount::parse($value);
            $max = PricingLimits::maxAmountKobo();
            if ($kobo === null) {
                $fail('The :attribute must be an amount in naira, e.g. 1,250.50.');
            } elseif ($kobo < 1) {
                $fail('The :attribute must be at least ₦0.01.');
            } elseif ($kobo > $max) {
                $fail('The :attribute may not be more than '.Money::format($max).' (system maximum).');
            }
        };
    }

    /** Validated data with the face-value limits converted to kobo. @return array<string, mixed> */
    public function planData(): array
    {
        $data = $this->safe()->except(['min_amount', 'max_amount']);
        $variable = $this->input('amount_type') === AmountType::Variable->value;
        $data['min_amount_kobo'] = $variable ? KoboAmount::parse($this->input('min_amount')) : null;
        $data['max_amount_kobo'] = $variable ? KoboAmount::parse($this->input('max_amount')) : null;

        return $data;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['product_id' => 'product', 'data_volume_mb' => 'data volume', 'validity_days' => 'validity days',
            'min_amount' => 'minimum amount', 'max_amount' => 'maximum amount'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'data_volume_mb.prohibited_if' => 'Variable-amount plans cannot have a fixed data volume.',
            'min_amount.prohibited_if' => 'Fixed plans do not use amount limits.',
            'max_amount.prohibited_if' => 'Fixed plans do not use amount limits.',
        ];
    }
}
