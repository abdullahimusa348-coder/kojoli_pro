<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ServiceRequest extends CatalogItemRequest
{
    protected function table(): string
    {
        return 'services';
    }

    protected function existing(): ?Model
    {
        $service = $this->route('service');

        return $service instanceof Service ? $service : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            // Every service belongs to an existing category.
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['category_id' => 'category'];
    }
}
