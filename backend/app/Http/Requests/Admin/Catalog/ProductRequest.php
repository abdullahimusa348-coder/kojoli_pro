<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Product;
use App\Models\Service;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\Network;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Product fields. On create the code is generated from the service slug and
 * the name and must be unique; on update the code is locked (any submitted
 * "code" is ignored). Network comes from the fixed list.
 */
class ProductRequest extends FormRequest
{
    private function creating(): bool
    {
        return ! $this->route('product') instanceof Product;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'name' => ['required', 'string', 'min:2', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->creating() || ! is_string($value)) {
                    return;
                }
                $service = Service::find($this->input('service_id'));
                if ($service === null) {
                    return; // service_id error is reported separately
                }
                $code = CatalogSlug::productCode($service->slug, $value);
                if ($code === '') {
                    $fail('The name must contain letters or numbers.');
                } elseif (Product::where('code', $code)->exists()) {
                    $fail("This name is already used in this service (the code “{$code}” is taken).");
                }
            }],
            'network' => ['nullable', Rule::enum(Network::class)],
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
        return ['service_id' => 'service'];
    }
}
