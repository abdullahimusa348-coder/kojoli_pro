<?php

use App\Actions\Admin\Roles\UpdateRole;
use App\Actions\Admin\SystemUsers\ChangeSystemUserStatus;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Permissions\PermissionModule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function roleStaff(SystemRole|string $role = SystemRole::SuperAdmin, array $attributes = []): SystemUser
{
    $staff = SystemUser::factory()->create($attributes);
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

function customRole(string $name, array $permissions = []): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'admin']);
    $role->syncPermissions($permissions);

    return $role;
}

function adminRole(SystemRole $role): Role
{
    return Role::findByName($role->value, 'admin');
}

describe('permission catalog', function () {
    it('stores every catalog permission on the admin guard, grouped by module', function () {
        expect(Permission::where('guard_name', 'admin')->pluck('name')->sort()->values()->all())
            ->toBe(collect(SystemPermission::values())->sort()->values()->all());

        foreach (PermissionModule::cases() as $module) {
            expect(SystemPermission::byModule()[$module->value])->not->toBeEmpty("{$module->value} has no permissions");
        }
    });

    it('covers every admin sidebar module with a catalog permission', function () {
        foreach (AdminModule::cases() as $module) {
            expect(SystemPermission::tryFrom($module->permission()))->not->toBeNull($module->value);
        }
    });

    it('keeps the approved default grants for built-in roles', function () {
        expect(adminRole(SystemRole::Viewer)->permissions->pluck('name')->sort()->values()->all())
            ->toBe(['admin.access', 'customers.view'])
            ->and(adminRole(SystemRole::SuperAdmin)->permissions->count())->toBe(count(SystemPermission::cases()));
    });

    it('does not overwrite role changes made in the admin area when re-seeded', function () {
        adminRole(SystemRole::Viewer)->givePermissionTo('reports.view');
        adminRole(SystemRole::Finance)->revokePermissionTo('customers.view');

        $this->seed(RolesAndPermissionsSeeder::class);

        expect(adminRole(SystemRole::Viewer)->hasPermissionTo('reports.view'))->toBeTrue()
            ->and(adminRole(SystemRole::Finance)->hasPermissionTo('customers.view'))->toBeFalse()
            ->and(adminRole(SystemRole::SuperAdmin)->permissions->count())->toBe(count(SystemPermission::cases()));
    });
});

