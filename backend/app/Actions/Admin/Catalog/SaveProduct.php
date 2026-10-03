<?php

namespace App\Actions\Admin\Catalog;

use App\Models\Product;
use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Catalog\CatalogSlug;
use App\Support\Enums\SystemPermission;

/**
 * Creates or updates a product (structure only: no prices or providers).
 * The code is generated on create and never changed, even if the product is
 * renamed or moved to another service. Active state has its own action.
 */
class SaveProduct
{
    public function __construct(private CatalogRules $rules) {}

    /** @param  array{service_id: int, name: string, network?: ?string, description?: ?string, sort_order?: ?int, is_active?: bool}  $data */
    public function create(array $data, SystemUser $actor): Product
    {
        $this->rules->authorize($actor, SystemPermission::ServicesCreate);

        $service = Service::findOrFail($data['service_id']);
        $product = new Product($this->attributes($data));
        $product->code = CatalogSlug::productCode($service->slug, $data['name']);
        $product->is_active = (bool) ($data['is_active'] ?? false);
        $product->save();

        return $product;
    }

    /** @param  array{service_id: int, name: string, network?: ?string, description?: ?string, sort_order?: ?int}  $data */
    public function update(Product $product, array $data, SystemUser $actor): Product
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $product->fill($this->attributes($data))->save();

        return $product;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'service_id' => (int) $data['service_id'],
            'name' => $data['name'],
            'network' => $data['network'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
