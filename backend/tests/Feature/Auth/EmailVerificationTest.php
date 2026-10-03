<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function registerCustomer(): void
{
    test()->post('/register', [
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'phone' => '08031234567',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ]);
}

describe('when OFF (default)', function () {
    it('is off by default', function () {
        expect(config('nadabo.require_email_verification'))->toBeFalse();
    });

    it('sends no verification email on registration', function () {
        Notification::fake();

        registerCustomer();

        Notification::assertNothingSent();
    });

    it('does not block unverified customers', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertOk();
    });

    it('sends the notice and resend routes back to the dashboard', function () {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/verify-email')->assertRedirect(route('dashboard', absolute: false));
        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    });
});

describe('when ON', function () {
    beforeEach(fn () => config(['nadabo.require_email_verification' => true]));

    it('sends a verification email on registration', function () {
        Notification::fake();

        registerCustomer();

        Notification::assertSentTo(User::firstWhere('email', 'ada@example.com'), VerifyEmail::class);
    });

    it('sends unverified customers to the notice page', function () {
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));

        $this->get('/verify-email')->assertOk()->assertSee('Verify your email address');
    });

    it('still lets unverified customers fix their email on the profile page', function () {
        $this->actingAs(User::factory()->unverified()->create())->get('/profile')->assertOk();
    });

    it('lets verified customers through', function () {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    });

    it('resends the verification email', function () {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post('/email/verification-notification')
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    });

    it('verifies with a valid signed link', function () {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        Event::assertDispatched(Verified::class);
        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('rejects a link with the wrong hash', function () {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('wrong@example.com'),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('asks for re-verification after an email change', function () {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'changed@example.com',
            'phone' => $user->phone,
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    });
});
