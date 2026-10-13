<?php

namespace App\Actions\Admin\Catalog;

use App\Models\ServiceCategory;
use App\Models\SystemUser;
use App\Support\Catalog\CatalogSlug;
use App\Support\Enums\SystemPermission;

/**
 * Creates or updates a category. The slug is generated from the name on
 * create and never changed on update. Active state has its own action.
 */
class SaveCategory
{
    public function __construct(private CatalogRules $rules) {}

    /** @param  array{name: string, description?: ?string, icon?: ?string, sort_order?: ?int, is_active?: bool}  $data */
    public function create(array $data, SystemUser $actor): ServiceCategory
    {
        $this->rules->authorize($actor, SystemPermission::ServicesCreate);

        $category = new ServiceCategory($this->attributes($data));
        $category->slug = CatalogSlug::from($data['name']);
        $category->is_active = (bool) ($data['is_active'] ?? false);
        $category->save();

        return $category;
    }

    /** @param  array{name: string, description?: ?string, icon?: ?string, sort_order?: ?int}  $data */
    public function update(ServiceCategory $category, array $data, SystemUser $actor): ServiceCategory
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $category->fill($this->attributes($data))->save();

        return $category;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
