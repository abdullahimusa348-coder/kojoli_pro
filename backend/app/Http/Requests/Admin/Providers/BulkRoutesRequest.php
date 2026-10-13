<?php

namespace App\Http\Requests\Admin\Providers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk helper: add this provider at one priority to every plan of a product. */
class BulkRoutesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['product_id' => 'product'];
    }
}
