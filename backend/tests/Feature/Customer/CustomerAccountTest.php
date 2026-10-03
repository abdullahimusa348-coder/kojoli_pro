<?php

use App\Actions\Customers\UpdateCustomerProfile;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Customer\CustomerNav;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

function accountCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
        'phone' => '08031112222',
    ], $attributes));
}

describe('account page', function () {
    it('loads for authenticated customers with every account detail', function () {
        Carbon::setTestNow('2025-11-05 09:00:00');
        $user = accountCustomer(['user_type' => UserType::Vendor]);
        Carbon::setTestNow();

        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertSee('data-account-info', false)
            ->assertSeeInOrder(['data-account="name"', 'Ada Obi'], false)
            ->assertSeeInOrder(['data-account="email"', 'ada@example.com'], false)
            ->assertSeeInOrder(['data-account="email-verification"', 'Verified'], false)
            ->assertSeeInOrder(['data-account="phone"', '08031112222'], false)
            ->assertSeeInOrder(['data-account="type"', 'Vendor'], false)
            ->assertSeeInOrder(['data-account="status"', 'Active'], false)
            ->assertSeeInOrder(['data-account="member-since"', 'datetime="2025-11-05"', '5 November 2025'], false);
    });

    it('redirects guests to login', function () {
        $this->get('/profile')->assertRedirect(route('login'));
        $this->patch('/profile', ['name' => 'x', 'email' => 'x@example.com'])->assertRedirect(route('login'));
    });

    it('opens for all four customer types with the same structure', function (UserType $type) {
        $this->actingAs(accountCustomer(['user_type' => $type]))->get('/profile')->assertOk()
            ->assertSeeInOrder(['data-account="type"', $type->label()], false)
            ->assertSee('data-phone-readonly', false)
            ->assertSee('id="security"', false);
    })->with(UserType::cases());

    it('is reached from the Account navigation item', function () {
        $user = accountCustomer();

        $this->actingAs($user)->get('/dashboard')->assertSee('href="'.CustomerNav::Account->url().'"', false);
        $this->actingAs($user)->get(parse_url(CustomerNav::Account->url(), PHP_URL_PATH))->assertOk()
            ->assertSee('data-account-info', false);
    });
});

describe('email verification status', function () {
    it('reads Verified for a verified email', function () {
        $this->actingAs(accountCustomer())->get('/profile')
            ->assertSeeInOrder(['data-account="email-verification"', 'Verified'], false);
    });

    it('reads Not required when verification is off and the email is unverified', function () {
        config(['nadabo.require_email_verification' => false]);

        $this->actingAs(accountCustomer(['email_verified_at' => null]))->get('/profile')
            ->assertSeeInOrder(['data-account="email-verification"', 'Not required'], false);
    });

    it('reads Not verified when verification is on and the email is unverified', function () {
        config(['nadabo.require_email_verification' => true]);

        // The profile stays reachable while unverified (approved behaviour) so the email can be fixed.
        $this->actingAs(accountCustomer(['email_verified_at' => null]))->get('/profile')->assertOk()
            ->assertSeeInOrder(['data-account="email-verification"', 'Not verified'], false);
    });
});

