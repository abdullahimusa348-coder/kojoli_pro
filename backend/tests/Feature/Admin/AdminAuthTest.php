<?php

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('shows the staff login page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Staff log in');
});

it('sends guests to the staff login', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

it('lets every staff role sign in to the admin area', function (SystemRole $role) {
    $staff = SystemUser::factory()->withRole($role)->create();

    $this->post('/admin/login', ['email' => strtoupper($staff->email), 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $this->assertAuthenticatedAs($staff, 'admin');
    $this->assertGuest('web');
    $this->get('/admin')->assertOk()->assertSee($role->label());
    expect($staff->fresh()->last_login_at)->not->toBeNull();
})->with(SystemRole::cases());

it('refuses customer credentials on the staff login', function () {
    $customer = User::factory()->create();

    $this->post('/admin/login', ['email' => $customer->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest('admin');
    $this->assertGuest('web');
});

it('refuses staff credentials on the customer login', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();

    $this->post('/login', ['login' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors(['login' => trans('auth.failed')]);

    $this->assertGuest('web');
    $this->assertGuest('admin');
});

it('never lets a signed-in customer into the admin area', function () {
    $this->actingAs(User::factory()->create(), 'web')
        ->get('/admin')
        ->assertRedirect(route('admin.login'));
});

it('never lets signed-in staff into the customer dashboard', function () {
    $this->actingAs(SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create(), 'admin')
        ->get('/dashboard')
        ->assertRedirect(route('login'));
});

it('refuses disabled staff at login', function () {
    $staff = SystemUser::factory()->disabled()->withRole(SystemRole::SuperAdmin)->create();

    $this->post('/admin/login', ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('admin');
});

it('refuses staff without any role at login', function () {
    $staff = SystemUser::factory()->create();

    $this->post('/admin/login', ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('admin');
});

it('signs out staff disabled after they signed in', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::Manager)->create();
    $this->actingAs($staff, 'admin');

    $staff->forceFill(['status' => 'disabled'])->save();

    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
});

it('throttles staff logins separately from customer logins', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::Viewer)->create(['email' => 'same@example.com']);
    $customer = User::factory()->create(['email' => 'same@example.com']);

    foreach (range(1, 5) as $i) {
        $this->post('/admin/login', ['email' => 'same@example.com', 'password' => 'wrong']);
    }

    $this->post('/admin/login', ['email' => 'same@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('admin');

    // The customer login with the same email is not locked by staff failures.
    $this->post('/login', ['login' => 'same@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($customer, 'web');
});

it('logs staff out of the admin area', function () {
    $this->actingAs(SystemUser::factory()->withRole(SystemRole::Viewer)->create(), 'admin')
        ->post('/admin/logout')
        ->assertRedirect(route('admin.login'));

    $this->assertGuest('admin');
});

it('uses a separate session cookie for the admin area', function () {
    $customerCookie = config('session.cookie');

    $this->get('/admin/login')->assertCookie('nadabo_admin_session')->assertCookieMissing($customerCookie);
    $this->get('/login')->assertCookie($customerCookie)->assertCookieMissing('nadabo_admin_session');
});

it('creates a staff account from the console with a typed password', function () {
    $this->artisan('nadabo:create-system-user', ['email' => 'Boss@Example.com', '--role' => 'manager'])
        ->expectsQuestion('Password for boss@example.com', 'AdminPass123')
        ->expectsQuestion('Confirm password', 'AdminPass123')
        ->assertSuccessful();

    $staff = SystemUser::firstWhere('email', 'boss@example.com');
    expect($staff->hasRole('manager'))->toBeTrue()
        ->and($staff->canAccessAdmin())->toBeTrue()
        ->and(User::count())->toBe(0);
});

it('rejects a weak password or unknown role from the console', function () {
    $this->artisan('nadabo:create-system-user', ['email' => 'boss@example.com', '--role' => 'manager'])
        ->expectsQuestion('Password for boss@example.com', 'weak')
        ->expectsQuestion('Confirm password', 'weak')
        ->assertFailed();

    $this->artisan('nadabo:create-system-user', ['email' => 'boss@example.com', '--role' => 'owner'])
        ->assertFailed();

    expect(SystemUser::count())->toBe(0);
});
