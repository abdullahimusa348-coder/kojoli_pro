<?php

use App\Actions\Auth\RegisterUser;
use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Models\Referral;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Services\Referrals\SignupReferrer;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Wallet\WalletStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Phase 12 CP3: signup attribution. The optional referral code on the signup
 * form, prefilled from ?ref= (letters and digits only, no lookup or storage);
 * a permanent level-1 link made only when a new customer's account is
 * created, in the same transaction, under a shared lock on the code's owner;
 * one neutral answer for every code that cannot be used (malformed, unknown,
 * an API User's, a disabled owner's) with nothing created and the entries
 * kept; a frozen wallet does not matter (T8 part 1); maintenance mode does
 * not stop it (T16 part 1); Registered after the commit; no other way to get
 * a referrer, ever; and no self-referral.
 */

/** A customer with their referral code: [owner, code]. */
function satReferrer(array $attributes = []): array
{
    $owner = User::factory()->create($attributes);

    return [$owner, app(ReferralCodeIssuer::class)->codeFor($owner)->code];
}

function satForm(array $overrides = []): array
{
    return $overrides + ['name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '08031234567',
        'password' => 'Secret123', 'password_confirmation' => 'Secret123'];
}

/** The value of the referral code field on a rendered signup form. */
function satField(string $html): ?string
{
    return preg_match('/<input[^>]*name="referral_code"[^>]*value="([^"]*)"/', $html, $match) === 1 ? $match[1] : null;
}

describe('signup form', function () {
    it('has an optional referral code field, empty by default', function () {
        $html = $this->get('/register')->assertOk()->assertSee('Referral code (optional)')->getContent();

        expect(satField($html))->toBe('');
    });

    it('fills the field from ?ref= in upper case, only for 1 to 20 letters or digits, without looking it up or storing it', function (string $ref, string $filled) {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->get('/register?ref='.urlencode($ref))->assertOk();

        // Laravel records every page's address as the previous URL (replaced by the next page); nothing else keeps the code.
        expect(satField($response->getContent()))->toBe($filled)
            ->and(array_filter($queries, fn (string $sql) => str_contains($sql, 'referral') || str_contains($sql, '"users"')))->toBe([])
            ->and(json_encode(Arr::except(session()->all(), ['_previous'])))->not->toContain(strtoupper($ref) ?: 'no-ref')
            ->and(array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()))->toBe(['XSRF-TOKEN', config('session.cookie')]);
    })->with([
        'a code in lower case' => ['abcd2345', 'ABCD2345'],
        'an unknown code (not looked up)' => ['ZZZZ9999', 'ZZZZ9999'],
        '20 characters' => [str_repeat('a', 20), str_repeat('A', 20)],
        '21 characters' => [str_repeat('a', 21), ''],
        'a dash' => ['ab-cd', ''],
        'markup' => ['<script>', ''],
        'empty' => ['', ''],
    ]);

    it('fills in the code from a customer\'s shared link', function () {
        [, $code] = satReferrer();

        expect(satField($this->get(route('register', ['ref' => $code]))->getContent()))->toBe($code);
    });
});

