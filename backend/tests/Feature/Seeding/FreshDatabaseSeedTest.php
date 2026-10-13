<?php

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('seeds a brand-new database in one run (as on first deployment)', function () {
    // DatabaseSeeder runs WithoutModelEvents, so spatie's cache-refresh events do not fire while seeding.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Permission::count())->toBe(0)->and(Role::count())->toBe(0);

    $this->seed(DatabaseSeeder::class);

    expect(Permission::count())->toBe(count(SystemPermission::cases()))
        ->and(Role::count())->toBe(count(SystemRole::cases()))
        ->and(Role::findByName('super-admin', 'admin')->permissions->count())->toBe(count(SystemPermission::cases()))
        ->and(Role::findByName('super-admin', 'admin')->hasPermissionTo('wallet.adjust'))->toBeTrue()
        ->and(Role::findByName('manager', 'admin')->hasPermissionTo('wallet.adjust'))->toBeFalse();

    $this->seed(DatabaseSeeder::class); // and again, idempotently
    expect(Permission::count())->toBe(count(SystemPermission::cases()))->and(Role::count())->toBe(count(SystemRole::cases()));
});
