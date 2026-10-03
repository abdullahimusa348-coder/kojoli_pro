<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('shows the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Log in to your account');
});

it('logs in with email', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login', ['login' => 'USER@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('logs in with phone number in any common format', function () {
    $user = User::factory()->create(['phone' => '08031234567']);

    $this->post('/login', ['login' => '+2348031234567', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('rejects an unknown account with the same message', function () {
    $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['login' => trans('auth.failed')]);

    $this->assertGuest();
});

it('rejects disabled accounts', function () {
    $user = User::factory()->disabled()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('signs out a session whose account was disabled after login', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->forceFill(['status' => 'disabled'])->save();

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('throttles repeated failed logins', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['login' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['login' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['login' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn(strtolower($user->email).'|127.0.0.1'), 'minutes' => 1])]);

    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

it('redirects guests away from the dashboard', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('redirects signed-in users away from guest pages', function () {
    $this->actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect(route('dashboard'));
});
