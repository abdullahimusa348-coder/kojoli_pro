<?php

use App\Models\User;
use App\Support\Customer\CustomerNav;
use App\Support\Customer\CustomerSessions;
use App\Support\Enums\UserType;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

function securityCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '08031112222'], $attributes));
}

/** Switch this test to database sessions (the production default). */
function useDatabaseSessions(): void
{
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** Store a session row for a user and return its raw id. */
function storeSession(User $user, string $ip = '102.89.34.7', string $agent = CHROME_ANDROID, int $minutesAgo = 30): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => $ip, 'user_agent' => $agent,
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->subMinutes($minutesAgo)->timestamp,
    ]);

    return $id;
}

/** Make the next requests use an existing session id as "this browser". */
function asBrowser(string $sessionId)
{
    return test()->withCookie(config('session.cookie'), $sessionId);
}

describe('page', function () {
    it('loads for authenticated customers of every type', function (UserType $type) {
        $this->actingAs(securityCustomer(['user_type' => $type]))->get('/security')->assertOk()
            ->assertSee('data-security-section="password"', false)
            ->assertSee('data-security-section="sessions"', false)
            ->assertSee('id="change-password"', false)
            ->assertSee('data-security-section="apps"', false);
    })->with(UserType::cases());

    it('redirects guests to login on every Security route', function () {
        $this->get('/security')->assertRedirect(route('login'));
        $this->post('/security/sessions/logout-others', ['current_password' => 'password'])->assertRedirect(route('login'));
        $this->delete('/security/sessions/'.str_repeat('a', 64))->assertRedirect(route('login'));
        $this->delete('/security/tokens/1')->assertRedirect(route('login'));
        $this->delete('/security/tokens', ['current_password' => 'password'])->assertRedirect(route('login'));
    });

    it('is reached from the Security navigation item and dashboard shortcut, and marked active', function () {
        $user = securityCustomer();

        expect(CustomerNav::Security->url())->toBe(route('security'));
        $this->actingAs($user)->get('/dashboard')
            ->assertSee('href="'.route('security').'" data-shortcut="security"', false)
            ->assertSee('data-customer-menu="security"', false);
        $this->actingAs($user)->get('/security')->assertOk()
            ->assertSee('data-customer-nav="dashboard"', false)
            ->assertDontSee('/profile#security');
        expect(CustomerNav::Security->isActive())->toBeTrue();
    });

    it('stays reachable for unverified customers when verification is on', function () {
        config(['nadabo.require_email_verification' => true]);

        $this->actingAs(securityCustomer(['email_verified_at' => null]))->get('/security')->assertOk();
    });

    it('blocks disabled customers', function () {
        $user = securityCustomer(['status' => 'disabled']);
        $token = $user->createToken('phone');

        $this->actingAs($user)->get('/security')->assertRedirect(route('login'));
        $this->actingAs($user)->delete('/security/tokens/'.$token->accessToken->id)->assertRedirect(route('login'));

        expect(PersonalAccessToken::count())->toBe(1);
    });

    it('shows no future-module content', function () {
        $html = mb_strtolower($this->actingAs(securityCustomer())->get('/security')->getContent());

        foreach (['wallet', 'airtime', 'transaction', 'referral', 'withdraw', 'payment', 'two-factor', '2fa', 'api key', 'create token', 'delete account', 'coming soon'] as $word) {
            expect(str_contains($html, $word))->toBeFalse("found \"{$word}\"");
        }
    });
});

