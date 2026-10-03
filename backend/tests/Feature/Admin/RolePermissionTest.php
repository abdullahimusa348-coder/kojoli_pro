<?php

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('seeds the five staff roles on the admin guard only', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['finance', 'manager', 'super-admin', 'support', 'viewer'])
        ->and(Role::where('guard_name', '!=', 'admin')->count())->toBe(0)
        ->and(Permission::where('guard_name', '!=', 'admin')->count())->toBe(0);
});

it('grants each role exactly its approved permissions', function (SystemRole $role, array $allowed) {
    $staff = SystemUser::factory()->withRole($role)->create();

    foreach (SystemPermission::cases() as $permission) {
        expect($staff->can($permission->value))
            ->toBe(in_array($permission->value, $allowed, true), "{$role->value} / {$permission->value}");
    }
})->with([
    'super admin' => [SystemRole::SuperAdmin, SystemPermission::values()],
    'manager' => [SystemRole::Manager, ['admin.access', 'customers.view', 'customers.update-status', 'customers.change-type', 'customers.update', 'customers.reset-password']],
    'support' => [SystemRole::Support, ['admin.access', 'customers.view', 'customers.update-status']],
    'finance' => [SystemRole::Finance, ['admin.access', 'customers.view']],
    'viewer' => [SystemRole::Viewer, ['admin.access', 'customers.view']],
]);

it('gives super admin any ability, even ones not defined yet', function () {
    $super = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();

    expect($super->can('some.future-permission'))->toBeTrue();
});

it('gives a disabled super admin nothing', function () {
    $super = SystemUser::factory()->disabled()->withRole(SystemRole::SuperAdmin)->create();

    expect($super->can('some.future-permission'))->toBeFalse()
        ->and($super->canAccessAdmin())->toBeFalse();
});

it('gives customers no staff abilities', function () {
    $customer = User::factory()->create();

    foreach (SystemPermission::values() as $permission) {
        expect($customer->can($permission))->toBeFalse();
    }
    expect(method_exists($customer, 'assignRole'))->toBeFalse();
});

it('is idempotent when re-seeded', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::count())->toBe(5)->and(Permission::count())->toBe(count(SystemPermission::cases()));
});
