<?php

namespace App\Actions\Admin\Catalog;

use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use Illuminate\Auth\Access\AuthorizationException;

/** Server-side permission check shared by the catalog actions (services.* covers categories too). */
class CatalogRules
{
    public function authorize(SystemUser $actor, SystemPermission $permission): void
    {
        if (! $actor->isActive() || ! $actor->can($permission->value)) {
            throw new AuthorizationException('You are not allowed to manage the service catalog.');
        }
    }
}
