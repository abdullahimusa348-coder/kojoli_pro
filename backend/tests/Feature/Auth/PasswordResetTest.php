<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('shows the forgot password page', function () {
    $this->get('/forgot-password')->assertOk();
});

it('sends a reset link to a known email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('gives the same response for an unknown email', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHas('status')
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

it('resets the password with a valid token and revokes API tokens', function () {
    Notification::fake();
    $user = User::factory()->create();
    $user->createToken('phone');

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->get('/reset-password/'.$notification->token.'?email='.$user->email)->assertOk();

        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

it('rejects an invalid token', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'invalid',
        'email' => $user->email,
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertSessionHasErrors('email');
});
