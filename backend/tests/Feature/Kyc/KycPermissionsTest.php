<?php

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Permissions\PermissionModule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP1 permissions: the eight approved KYC and virtual-account permissions, their modules and labels, the approved
 * grants on new installs and on existing ones (the one-time migration, which changes nothing else), and that customers hold
 * none of them.
 */

beforeEach(fn () => kycSeed());

/** The KYC and virtual-account permissions each role holds, as the approved matrix says. */
function kycGrantedTo(string $role): array
{
    return [
        'super-admin' => KYC_PERMISSION_NAMES,
        'manager' => ['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents', 'virtual-accounts.view'],
        'support' => ['kyc.view'],
        'finance' => ['virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers'],
        'viewer' => [],
    ][$role];
}

/** Each role's permissions outside the KYC and virtual-account set, so a migration that touches only its own can be proven. */
function kycOtherPermissions(): array
{
    return collect(SystemRole::cases())->mapWithKeys(fn (SystemRole $role) => [$role->value => Role::findByName($role->value, 'admin')
        ->permissions->pluck('name')->reject(fn (string $name) => in_array($name, KYC_PERMISSION_NAMES, true))->sort()->values()->all()])->all();
}

it('registers the eight approved permissions on the admin guard, and adds exactly those to the catalog', function () {
    expect(Permission::where('guard_name', 'admin')->whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(SystemPermission::cases())->toHaveCount(54)
        ->and(collect(SystemPermission::values())->diff(KYC_PERMISSION_NAMES)->count())->toBe(46);
});

it('maps the permissions to the KYC module (built) and the virtual-accounts module (defined, not built yet)', function () {
    expect(array_map(fn (SystemPermission $p) => $p->value, SystemPermission::byModule()['kyc']))
        ->toBe(['kyc.view', 'kyc.review', 'kyc.requirements', 'kyc.documents'])
        ->and(array_map(fn (SystemPermission $p) => $p->value, SystemPermission::byModule()['virtual-accounts']))
        ->toBe(['virtual-accounts.view', 'virtual-accounts.manage', 'virtual-accounts.providers', 'virtual-accounts.credentials'])
        ->and(PermissionModule::Kyc->isBuilt())->toBeTrue()
        ->and(PermissionModule::VirtualAccounts->isBuilt())->toBeFalse()
        ->and(PermissionModule::Kyc->label())->toBe('KYC')
        ->and(PermissionModule::VirtualAccounts->label())->toBe('Virtual accounts');
});

it('labels each permission for the Roles & Permissions matrix', function () {
    expect(SystemPermission::KycView->label())->toBe('View')
        ->and(SystemPermission::KycRequirements->label())->toBe('Configure requirements')
        ->and(SystemPermission::KycReview->label())->toBe('Decide submissions')
        ->and(SystemPermission::VirtualAccountsCredentials->label())->toBe('Manage provider credentials');
});

it('adds one sidebar item, KYC, under Operations, that opens the requirements page, and no virtual-accounts item yet', function () {
    expect(AdminModule::Kyc->label())->toBe('KYC')
        ->and(AdminModule::Kyc->group())->toBe('Operations')
        ->and(AdminModule::Kyc->permission())->toBe('kyc.view')
        ->and(AdminModule::Kyc->isBuilt())->toBeTrue()
        ->and(AdminModule::Kyc->routeName())->toBe('admin.kyc.requirements')
        ->and(AdminModule::tryFrom('virtual-accounts'))->toBeNull();
});

it('grants each built-in role exactly the approved KYC and virtual-account permissions', function (string $role) {
    $staff = SystemUser::factory()->withRole(SystemRole::from($role))->create();

    foreach (KYC_PERMISSION_NAMES as $name) {
        expect($staff->can($name))->toBe(in_array($name, kycGrantedTo($role), true), "{$role} / {$name}");
    }
})->with(['super-admin', 'manager', 'support', 'finance', 'viewer']);

it('gives new installs the same grants through the role defaults', function () {
    expect(array_values(array_intersect(SystemRole::SuperAdmin->permissions(), KYC_PERMISSION_NAMES)))->toBe(KYC_PERMISSION_NAMES)
        ->and(array_values(array_intersect(SystemRole::Manager->permissions(), KYC_PERMISSION_NAMES)))->toBe(kycGrantedTo('manager'))
        ->and(array_values(array_intersect(SystemRole::Support->permissions(), KYC_PERMISSION_NAMES)))->toBe(kycGrantedTo('support'))
        ->and(array_values(array_intersect(SystemRole::Finance->permissions(), KYC_PERMISSION_NAMES)))->toBe(kycGrantedTo('finance'))
        ->and(array_values(array_intersect(SystemRole::Viewer->permissions(), KYC_PERMISSION_NAMES)))->toBe([]);
});

it('gives an existing database the approved grants through its migration, and changes no other permission', function () {
    $before = kycOtherPermissions();
    foreach (SystemRole::cases() as $role) {
        Role::findByName($role->value, 'admin')->revokePermissionTo(KYC_PERMISSION_NAMES);
    }
    Permission::whereIn('name', KYC_PERMISSION_NAMES)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(0);

    (require database_path('migrations/'.KYC_PERMISSIONS_MIGRATION.'.php'))->up();

    foreach (SystemRole::cases() as $role) {
        $granted = Role::findByName($role->value, 'admin')->permissions->pluck('name')
            ->filter(fn (string $name) => in_array($name, KYC_PERMISSION_NAMES, true))->sort()->values()->all();
        expect($granted)->toBe(collect(kycGrantedTo($role->value))->sort()->values()->all(), $role->value);
    }
    expect(kycOtherPermissions())->toBe($before);
});

it('skips roles that do not exist yet, so a fresh database gets its grants from the seeder', function () {
    DB::table('role_has_permissions')->delete();
    DB::table('model_has_permissions')->delete();
    DB::table('model_has_roles')->delete();
    Role::query()->delete();
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/'.KYC_PERMISSIONS_MIGRATION.'.php'))->up();

    expect(Role::count())->toBe(0)
        ->and(Permission::where('guard_name', 'admin')->whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(0);

    (new RolesAndPermissionsSeeder)->run();

    expect(Permission::where('guard_name', 'admin')->whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(Role::findByName('manager', 'admin')->hasPermissionTo('kyc.review'))->toBeTrue()
        ->and(Role::findByName('finance', 'admin')->hasPermissionTo('virtual-accounts.providers'))->toBeTrue()
        ->and(Role::findByName('viewer', 'admin')->hasPermissionTo('kyc.view'))->toBeFalse();
});

it('removes the eight permissions when its migration is rolled back, and restores them when it runs again', function () {
    (require database_path('migrations/'.KYC_PERMISSIONS_MIGRATION.'.php'))->down();

    expect(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(0)
        ->and(Role::findByName('manager', 'admin')->permissions->pluck('name')->intersect(KYC_PERMISSION_NAMES)->all())->toBe([]);

    (require database_path('migrations/'.KYC_PERMISSIONS_MIGRATION.'.php'))->up();

    expect(Permission::whereIn('name', KYC_PERMISSION_NAMES)->count())->toBe(8)
        ->and(Role::findByName('manager', 'admin')->hasPermissionTo('kyc.review'))->toBeTrue();
});

it('gives customers no staff permission over the new modules', function () {
    $customer = User::factory()->create();

    foreach (KYC_PERMISSION_NAMES as $name) {
        expect($customer->can($name))->toBeFalse($name);
    }
});
