<?php

use App\Models\User;

it('shows the dashboard and profile to a signed-in user', function () {
    $user = User::factory()->create(['name' => 'Ada Obi']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Welcome back')->assertSee('Ada Obi')->assertSee('Subscriber');
    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Account details');
});

it('updates name, email and phone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => 'New Name',
        'email' => 'New@Example.com',
        'phone' => '0901 234 5678',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->phone)->toBe('09012345678')
        ->and($user->email_verified_at)->toBeNull();
});

it('keeps the email verified when it does not change', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => 'New Name',
        'email' => $user->email,
        'phone' => $user->phone,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('does not let users change their own type or status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'user_type' => 'api_user',
        'status' => 'disabled',
    ]);

    $user->refresh();
    expect($user->user_type->value)->toBe('subscriber')->and($user->isActive())->toBeTrue();
});

it('changes the password only with the current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/profile/password', [
        'current_password' => 'wrong',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

    $this->actingAs($user)->put('/profile/password', [
        'current_password' => 'password',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue();
});
