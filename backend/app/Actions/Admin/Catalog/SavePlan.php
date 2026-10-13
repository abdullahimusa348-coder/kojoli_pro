<?php

namespace App\Actions\Admin\Catalog;

use App\Models\Plan;
use App\Models\Product;
use App\Models\SystemUser;
use App\Support\Catalog\CatalogSlug;
use App\Support\Enums\SystemPermission;

/**
 * Creates or updates a plan (structure only: no prices, provider routes or
 * purchasing). The code is generated on create and never changed, even if
 * the plan is renamed or moved to another product. Active state has its own action.
 */
class SavePlan
{
    public function __construct(private CatalogRules $rules) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, SystemUser $actor): Plan
    {
        $this->rules->authorize($actor, SystemPermission::ServicesCreate);

        $product = Product::findOrFail($data['product_id']);
        $plan = new Plan($this->attributes($data));
        $plan->code = CatalogSlug::planCode($product->code, $data['name']);
        $plan->is_active = (bool) ($data['is_active'] ?? false);
        $plan->save();

        return $plan;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Plan $plan, array $data, SystemUser $actor): Plan
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $plan->fill($this->attributes($data))->save();

        return $plan;
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'product_id' => (int) $data['product_id'],
            'name' => $data['name'],
            'amount_type' => $data['amount_type'],
            'validity_period' => $data['validity_period'] ?? null,
            'validity_days' => isset($data['validity_days']) ? (int) $data['validity_days'] : null,
            'data_volume_mb' => isset($data['data_volume_mb']) ? (int) $data['data_volume_mb'] : null,
            'min_amount_kobo' => isset($data['min_amount_kobo']) ? (int) $data['min_amount_kobo'] : null,
            'max_amount_kobo' => isset($data['max_amount_kobo']) ? (int) $data['max_amount_kobo'] : null,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
