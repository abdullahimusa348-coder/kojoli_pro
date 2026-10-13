<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Model;

class CategoryRequest extends CatalogItemRequest
{
    protected function table(): string
    {
        return 'service_categories';
    }

    protected function existing(): ?Model
    {
        $category = $this->route('category');

        return $category instanceof ServiceCategory ? $category : null;
    }
}