describe('editing name and email', function () {
    it('updates the name', function () {
        $user = accountCustomer();

        $this->actingAs($user)->patch('/profile', ['name' => 'Adaeze Obi', 'email' => 'ada@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        expect($user->fresh()->name)->toBe('Adaeze Obi')
            ->and($user->fresh()->email_verified_at)->not->toBeNull();

        $this->get('/profile')->assertSee('Account details updated.');
    });

    it('updates the email with the existing rules and marks it unverified', function () {
        $user = accountCustomer();

        $this->actingAs($user)->patch('/profile', ['name' => 'Ada Obi', 'email' => '  NEW.Ada@Example.com '])
            ->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->email)->toBe('new.ada@example.com')
            ->and($user->email_verified_at)->toBeNull();
    });

    it('validates name and email', function (array $payload, string $error) {
        accountCustomer(['email' => 'taken@example.com', 'phone' => '08039998888']);
        $user = accountCustomer(['email' => 'me@example.com', 'phone' => '08030000001']);

        $this->actingAs($user)->patch('/profile', $payload)->assertSessionHasErrors($error);
        expect($user->fresh()->email)->toBe('me@example.com');
    })->with([
        'missing name' => [['name' => '', 'email' => 'me@example.com'], 'name'],
        'bad email' => [['name' => 'Me', 'email' => 'not-an-email'], 'email'],
        'duplicate email' => [['name' => 'Me', 'email' => 'TAKEN@example.com'], 'email'],
    ]);
});

describe('locked fields', function () {
    it('shows the phone read-only with a note and no submittable phone field', function () {
        $html = $this->actingAs(accountCustomer())->get('/profile')->getContent();

        expect($html)->toMatch('/<input id="phone-readonly"[^>]*value="08031112222"[^>]*readonly/')
            ->and($html)->toContain('Phone number changes will require SMS verification')
            ->and($html)->not->toMatch('/name="phone"/');
    });

    it('rejects a phone change server-side and changes nothing', function (string $phone) {
        $user = accountCustomer();

        $this->actingAs($user)->from('/profile')
            ->patch('/profile', ['name' => 'Changed Name', 'email' => 'ada@example.com', 'phone' => $phone])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors(['phone' => 'Your phone number cannot be changed here yet. Phone changes will need SMS verification.']);

        $user->refresh();
        expect($user->phone)->toBe('08031112222')->and($user->name)->toBe('Ada Obi');
    })->with(['09012345678', '+2348031112222', '08031112222']);

    it('cannot blank the phone either', function () {
        $user = accountCustomer();

        // An empty value counts as "not sent"; phone is never an accepted field, so nothing changes.
        $this->actingAs($user)->patch('/profile', ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '']);

        expect($user->fresh()->phone)->toBe('08031112222');
    });

    it('shows the phone rejection message on the page', function () {
        $user = accountCustomer();

        $this->actingAs($user)->from('/profile')->patch('/profile', ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '09012345678']);

        $this->get('/profile')->assertSee('Your phone number cannot be changed here yet.');
    });

    it('ignores account type and status sent by the customer', function () {
        $user = accountCustomer();

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Ada Obi', 'email' => 'ada@example.com',
            'user_type' => 'api_user', 'status' => 'disabled', 'email_verified_at' => null, 'password' => 'Injected123',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        expect($user->user_type)->toBe(UserType::Subscriber)
            ->and($user->status)->toBe(UserStatus::Active)
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('password', $user->password))->toBeTrue();
    });

    it('still lets staff change a customer phone through admin Users', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = SystemUser::factory()->withRole(SystemRole::Manager)->create();
        $user = accountCustomer();

        $this->actingAs($staff, 'admin')
            ->put("/admin/users/{$user->id}", ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '09012345678'])
            ->assertSessionHasNoErrors();

        expect($user->fresh()->phone)->toBe('09012345678');
        expect(fn () => app(UpdateCustomerProfile::class)->handle($user, ['name' => 'x', 'email' => 'x@example.com', 'phone' => '08000000000'], $staff))
            ->not->toThrow(Exception::class);
    });
});

describe('password section', function () {
    it('keeps the password form under the Security anchor, separate from account details', function () {
        $html = $this->actingAs(accountCustomer())->get('/profile')->getContent();

        expect($html)->toMatch('/<section id="security"[^>]*>.*name="current_password"/s')
            ->and($html)->toContain('href="'.CustomerNav::Security->url().'"');

        // The password fields are not inside the account details or edit-details form.
        $editForm = substr($html, strpos($html, 'action="'.route('profile.update').'"'));
        $editForm = substr($editForm, 0, strpos($editForm, '</form>'));
        expect($editForm)->not->toContain('current_password');
    });

    it('still changes the password', function () {
        $user = accountCustomer();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'password', 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue();
    });
});

it('never renders password hashes, tokens or secrets', function () {
    $user = accountCustomer(['password' => 'AccountSecret123']);
    $token = $user->createToken('phone')->plainTextToken;
    $user->refresh();

    $html = $this->actingAs($user)->get('/profile')->getContent();

    expect($html)->not->toContain('$2y$')->not->toContain('AccountSecret123')
        ->not->toContain($token)->not->toContain(explode('|', $token)[1])
        ->not->toContain((string) $user->remember_token);
});