describe('attribution', function () {
    it('links the new customer to the code\'s owner, permanently, whatever the case of the code', function () {
        [$owner, $code] = satReferrer();

        $this->post('/register', satForm(['referral_code' => '  '.strtolower($code).' ']))->assertRedirect(route('dashboard'));

        $customer = User::firstWhere('email', 'bisi@example.com');
        $this->assertAuthenticatedAs($customer);
        expect(Referral::sole()->only(['referrer_id', 'referred_user_id']))->toBe(['referrer_id' => $owner->id, 'referred_user_id' => $customer->id])
            ->and($customer->user_type)->toBe(UserType::Subscriber);
    });

    it('makes no link without a code', function (?string $code) {
        $this->post('/register', satForm(['referral_code' => $code]))->assertRedirect(route('dashboard'));

        expect(User::count())->toBe(1)->and(Referral::count())->toBe(0);
    })->with(['no field' => null, 'spaces only' => '   ']);

    it('refuses every code that cannot be used with the same neutral message, keeps the other entries and creates nothing', function (Closure $code) {
        $value = $code();
        $before = [User::count(), Referral::count()];

        $this->from('/register')->post('/register', satForm(['referral_code' => $value]))->assertRedirect('/register');

        $this->assertGuest();
        expect(session('errors')->getBag('default')->toArray())->toBe(['referral_code' => [SignupReferrer::INVALID]])
            ->and(SignupReferrer::INVALID)->toBe("This referral code isn't valid")
            ->and(session()->getOldInput())->toMatchArray(['name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '08031234567',
                'referral_code' => is_string($value) ? trim($value) : $value]) // kept as typed
            ->and(session()->hasOldInput('password'))->toBeFalse()
            ->and([User::count(), Referral::count()])->toBe($before);
        if (is_string($value)) { // (an array never comes from the form, and the shared input component cannot show one)
            expect(satField($this->get('/register')->getContent()))->toBe(e(trim($value)));
        }
    })->with([
        'too short' => fn () => 'abc2345',
        'look-alike characters' => fn () => 'ABCD0O1I',
        'a symbol' => fn () => 'ABCD-345',
        'unknown' => fn () => 'ZZZZ2345',
        'an API User\'s code' => function () {
            [$owner, $code] = satReferrer();
            $owner->forceFill(['user_type' => UserType::ApiUser])->save();

            return $code;
        },
        'a disabled owner\'s code' => function () {
            [$owner, $code] = satReferrer();
            $owner->forceFill(['status' => UserStatus::Disabled])->save();

            return $code;
        },
        'not text' => fn () => ['ABCD2345'],
    ]);

    it('accepts the code of an owner whose wallet is frozen (T8 part 1)', function () {
        [$owner, $code] = satReferrer();
        $wallets = app(WalletService::class);
        $wallets->setStatus($wallets->walletFor($owner), WalletStatus::Frozen);

        $this->post('/register', satForm(['referral_code' => $code]))->assertRedirect(route('dashboard'));

        expect(Referral::sole()->referrer_id)->toBe($owner->id);
    });

    it('keeps the referral code when another entry is refused, and shows it again', function () {
        [, $code] = satReferrer();

        $this->from('/register')->post('/register', satForm(['phone' => '12345', 'referral_code' => strtolower($code)]))
            ->assertRedirect('/register')->assertSessionHasErrors('phone')->assertSessionDoesntHaveErrors('referral_code');

        expect(satField($this->get('/register')->getContent()))->toBe(strtolower($code))->and(Referral::count())->toBe(0); // as typed
    });

    it('checks the owner again under a lock when the account is created, refusing with the same message', function (Closure $change) {
        [$owner, $code] = satReferrer();
        $change($owner); // after the form was checked, before the account is created

        try {
            app(RegisterUser::class)->handle(['name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '08031234567', 'password' => 'Secret123'], $code);
            $this->fail('The signup was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['referral_code' => [SignupReferrer::INVALID]]);
        }
        expect(User::where('email', 'bisi@example.com')->exists())->toBeFalse()->and(Referral::count())->toBe(0);
    })->with([
        'disabled' => fn (User $owner) => $owner->forceFill(['status' => UserStatus::Disabled])->save(),
        'now an API User' => fn (User $owner) => $owner->forceFill(['user_type' => UserType::ApiUser])->save(),
    ]);

    it('fires Registered after the account and its link are saved, outside the registration transaction', function () {
        [, $code] = satReferrer();
        $seen = null;
        Event::listen(Registered::class, function (Registered $event) use (&$seen) {
            $seen = [DB::transactionLevel(), Referral::where('referred_user_id', $event->user->id)->exists()];
        });
        $base = DB::transactionLevel();

        $this->post('/register', satForm(['referral_code' => $code]))->assertRedirect(route('dashboard'));

        expect($seen)->toBe([$base, true]);
    });

    it('still sends the verification email after a signup with a code when verification is on', function () {
        Notification::fake();
        config(['nadabo.require_email_verification' => true]);
        [, $code] = satReferrer();

        $this->post('/register', satForm(['referral_code' => $code]));

        $customer = User::firstWhere('email', 'bisi@example.com');
        Notification::assertSentTo($customer, VerifyEmail::class);
        expect(Referral::sole()->referred_user_id)->toBe($customer->id);
    });

    it('attributes signups during maintenance mode, which only stops new purchases (T16 part 1)', function () {
        app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);
        [$owner, $code] = satReferrer();

        $this->post('/register', satForm(['referral_code' => $code]))->assertRedirect(route('dashboard'));

        expect(Referral::sole()->referrer_id)->toBe($owner->id);
    });

    it('lets a second account use another account\'s code: no email or phone matching', function () {
        [$owner, $code] = satReferrer(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '08031110000']);

        $this->post('/register', satForm(['name' => 'Ada Obi', 'email' => 'ada.obi@example.com', 'phone' => '08031110001', 'referral_code' => $code]))
            ->assertRedirect(route('dashboard'));

        expect(Referral::sole()->referrer_id)->toBe($owner->id);
    });

    it('creates nothing when another entry is refused, even with a valid code', function () {
        [, $code] = satReferrer(['email' => 'taken@example.com']);

        $this->post('/register', satForm(['email' => 'taken@example.com', 'referral_code' => $code]))->assertSessionHasErrors('email');

        expect(User::count())->toBe(1)->and(Referral::count())->toBe(0);
    });
});

describe('permanence', function () {
    it('takes a referral code only from web registration, as its own argument', function () {
        [, $code] = satReferrer();

        app(RegisterUser::class)->handle(['name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '08031234567',
            'password' => 'Secret123', 'referral_code' => $code]);

        expect(User::where('email', 'bisi@example.com')->exists())->toBeTrue()->and(Referral::count())->toBe(0);
    });

    it('never gives an existing customer a referrer', function () {
        [, $code] = satReferrer();
        $existing = User::factory()->create();

        $this->actingAs($existing)->post('/register', satForm(['referral_code' => $code]))->assertRedirect(route('dashboard'));
        $this->get('/register?ref='.$code)->assertRedirect(route('dashboard'));

        expect(Referral::count())->toBe(0)->and(User::count())->toBe(2);
    });

    it('makes links only in RegisterUser, never by hand or in bulk', function () {
        $sources = collect([app_path(), base_path('routes'), resource_path(), database_path('seeders'), database_path('factories')])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);

        expect($sources->filter(fn (string $code) => preg_match('/new\s+Referral\b|Referral::(?:create|firstOrCreate|updateOrCreate|make|forceCreate)\s*\(/', $code) === 1)->keys()->all())
            ->toBe(['app/Actions/Auth/RegisterUser.php']);
    });

    it('keeps the link whatever later happens to either customer, and never changes or removes it', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
        [$owner, $code] = satReferrer();
        $this->post('/register', satForm(['referral_code' => $code]));
        $customer = User::firstWhere('email', 'bisi@example.com');
        $link = Referral::sole();

        app(ChangeUserType::class)->handle($owner, UserType::ApiUser, $staff);
        app(ChangeUserType::class)->handle($customer, UserType::ApiUser, $staff); // keeps their referrer while an API User
        app(ChangeCustomerStatus::class)->handle($owner, UserStatus::Disabled, $staff);
        app(ChangeUserType::class)->handle($customer->fresh(), UserType::Vendor, $staff); // eligible again: the same referrer
        app(ChangeCustomerStatus::class)->handle($customer->fresh(), UserStatus::Disabled, $staff);

        expect(Referral::sole()->toArray())->toBe($link->toArray())
            ->and(fn () => $link->forceFill(['referrer_id' => $customer->id])->save())->toThrow(LogicException::class, 'Referral links are permanent.')
            ->and(fn () => $link->delete())->toThrow(LogicException::class, 'Referral links are permanent.')
            ->and(Referral::sole()->only(['referrer_id', 'referred_user_id']))->toBe(['referrer_id' => $owner->id, 'referred_user_id' => $customer->id]);
    });

    it('refuses a self-referral link, and a second referrer for a customer', function () {
        [$owner] = satReferrer();
        $customer = User::factory()->create();
        (new Referral)->forceFill(['referrer_id' => $owner->id, 'referred_user_id' => $customer->id])->save();

        expect(fn () => (new Referral)->forceFill(['referrer_id' => $owner->id, 'referred_user_id' => $owner->id])->save())
            ->toThrow(LogicException::class, 'A customer can never refer themselves.')
            ->and(fn () => (new Referral)->forceFill(['referrer_id' => User::factory()->create()->id, 'referred_user_id' => $customer->id])->save())
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(Referral::count())->toBe(1);
    });
});