describe('access control', function () {
    it('lets super admin use every Roles & Permissions page', function () {
        $this->actingAs(roleStaff(), 'admin');

        $this->get('/admin/roles')->assertOk()->assertSee('Roles &amp; Permissions', false)->assertSee('Add role');
        $this->get('/admin/roles/create')->assertOk()->assertSee('data-module="wallet"', false);
        $this->get('/admin/roles/'.adminRole(SystemRole::Manager)->id.'/edit')->assertOk()->assertSee('Save role');
    });

    it('returns 403 on every route for built-in roles without roles permissions', function (SystemRole $role) {
        $target = customRole('Target Role');
        $this->actingAs(roleStaff($role), 'admin');

        $this->get('/admin/roles')->assertForbidden();
        $this->get('/admin/roles/create')->assertForbidden();
        $this->post('/admin/roles', ['name' => 'Sneaky', 'permissions' => ['admin.access']])->assertForbidden();
        $this->get("/admin/roles/{$target->id}/edit")->assertForbidden();
        $this->put("/admin/roles/{$target->id}", ['name' => 'Target Role', 'permissions' => ['settings.update']])->assertForbidden();
        $this->delete("/admin/roles/{$target->id}")->assertForbidden();

        expect(Role::where('name', 'Sneaky')->exists())->toBeFalse()
            ->and($target->fresh()->permissions)->toBeEmpty();
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('blocks guests and customers', function () {
        $role = adminRole(SystemRole::Viewer);

        $this->get('/admin/roles')->assertRedirect(route('admin.login'));
        $this->put("/admin/roles/{$role->id}", ['permissions' => []])->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/roles')->assertRedirect(route('admin.login'));
        $this->post('/admin/roles', ['name' => 'Customer Role', 'permissions' => []])->assertRedirect(route('admin.login'));
        $this->delete("/admin/roles/{$role->id}")->assertRedirect(route('admin.login'));

        expect(Role::where('name', 'Customer Role')->exists())->toBeFalse()
            ->and($role->fresh()->permissions->count())->toBe(2);
    });

    it('allows only the permitted actions for a view-only role', function () {
        $viewer = roleStaff(customRole('Role Auditor', ['admin.access', 'roles.view'])->name);
        $target = customRole('Other Role');
        $this->actingAs($viewer, 'admin');

        $this->get('/admin/roles')->assertOk()->assertDontSee('Add role');
        $this->get("/admin/roles/{$target->id}/edit")->assertOk()->assertSee('You can view this role but not change it.')->assertDontSee('Save role');
        $this->get('/admin/roles/create')->assertForbidden();
        $this->put("/admin/roles/{$target->id}", ['name' => 'Other Role', 'permissions' => ['roles.view']])->assertForbidden();
        $this->delete("/admin/roles/{$target->id}")->assertForbidden();
    });

    it('does not resolve customer-guard roles', function () {
        $webRole = Role::create(['name' => 'web-only', 'guard_name' => 'web']);

        $this->actingAs(roleStaff(), 'admin')->get("/admin/roles/{$webRole->id}/edit")->assertNotFound();
    });
});

describe('create and edit', function () {
    it('creates a custom role with granular permissions', function () {
        $this->actingAs(roleStaff(), 'admin')
            ->post('/admin/roles', ['name' => '  Customer   Care ', 'permissions' => ['admin.access', 'customers.view', 'support.view', 'support.manage']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.roles'))
            ->assertSessionHas('status', 'Role “Customer Care” created.');

        $role = Role::findByName('Customer Care', 'admin');
        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(['admin.access', 'customers.view', 'support.manage', 'support.view']);
    });

    it('explains that built-in names are reserved', function () {
        $this->actingAs(roleStaff(), 'admin')->post('/admin/roles', ['name' => 'Manager', 'permissions' => []])
            ->assertSessionHasErrors(['name' => 'That name is reserved for a built-in role.']);
    });

    it('creates a role with no permissions', function () {
        $this->actingAs(roleStaff(), 'admin')->post('/admin/roles', ['name' => 'Empty Role'])->assertSessionHasNoErrors();

        expect(Role::findByName('Empty Role', 'admin')->permissions)->toBeEmpty();
    });

    it('validates role input', function (array $payload, string $error) {
        customRole('Existing Role');

        $this->actingAs(roleStaff(), 'admin')->post('/admin/roles', $payload)->assertSessionHasErrors($error);

        expect(Role::where('guard_name', 'admin')->count())->toBe(6);
    })->with([
        'missing name' => [['name' => '', 'permissions' => []], 'name'],
        'too short' => [['name' => 'ab', 'permissions' => []], 'name'],
        'bad characters' => [['name' => 'Role<script>', 'permissions' => []], 'name'],
        'duplicate' => [['name' => 'Existing Role', 'permissions' => []], 'name'],
        'reserved slug' => [['name' => 'manager', 'permissions' => []], 'name'],
        'reserved slug, any case' => [['name' => 'Manager', 'permissions' => []], 'name'],
        'reserved label' => [['name' => 'super admin', 'permissions' => []], 'name'],
        'unknown permission' => [['name' => 'New Role', 'permissions' => ['wallet.steal']], 'permissions.0'],
        'permissions not a list' => [['name' => 'New Role', 'permissions' => 'admin.access'], 'permissions'],
    ]);

    it('renames a custom role and adds and removes permissions', function () {
        $role = customRole('Night Shift', ['admin.access', 'customers.view', 'reports.view']);

        $this->actingAs(roleStaff(), 'admin')
            ->put("/admin/roles/{$role->id}", ['name' => 'Night Support', 'permissions' => ['admin.access', 'support.view', 'support.manage']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.roles'));

        $role->refresh();
        expect($role->name)->toBe('Night Support')
            ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'support.manage', 'support.view']);
    });

    it('lets super admin change built-in role permissions but not their names', function () {
        $viewer = adminRole(SystemRole::Viewer);

        $this->actingAs(roleStaff(), 'admin')
            ->put("/admin/roles/{$viewer->id}", ['name' => 'Renamed Viewer', 'permissions' => ['admin.access', 'customers.view', 'reports.view']])
            ->assertSessionHasNoErrors();

        $viewer->refresh();
        expect($viewer->name)->toBe('viewer')
            ->and($viewer->hasPermissionTo('reports.view'))->toBeTrue();
    });

    it('rejects a rename of a built-in role through the action', function () {
        expect(fn () => app(UpdateRole::class)->handle(adminRole(SystemRole::Viewer), 'Renamed', ['admin.access'], roleStaff()))
            ->toThrow(ValidationException::class);
    });

    it('shows the matrix grouped by module with current permissions checked', function () {
        $role = customRole('Matrix Role', ['wallet.view']);

        $html = $this->actingAs(roleStaff(), 'admin')->get("/admin/roles/{$role->id}/edit")->assertOk()->getContent();

        foreach (PermissionModule::cases() as $module) {
            expect($html)->toContain('data-module="'.$module->value.'"');
        }
        expect($html)->toMatch('/value="wallet\.view"\s+checked/')
            ->and($html)->not->toMatch('/value="wallet\.manage"\s+checked/')
            ->and($html)->toContain('Not built yet');
    });
});

describe('staff receive only their role permissions', function () {
    it('grants and removes access as role permissions change', function () {
        $role = customRole('Settings Clerk', ['admin.access']);
        $staff = roleStaff($role->name);
        $super = roleStaff();

        $this->actingAs($staff, 'admin')->get('/admin/settings')->assertForbidden();

        $this->actingAs($super, 'admin')->put("/admin/roles/{$role->id}", ['name' => 'Settings Clerk', 'permissions' => ['admin.access', 'settings.view']]);
        $this->actingAs($staff->fresh(), 'admin')->get('/admin/settings')->assertOk();
        $this->actingAs($staff->fresh(), 'admin')->get('/admin/system-users')->assertForbidden();

        $this->actingAs($super, 'admin')->put("/admin/roles/{$role->id}", ['name' => 'Settings Clerk', 'permissions' => ['admin.access']]);
        $this->actingAs($staff->fresh(), 'admin')->get('/admin/settings')->assertForbidden();
    });

    it('shows sidebar items only for the role permissions', function () {
        $staff = roleStaff(customRole('Wallet Desk', ['admin.access', 'wallet.view'])->name);

        $this->actingAs($staff, 'admin')->get('/admin')
            ->assertSee('data-nav="wallet"', false)
            ->assertDontSee('data-nav="users"', false)
            ->assertDontSee('data-nav="roles"', false);
        $this->get('/admin/wallet')->assertOk();
        $this->get('/admin/payments')->assertForbidden();
    });

    it('keeps staff whose role lacks admin.access out of the admin area', function () {
        $staff = roleStaff(customRole('No Access', ['reports.view'])->name);

        $this->post('/admin/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    });

    it('lets System Users assign and filter by custom roles', function () {
        customRole('Customer Care', ['admin.access', 'support.view']);
        $super = roleStaff();
        $this->actingAs($super, 'admin');

        $this->get('/admin/system-users/create')->assertSee('value="Customer Care"', false);
        $this->post('/admin/system-users', [
            'name' => 'Care Agent', 'email' => 'care.agent@example.com', 'role' => 'Customer Care', 'status' => 'active',
            'password' => 'CarePass123', 'password_confirmation' => 'CarePass123',
        ])->assertSessionHasNoErrors();

        $agent = SystemUser::firstWhere('email', 'care.agent@example.com');
        expect($agent->primaryRoleName())->toBe('Customer Care')
            ->and($agent->can('support.view'))->toBeTrue()
            ->and($agent->can('settings.view'))->toBeFalse();

        $this->get('/admin/system-users?role=Customer+Care')->assertSee('Care Agent');
        $this->post('/admin/system-users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'web-or-missing', 'status' => 'active', 'password' => 'CarePass123', 'password_confirmation' => 'CarePass123'])
            ->assertSessionHasErrors('role');
    });
});

describe('safety rules', function () {
    it('locks the Super Admin role', function () {
        $superRole = adminRole(SystemRole::SuperAdmin);
        $this->actingAs(roleStaff(), 'admin');

        $this->get("/admin/roles/{$superRole->id}/edit")->assertOk()
            ->assertSee('The Super Admin role always has every permission and cannot be changed.')
            ->assertDontSee('Save role')->assertDontSee('Delete role');
        $this->put("/admin/roles/{$superRole->id}", ['permissions' => []])->assertSessionHasErrors('role');
        $this->delete("/admin/roles/{$superRole->id}")->assertSessionHasErrors('role');

        expect($superRole->fresh()->permissions->count())->toBe(count(SystemPermission::cases()));
    });

    it('keeps Super Admin full access even if every other role is emptied', function () {
        $super = roleStaff();
        $this->actingAs($super, 'admin');

        foreach ([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer] as $role) {
            $this->put('/admin/roles/'.adminRole($role)->id, ['permissions' => []])->assertSessionHasNoErrors();
        }

        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/system-users')->assertOk();
        $this->get('/admin/settings')->assertOk();
        expect($super->fresh()->can('wallet.manage'))->toBeTrue();
    });

    it('stops non super admins escalating privileges', function () {
        $editorRole = customRole('Role Editor', ['admin.access', 'roles.view', 'roles.update', 'customers.view']);
        $editor = roleStaff($editorRole->name);
        $target = customRole('Junior', ['admin.access']);
        $this->actingAs($editor, 'admin');

        // Can grant a permission they hold.
        $this->put("/admin/roles/{$target->id}", ['name' => 'Junior', 'permissions' => ['admin.access', 'customers.view']])->assertSessionHasNoErrors();
        // Cannot grant one they do not hold.
        $this->put("/admin/roles/{$target->id}", ['name' => 'Junior', 'permissions' => ['admin.access', 'customers.view', 'system-users.manage']])
            ->assertSessionHasErrors('permissions');
        // Cannot edit their own role.
        $this->put("/admin/roles/{$editorRole->id}", ['name' => 'Role Editor', 'permissions' => [...$editorRole->permissions->pluck('name'), 'settings.update']])
            ->assertSessionHasErrors('role');

        expect($target->fresh()->hasPermissionTo('system-users.manage'))->toBeFalse()
            ->and($editorRole->fresh()->hasPermissionTo('settings.update'))->toBeFalse();
    });

    it('stops non super admins removing permissions they do not hold', function () {
        $editor = roleStaff(customRole('Role Editor', ['admin.access', 'roles.view', 'roles.update'])->name);
        $target = customRole('Settings Team', ['admin.access', 'settings.view']);

        $this->actingAs($editor, 'admin')
            ->put("/admin/roles/{$target->id}", ['name' => 'Settings Team', 'permissions' => ['admin.access']])
            ->assertSessionHasErrors('permissions');

        expect($target->fresh()->hasPermissionTo('settings.view'))->toBeTrue();
    });

    it('deletes only unassigned custom roles', function () {
        $super = roleStaff();
        $unused = customRole('Unused Role');
        $assigned = customRole('Assigned Role');
        roleStaff($assigned->name);
        $heldByDeleted = customRole('Held By Deleted');
        roleStaff($heldByDeleted->name)->delete();
        $this->actingAs($super, 'admin');

        $this->delete("/admin/roles/{$unused->id}")->assertRedirect(route('admin.roles'))->assertSessionHas('status', 'Role “Unused Role” deleted.');
        $this->delete("/admin/roles/{$assigned->id}")->assertSessionHasErrors('role');
        $this->delete("/admin/roles/{$heldByDeleted->id}")->assertSessionHasErrors('role');
        $this->delete('/admin/roles/'.adminRole(SystemRole::Viewer)->id)->assertSessionHasErrors('role');

        expect(Role::where('name', 'Unused Role')->exists())->toBeFalse()
            ->and(Role::where('name', 'Assigned Role')->exists())->toBeTrue()
            ->and(Role::where('name', 'viewer')->exists())->toBeTrue();
    });

    it('re-checks permissions inside the actions', function () {
        expect(fn () => app(UpdateRole::class)->handle(customRole('Any Role'), 'Any Role', [], roleStaff(SystemRole::Viewer)))
            ->toThrow(AuthorizationException::class);
    });

    it('refuses role management to deactivated staff', function () {
        $super = roleStaff();
        $this->actingAs($super, 'admin');
        $super->forceFill(['status' => 'disabled'])->save();

        $this->get('/admin/roles')->assertRedirect(route('admin.login'));
    });

    it('still prevents removing the last active Super Admin', function () {
        $super = roleStaff();

        expect(fn () => app(ChangeSystemUserStatus::class)->handle($super, UserStatus::Disabled, roleStaff(attributes: ['status' => 'disabled'])))
            ->toThrow(AuthorizationException::class);
        $this->actingAs($super, 'admin')->delete("/admin/system-users/{$super->id}")->assertSessionHasErrors('system_user');
        expect($super->fresh()->isActive())->toBeTrue();
    });

    it('never renders password hashes on role pages', function () {
        $super = roleStaff(attributes: ['password' => 'RolePageSecret123']);
        $this->actingAs($super, 'admin');

        foreach (['/admin/roles', '/admin/roles/create', '/admin/roles/'.adminRole(SystemRole::Manager)->id.'/edit'] as $url) {
            $html = $this->get($url)->getContent();
            expect($html)->not->toContain('$2y$')->not->toContain('RolePageSecret123')->not->toContain($super->password);
        }
    });
});

describe('roles list', function () {
    it('lists built-in roles first with permission and staff counts', function () {
        customRole('Alpha Team', ['admin.access']);
        roleStaff(SystemRole::Finance);
        roleStaff(SystemRole::Finance);
        User::factory()->create();
        $this->actingAs(roleStaff(), 'admin');

        $this->get('/admin/roles')->assertOk()
            ->assertSeeInOrder(['Super Admin', 'Manager', 'Support', 'Finance', 'Viewer', 'Alpha Team'])
            ->assertSee('All permissions')
            ->assertSee('1 of '.count(SystemPermission::cases()).' permissions')
            ->assertSee('2 staff')
            ->assertSee('(your role)');
    });

    it('searches and filters roles', function () {
        customRole('Alpha Team');
        customRole('Beta Team');
        $this->actingAs(roleStaff(), 'admin');

        $this->get('/admin/roles?q=alpha')->assertSee('data-role="Alpha Team"', false)->assertDontSee('data-role="Beta Team"', false);
        $this->get('/admin/roles?type=custom')->assertSee('data-role="Beta Team"', false)->assertDontSee('data-role="manager"', false);
        $this->get('/admin/roles?type=built-in')->assertSee('data-role="manager"', false)->assertDontSee('data-role="Alpha Team"', false);
        $this->get('/admin/roles?q=zzz')->assertSee('No roles found');
        $this->get('/admin/roles?type=weird')->assertSessionHasErrors('type');
    });
});
