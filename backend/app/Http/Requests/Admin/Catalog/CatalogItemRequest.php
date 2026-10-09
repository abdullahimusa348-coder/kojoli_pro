<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Support\Catalog\CatalogIcon;
use App\Support\Catalog\CatalogSlug;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shared validation for categories and services. On create the slug is
 * generated from the name and must be unique in its table; on update the
 * slug is locked, so it is neither validated nor accepted. Any submitted
 * "slug" field is ignored (it is not in the validated data).
 */
abstract class CatalogItemRequest extends FormRequest
{
    /** Table whose slugs must be unique. */
    abstract protected function table(): string;

    abstract protected function existing(): ?Model;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->existing() === null;

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($creating) {
                if (! $creating || ! is_string($value)) {
                    return;
                }
                $slug = CatalogSlug::from($value);
                if ($slug === '') {
                    $fail('The name must contain letters or numbers.');
                } elseif (DB::table($this->table())->where('slug', $slug)->exists()) {
                    $fail("This name is already used (the slug “{$slug}” is taken).");
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', Rule::enum(CatalogIcon::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];

        if ($creating) {
            $rules['is_active'] = ['boolean'];
        }

        return $rules;
    }
}
