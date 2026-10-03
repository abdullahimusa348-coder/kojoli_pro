<?php

namespace App\Actions\Admin\Catalog;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;

/**
 * Enables or disables a category or a service (services.update). Disabling a
 * category leaves its services' own active flags unchanged; they simply
 * become unavailable while the category is disabled.
 */
class SetCatalogStatus
{
    public function __construct(private CatalogRules $rules) {}

    public function handle(ServiceCategory|Service $item, bool $active, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::ServicesUpdate);

        $item->is_active = $active;
        $item->save();
    }
}
