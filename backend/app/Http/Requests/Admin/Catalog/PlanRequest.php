<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Plan;
use App\Models\Product;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\ValidityPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan fields (structure only: no price, cost or provider fields exist).
 * On create the code is generated from the product code and the name and
 * must be unique; on update the code is locked. Variable-amount plans have
 * no fixed data volume.
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
            'amount_type' => ['required', Rule::enum(AmountType::class)],
            'validity_period' => ['nullable', Rule::enum(ValidityPeriod::class)],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'data_volume_mb' => ['nullable', 'integer', 'min:1', 'max:10000000', 'prohibited_if:amount_type,variable'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];

        if ($this->creating()) {
            $rules['is_active'] = ['boolean'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['product_id' => 'product', 'data_volume_mb' => 'data volume', 'validity_days' => 'validity days'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['data_volume_mb.prohibited_if' => 'Variable-amount plans cannot have a fixed data volume.'];
    }
}
