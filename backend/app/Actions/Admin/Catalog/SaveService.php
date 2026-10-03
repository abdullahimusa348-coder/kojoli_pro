<?php

namespace App\Actions\Admin\Catalog;

use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Catalog\CatalogSlug;
use App\Support\Enums\SystemPermission;

/**
 * Creates or updates a catalog service (catalog details only; no plans,
 * pricing or providers). The slug is generated from the name on create and
 * never changed on update. Active state has its own action.
 */
class SaveService
{
    public function __construct(private CatalogRules $rules) {}

    /** @param  array{category_id: int, name: string, description?: ?string, icon?: ?string, sort_order?: ?int, is_active?: bool}  $data */
    public function create(array $data, SystemUser $actor): Service
    {
        $this->rules->authorize($actor, SystemPermission::ServicesCreate);

        $service = new Service($this->attributes($data));
        $service->slug = CatalogSlug::from($data['name']);
        $service->is_active = (bool) ($data['is_active'] ?? false);
        $service->save();

        return $service;
    }

    /** @param  array{category_id: int, name: string, description?: ?string, icon?: ?string, sort_order?: ?int}  $data */
    public function update(Service $service, array $data, SystemUser $actor): Service
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $service->fill($this->attributes($data))->save();

        return $service;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'category_id' => (int) $data['category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
