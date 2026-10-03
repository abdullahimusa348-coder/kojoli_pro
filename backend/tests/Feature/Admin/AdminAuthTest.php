<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('shows the admin login page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Admin log in');
});

it('sends guests to the admin login', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

it('lets an admin sign in to the admin area', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RolesAndPermissionsSeeder::ADMIN);

    $this->post('/admin/login', ['login' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk()->assertSee('Admin area');
});

it('lets a super admin in through Gate::before', function () {
    $super = User::factory()->create();
    $super->assignRole(RolesAndPermissionsSeeder::SUPER_ADMIN);

    $this->actingAs($super)->get('/admin')->assertOk();
});

it('refuses admin login for customers without starting a session', function () {
    $user = User::factory()->create();

    $this->post('/admin/login', ['login' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('forbids customers from the admin area', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('creates an admin from the console with a typed password', function () {
    $this->artisan('nadabo:create-admin', ['email' => 'Boss@Example.com'])
        ->expectsQuestion('Password for boss@example.com', 'AdminPass123')
        ->expectsQuestion('Confirm password', 'AdminPass123')
        ->assertSuccessful();

    expect(User::firstWhere('email', 'boss@example.com')->canAccessAdmin())->toBeTrue();
});

it('rejects a weak admin password from the console', function () {
    $this->artisan('nadabo:create-admin', ['email' => 'boss@example.com'])
        ->expectsQuestion('Password for boss@example.com', 'weak')
        ->expectsQuestion('Confirm password', 'weak')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
