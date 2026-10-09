<?php

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function staffWith(SystemRole $role): SystemUser
{
    return SystemUser::factory()->withRole($role)->create(['name' => 'Ngozi Bello']);
}

/** Modules each role may see and open. Reserved module permissions are not granted to any role yet. */
dataset('role access', [
    'super admin' => [SystemRole::SuperAdmin, array_map(fn (AdminModule $m) => $m->value, AdminModule::cases())],
    'manager' => [SystemRole::Manager, ['dashboard', 'users', 'kyc']],
    'support' => [SystemRole::Support, ['dashboard', 'users', 'kyc']],
    'finance' => [SystemRole::Finance, ['dashboard', 'users']],
    'viewer' => [SystemRole::Viewer, ['dashboard', 'users']],
]);

it('shows each role exactly its permitted sidebar items', function (SystemRole $role, array $allowed) {
    $response = $this->actingAs(staffWith($role), 'admin')->get('/admin')->assertOk();

    foreach (AdminModule::cases() as $module) {
        in_array($module->value, $allowed, true)
            ? $response->assertSee('data-nav="'.$module->value.'"', false)
            : $response->assertDontSee('data-nav="'.$module->value.'"', false);
    }
})->with('role access');

it('lets each role open only its permitted module pages', function (SystemRole $role, array $allowed) {
    $this->actingAs(staffWith($role), 'admin');

    foreach (AdminModule::cases() as $module) {
        $response = $this->get(route($module->routeName()));

        in_array($module->value, $allowed, true) ? $response->assertOk() : $response->assertForbidden();
    }
})->with('role access');

it('lists all fifteen modules in order for super admin', function () {
    $this->actingAs(staffWith(SystemRole::SuperAdmin), 'admin')
        ->get('/admin')
        ->assertSeeInOrder([
            'Dashboard', 'Users', 'KYC', 'Services', 'Transactions', 'Providers', 'Payments', 'Wallet', 'Withdrawals',
            'Referral &amp; Commission', 'Notifications', 'Support', 'Reports', 'Settings', 'System Users', 'Roles &amp; Permissions',
        ], false);
});

it('renders module placeholders without business data', function () {
    $this->actingAs(staffWith(SystemRole::SuperAdmin), 'admin')
        ->get('/admin/withdrawals')
        ->assertOk()
        ->assertSee('data-placeholder="withdrawals"', false)
        ->assertSee('Withdrawals is not built yet')
        ->assertSee('Phase 14');
});

it('shows all foundation cards to super admin with real counts and no invented money', function () {
    User::factory()->count(3)->create();

    $response = $this->actingAs(staffWith(SystemRole::SuperAdmin), 'admin')->get('/admin')->assertOk();

    foreach (['total-users', 'wallet-balance', 'todays-sales', 'todays-revenue', 'pending-withdrawals'] as $card) {
        $response->assertSee('data-card="'.$card.'"', false);
    }

    $response->assertSee('Total Users')
        ->assertSee('Registered customer accounts')
        ->assertSeeInOrder(['data-card="total-users"', '>3<'], false)
        ->assertSee('₦0.00')
        ->assertSee('Not live')
        ->assertSee('data-panel="recent-transactions"', false)
        ->assertSee('No transactions yet');
});

it('counts customers only, never staff, in Total Users', function () {
    SystemUser::factory()->count(4)->create();

    $this->actingAs(staffWith(SystemRole::SuperAdmin), 'admin')
        ->get('/admin')
        ->assertSeeInOrder(['data-card="total-users"', '>0<'], false);
});

it('hides financial cards and recent transactions from roles without those permissions', function (SystemRole $role) {
    $response = $this->actingAs(staffWith($role), 'admin')->get('/admin')->assertOk();

    $response->assertSee('data-card="total-users"', false);
    foreach (['wallet-balance', 'todays-sales', 'todays-revenue', 'pending-withdrawals'] as $card) {
        $response->assertDontSee('data-card="'.$card.'"', false);
    }
    $response->assertDontSee('data-panel="recent-transactions"', false);
})->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('shows the profile menu, mobile menu button and logout', function () {
    $this->actingAs(staffWith(SystemRole::Manager), 'admin')
        ->get('/admin')
        ->assertSee('Ngozi Bello')
        ->assertSee('Manager')
        ->assertSee('aria-label="Open menu"', false)
        ->assertSee('aria-label="Account menu"', false)
        ->assertSee('action="'.route('admin.logout').'"', false)
        ->assertSee('aria-current="page"', false);
});

it('redirects guests and customer sessions from every admin page to the staff login', function () {
    $customer = User::factory()->create();

    foreach (AdminModule::cases() as $module) {
        $this->get(route($module->routeName()))->assertRedirect(route('admin.login'));
        $this->actingAs($customer, 'web')->get(route($module->routeName()))->assertRedirect(route('admin.login'));
    }
});

it('signs out disabled staff instead of showing the dashboard', function () {
    $staff = staffWith(SystemRole::SuperAdmin);
    $staff->forceFill(['status' => 'disabled'])->save();

    $this->actingAs($staff, 'admin')->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
    $this->get('/admin/users')->assertRedirect(route('admin.login'));
});

it('signs out staff whose roles were removed', function () {
    $staff = staffWith(SystemRole::Viewer);
    $staff->syncRoles([]);

    $this->actingAs($staff, 'admin')->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
});

it('leaves the customer dashboard unchanged', function () {
    $this->actingAs(User::factory()->create(['name' => 'Ada Obi']), 'web')
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Welcome back')->assertSee('Ada Obi')
        ->assertDontSee('data-nav=', false);
});
