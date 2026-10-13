<?php

namespace App\Actions\Admin\Catalog;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;

/**
 * Enables or disables a category, service, product or plan (services.update).
 * Disabling a parent leaves its children's own active flags unchanged; they
 * simply become unavailable while the parent is disabled.
 */
class SetCatalogStatus
{
    public function __construct(private CatalogRules $rules) {}

    public function handle(ServiceCategory|Service|Product|Plan $item, bool $active, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $item->is_active = $active;
        $item->save();
    }
}