describe('password change', function () {
    it('changes the password from the Security page and stays there', function () {
        $user = securityCustomer();
        $user->createToken('phone');

        $this->actingAs($user)->from('/security')->put('/profile/password', [
            'current_password' => 'password', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('security'));

        expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);
        $this->get('/security')->assertSee('Password updated. Other devices have been signed out.');
    });

    it('shows password errors on the Security page', function () {
        $this->actingAs(securityCustomer())->from('/security')->put('/profile/password', [
            'current_password' => 'wrong', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertRedirect('/security')->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->get('/security')->assertSee('The password is incorrect.');
    });
});

describe('email verification', function () {
    it('is hidden when verification is off', function () {
        config(['nadabo.require_email_verification' => false]);

        $this->actingAs(securityCustomer(['email_verified_at' => null]))->get('/security')
            ->assertDontSee('data-security-section="email-verification"', false)
            ->assertDontSee('Resend verification email');
    });

    it('shows Not verified with a working resend when on and unverified', function () {
        config(['nadabo.require_email_verification' => true]);
        Notification::fake();
        $user = securityCustomer(['email_verified_at' => null]);

        $this->actingAs($user)->get('/security')
            ->assertSee('data-security-section="email-verification"', false)
            ->assertSeeInOrder(['data-verification-status', 'Not verified'], false)
            ->assertSee('action="'.route('verification.send').'"', false);

        $this->actingAs($user)->from('/security')->post('/email/verification-notification')->assertRedirect('/security');
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/security')->assertSee('A new verification link has been sent');
    });

    it('shows Verified without a resend button when verified', function () {
        config(['nadabo.require_email_verification' => true]);

        $this->actingAs(securityCustomer())->get('/security')
            ->assertSeeInOrder(['data-verification-status', 'Verified'], false)
            ->assertDontSee('Resend verification email');
    });
});

describe('signed-in browsers', function () {
    it('says the list is unavailable when sessions are not stored in the database', function () {
        config(['session.driver' => 'array']);

        $this->actingAs(securityCustomer())->get('/security')
            ->assertSee('The list of signed-in browsers is not available on this server.');
    });

    it('lists only the customer\'s own sessions with this browser marked, masked IPs and readable devices', function () {
        useDatabaseSessions();
        $user = securityCustomer();
        $current = storeSession($user, '41.58.200.11', SAFARI_IPHONE, 0);
        $other = storeSession($user, '102.89.34.7', CHROME_ANDROID, 90);
        $someoneElse = storeSession(securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888']), '197.210.1.2');

        $html = asBrowser($current)->actingAs($user)->get('/security')->assertOk()->getContent();

        expect(substr_count($html, 'data-session '))->toBe(2)
            ->and($html)->toContain('Safari on iOS')->toContain('Chrome on Android')
            ->toContain('This browser')
            ->toContain('41.58.•••.•••')->toContain('102.89.•••.•••')
            ->not->toContain('41.58.200.11')->not->toContain('102.89.34.7')->not->toContain('197.210')
            ->not->toContain($current)->not->toContain($other)->not->toContain($someoneElse)
            ->toContain(route('security.sessions.destroy', CustomerSessions::ref($other)))
            ->not->toContain(route('security.sessions.destroy', CustomerSessions::ref($current)));
    });

    it('logs out one other browser without a password', function () {
        useDatabaseSessions();
        $user = securityCustomer();
        $current = storeSession($user, minutesAgo: 0);
        $other = storeSession($user);

        asBrowser($current)->actingAs($user)->delete('/security/sessions/'.CustomerSessions::ref($other))
            ->assertRedirect(route('security'))->assertSessionHas('status', 'Browser logged out.');

        expect(DB::table('sessions')->where('id', $other)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
    });

    it('refuses to remove another customer\'s session, the current session or an unknown one', function () {
        useDatabaseSessions();
        $user = securityCustomer();
        $current = storeSession($user, minutesAgo: 0);
        $theirs = storeSession(securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888']));

        asBrowser($current)->actingAs($user)->delete('/security/sessions/'.CustomerSessions::ref($theirs))->assertNotFound();
        asBrowser($current)->actingAs($user)->delete('/security/sessions/'.CustomerSessions::ref($current))->assertNotFound();
        asBrowser($current)->actingAs($user)->delete('/security/sessions/'.str_repeat('f', 64))->assertNotFound();
        $this->actingAs($user)->delete('/security/sessions/not-a-hash')->assertNotFound();

        expect(DB::table('sessions')->whereIn('id', [$current, $theirs])->count())->toBe(2);
    });

    it('logs out all other browsers only with the current password', function () {
        useDatabaseSessions();
        $user = securityCustomer();
        $current = storeSession($user, minutesAgo: 0);
        $others = [storeSession($user), storeSession($user, '102.89.1.1', SAFARI_IPHONE)];
        $theirs = storeSession(securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888']));
        $oldHash = $user->password;

        asBrowser($current)->actingAs($user)->from('/security')
            ->post('/security/sessions/logout-others', ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('logoutOthers', 'current_password');
        expect(DB::table('sessions')->whereIn('id', $others)->count())->toBe(2);

        asBrowser($current)->actingAs($user)->post('/security/sessions/logout-others', ['current_password' => 'password'])
            ->assertRedirect(route('security'))->assertSessionHas('status', '2 other browsers logged out.');

        $user->refresh();
        expect(DB::table('sessions')->whereIn('id', $others)->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', $current)->exists())->toBeTrue()
            ->and(DB::table('sessions')->where('id', $theirs)->exists())->toBeTrue()
            // Password rehashed: other sessions and remember-me cookies built on the old hash stop working.
            ->and($user->password)->not->toBe($oldHash)
            ->and(Hash::check('password', $user->password))->toBeTrue();
    });

    it('needs the password field for log out all', function () {
        $this->actingAs(securityCustomer())->post('/security/sessions/logout-others', [])
            ->assertSessionHasErrorsIn('logoutOthers', 'current_password');
    });
});

describe('signed-in apps', function () {
    it('lists only the customer\'s own tokens without values or hashes', function () {
        $user = securityCustomer();
        $mine = $user->createToken('Pixel 8');
        $mine->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();
        $theirs = securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888'])->createToken('Bola iPhone');

        $html = $this->actingAs($user)->get('/security')->getContent();

        expect(substr_count($html, 'data-token>'))->toBe(1)
            ->and($html)->toContain('Pixel 8')->toContain('Last used')
            ->not->toContain('Bola iPhone')
            ->not->toContain($mine->plainTextToken)->not->toContain(explode('|', $mine->plainTextToken)[1])
            ->not->toContain($mine->accessToken->token)
            ->not->toContain($theirs->plainTextToken);
    });

    it('shows an empty state with no tokens', function () {
        $this->actingAs(securityCustomer())->get('/security')->assertSee('No apps are signed in.')->assertDontSee('Revoke all apps');
    });

    it('revokes one token without a password', function () {
        $user = securityCustomer();
        $one = $user->createToken('one');
        $user->createToken('two');

        $this->actingAs($user)->delete('/security/tokens/'.$one->accessToken->id)
            ->assertRedirect(route('security'))->assertSessionHas('status', 'App access revoked.');

        expect($user->tokens()->pluck('name')->all())->toBe(['two']);

        // A real API call carries no web login; clear the test's in-memory web user first.
        app('auth')->forgetGuards();
        $this->withToken($one->plainTextToken)->getJson('/api/v1/user')->assertUnauthorized();
    });

    it('refuses to revoke another customer\'s token', function () {
        $user = securityCustomer();
        $theirs = securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888'])->createToken('theirs');

        $this->actingAs($user)->delete('/security/tokens/'.$theirs->accessToken->id)->assertNotFound();
        $this->actingAs($user)->delete('/security/tokens/abc')->assertNotFound();

        expect(PersonalAccessToken::count())->toBe(1);
    });

    it('revokes all tokens only with the current password', function () {
        $user = securityCustomer();
        $user->createToken('one');
        $user->createToken('two');
        $theirs = securityCustomer(['email' => 'bola@example.com', 'phone' => '08039998888'])->createToken('theirs');

        $this->actingAs($user)->from('/security')->delete('/security/tokens', ['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('revokeTokens', 'current_password');
        expect($user->tokens()->count())->toBe(2);

        $this->actingAs($user)->delete('/security/tokens', ['current_password' => 'password'])
            ->assertRedirect(route('security'))->assertSessionHas('status', '2 apps revoked.');

        expect($user->tokens()->count())->toBe(0)
            ->and(PersonalAccessToken::whereKey($theirs->accessToken->id)->exists())->toBeTrue();
    });
});

it('uses each element id only once on the page', function () {
    useDatabaseSessions();
    $user = securityCustomer();
    $current = storeSession($user, minutesAgo: 0);
    storeSession($user);
    $user->createToken('phone');

    $html = asBrowser($current)->actingAs($user)->get('/security')->getContent();
    preg_match_all('/\sid="([^"]+)"/', $html, $m);

    expect(array_diff_assoc($m[1], array_unique($m[1])))->toBe([]);
});

it('rate limits the Security actions', function () {
    $user = securityCustomer();
    $this->actingAs($user);

    foreach (range(1, 10) as $i) {
        $this->delete('/security/tokens/999999')->assertNotFound();
    }

    $this->delete('/security/tokens/999999')->assertStatus(429);
});

it('never exposes password hashes, secrets, raw session ids, token values or full IPs', function () {
    useDatabaseSessions();
    $user = securityCustomer(['password' => 'SecuritySecret123']);
    $current = storeSession($user, '41.58.200.11', SAFARI_IPHONE, 0);
    $other = storeSession($user, '102.89.34.7');
    $token = $user->createToken('phone');
    $user->refresh();

    $html = asBrowser($current)->actingAs($user)->get('/security')->getContent();

    expect($html)->not->toContain('$2y$')->not->toContain('SecuritySecret123')
        ->not->toContain($current)->not->toContain($other)
        ->not->toContain($token->plainTextToken)->not->toContain($token->accessToken->token)
        ->not->toContain('41.58.200.11')->not->toContain('102.89.34.7')
        ->not->toContain((string) $user->remember_token)
        ->not->toMatch('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/');
});

describe('helpers', function () {
    it('masks IPv4 and IPv6 addresses', function () {
        expect(CustomerSessions::maskIp('102.89.34.7'))->toBe('102.89.•••.•••')
            ->and(CustomerSessions::maskIp('2001:db8:85a3::8a2e:370:7334'))->toBe('2001:db8:••••:••••')
            ->and(CustomerSessions::maskIp(null))->toBe('Unknown')
            ->and(CustomerSessions::maskIp('not-an-ip'))->toBe('Unknown');
    });

    it('describes common browsers', function () {
        expect(CustomerSessions::describeAgent(CHROME_ANDROID))->toBe('Chrome on Android')
            ->and(CustomerSessions::describeAgent(SAFARI_IPHONE))->toBe('Safari on iOS')
            ->and(CustomerSessions::describeAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0'))->toBe('Edge on Windows')
            ->and(CustomerSessions::describeAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0'))->toBe('Firefox on macOS')
            ->and(CustomerSessions::describeAgent(null))->toBe('Unknown browser');
    });
});
