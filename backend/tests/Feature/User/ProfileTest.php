<?php

use App\Models\User;

it('shows the dashboard and profile to a signed-in user', function () {
    $user = User::factory()->create(['name' => 'Ada Obi']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Welcome back')->assertSee('Ada Obi')->assertSee('Subscriber');
    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Account details');
});

it('updates name and email', function () {
    $user = User::factory()->create(['phone' => '08031112222']);

    $this->actingAs($user)->patch('/profile', [
        'name' => 'New Name',
        'email' => 'New@Example.com',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->phone)->toBe('08031112222')
        ->and($user->email_verified_at)->toBeNull();
});

it('rejects phone changes from the customer profile form', function () {
    $user = User::factory()->create(['phone' => '08031112222']);

    $this->actingAs($user)->patch('/profile', [
        'name' => 'New Name',
        'email' => $user->email,
        'phone' => '0901 234 5678',
    ])->assertSessionHasErrors('phone');

    expect($user->fresh()->phone)->toBe('08031112222')
        ->and($user->fresh()->name)->not->toBe('New Name');
});

it('keeps the email verified when it does not change', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => 'New Name',
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('does not let users change their own type or status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile', [
        'name' => $user->name,
        'email' => $user->email,
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
    ])->assertSessionHasNoErrors()->assertRedirect(route('security'));

    expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue();
});
