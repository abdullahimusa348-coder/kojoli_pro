<?php

use App\Models\User;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;

it('shows the registration page', function () {
    $this->get('/register')->assertOk()->assertSee('Create your account');
});

it('registers a subscriber, hashes the password and signs them in', function () {
    $response = $this->post('/register', [
        'name' => 'Ada Obi',
        'email' => 'Ada@Example.com',
        'phone' => '+234 803 123 4567',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::firstWhere('email', 'ada@example.com');
    expect($user)->not->toBeNull()
        ->and($user->phone)->toBe('08031234567')
        ->and($user->user_type)->toBe(UserType::Subscriber)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->password)->not->toBe('Secret123')
        ->and(Hash::check('Secret123', $user->password))->toBeTrue();
});

it('ignores a user_type or status sent in the request', function () {
    $this->post('/register', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'phone' => '08031234567',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'user_type' => 'vendor',
        'status' => 'disabled',
    ]);

    expect(User::firstWhere('email', 'sneaky@example.com')->user_type)->toBe(UserType::Subscriber);
});

it('rejects weak passwords, invalid phones and duplicates', function () {
    User::factory()->create(['email' => 'taken@example.com', 'phone' => '08031234567']);

    $this->post('/register', [
        'name' => 'X',
        'email' => 'TAKEN@example.com',
        'phone' => '0803 123 4567',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors(['email', 'phone', 'password']);

    $this->post('/register', [
        'name' => 'X',
        'email' => 'new@example.com',
        'phone' => '12345',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ])->assertSessionHasErrors(['phone']);

    $this->assertGuest();
});
