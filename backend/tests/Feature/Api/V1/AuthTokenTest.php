<?php

use App\Models\SystemUser;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a bearer token for valid credentials', function () {
    $user = User::factory()->create(['phone' => '08031234567']);

    $this->postJson('/api/v1/auth/token', [
        'login' => '08031234567',
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.user_type', 'subscriber')
        ->assertJsonMissingPath('user.password');

    expect($user->tokens()->count())->toBe(1);
});

it('rejects bad credentials and disabled accounts', function () {
    $user = User::factory()->create();
    $disabled = User::factory()->disabled()->create();

    $this->postJson('/api/v1/auth/token', ['login' => $user->email, 'password' => 'wrong', 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('login');

    $this->postJson('/api/v1/auth/token', ['login' => $disabled->email, 'password' => 'password', 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('returns the current user for a valid token and 401 without one', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->getJson('/api/v1/user')->assertUnauthorized();

    $this->withToken($token)->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('blocks tokens of accounts disabled later', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $user->forceFill(['status' => 'disabled'])->save();

    $this->withToken($token)->getJson('/api/v1/user')->assertForbidden();
});

it('revokes the current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('issues non-expiring tokens when no expiration is configured', function () {
    config(['sanctum.expiration' => null]);
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/token', ['login' => $user->email, 'password' => 'password', 'device_name' => 'x'])
        ->assertCreated()
        ->assertJsonPath('expires_at', null);

    expect(PersonalAccessToken::first()->expires_at)->toBeNull();
});

it('applies the configured token lifetime', function () {
    config(['sanctum.expiration' => 60]);
    $user = User::factory()->create();

    $token = $this->postJson('/api/v1/auth/token', ['login' => $user->email, 'password' => 'password', 'device_name' => 'x'])
        ->assertCreated()
        ->assertJsonPath('expires_at', fn (string $at) => abs(now()->addMinutes(60)->diffInSeconds($at)) < 5)
        ->json('access_token');

    $this->withToken($token)->getJson('/api/v1/user')->assertOk();

    $this->travel(61)->minutes();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
});

it('reads the token lifetime from SANCTUM_TOKEN_EXPIRATION', function () {
    $read = function (?string $value): mixed {
        $value === null ? putenv('SANCTUM_TOKEN_EXPIRATION') : putenv("SANCTUM_TOKEN_EXPIRATION={$value}");
        $_ENV['SANCTUM_TOKEN_EXPIRATION'] = $_SERVER['SANCTUM_TOKEN_EXPIRATION'] = $value;
        if ($value === null) {
            unset($_ENV['SANCTUM_TOKEN_EXPIRATION'], $_SERVER['SANCTUM_TOKEN_EXPIRATION']);
        }

        return (require config_path('sanctum.php'))['expiration'];
    };

    try {
        expect($read(null))->toBeNull()
            ->and($read(''))->toBeNull()
            ->and($read('0'))->toBeNull()
            ->and($read('43200'))->toBe(43200);
    } finally {
        $read(null);
    }
});

it('never issues API tokens to staff accounts', function () {
    $staff = SystemUser::factory()->create();

    $this->postJson('/api/v1/auth/token', ['login' => $staff->email, 'password' => 'password', 'device_name' => 'x'])
        ->assertUnprocessable();
});
