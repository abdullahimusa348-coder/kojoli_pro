<?php

use App\Models\User;
use App\Support\Customer\CustomerDashboard;
use App\Support\Customer\CustomerNav;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Illuminate\Support\Carbon;

function dashCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Ada Obi',
        'email' => 'ada@example.com',
    ], $attributes));
}

/** Render the dashboard view with its real data for a user (used where routing would redirect first). */
function renderDashboard(User $user): string
{
    test()->actingAs($user);

    return view('user.dashboard', CustomerDashboard::for($user))->render();
}

it('lets an authenticated customer open the dashboard', function () {
    $this->actingAs(dashCustomer())->get('/dashboard')->assertOk()
        ->assertSee('data-account-summary', false)
        ->assertSee('Welcome back');
});

it('still redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('welcomes the customer by name and shows the account summary', function () {
    Carbon::setTestNow('2026-02-14 10:00:00');
    $user = dashCustomer();
    Carbon::setTestNow();

    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertSeeInOrder(['Welcome back', 'Ada Obi'])
        ->assertSeeInOrder(['data-summary="name"', 'Ada Obi'], false)
        ->assertSeeInOrder(['data-summary="type"', 'Subscriber'], false)
        ->assertSeeInOrder(['data-summary="status"', 'Active'], false)
        ->assertSeeInOrder(['data-summary="member-since"', 'datetime="2026-02-14"', '14 February 2026'], false)
        ->assertSeeInOrder(['data-summary="email-verification"', 'ada@example.com'], false);
});

it('shows the correct account type label', function (UserType $type) {
    $this->actingAs(dashCustomer(['user_type' => $type]))->get('/dashboard')
        ->assertSeeInOrder(['data-summary="type"', $type->label()], false);
})->with(UserType::cases());

it('shows the account status from the account', function () {
    $active = dashCustomer();
    $disabled = dashCustomer(['email' => 'off@example.com', 'phone' => '08039990000', 'status' => UserStatus::Disabled]);

    expect(renderDashboard($active))->toMatch('/data-summary="status">\s*<span[^>]*>Active</s');
    // Disabled customers are signed out before reaching the page; the view still labels them correctly.
    expect(renderDashboard($disabled))->toMatch('/data-summary="status">\s*<span[^>]*>Disabled</s');
    $this->actingAs($disabled)->get('/dashboard')->assertRedirect(route('login'));
});

describe('email verification status and prompt', function () {
    it('shows Verified for a verified email', function () {
        $this->actingAs(dashCustomer())->get('/dashboard')
            ->assertSeeInOrder(['data-summary="email-verification"', 'Verified'], false)
            ->assertDontSee('data-verification-prompt', false);
    });

    it('shows Not required and no prompt when verification is disabled', function () {
        config(['nadabo.require_email_verification' => false]);

        $this->actingAs(dashCustomer(['email_verified_at' => null]))->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['data-summary="email-verification"', 'Not required'], false)
            ->assertDontSee('data-verification-prompt', false)
            ->assertDontSee('Please verify your email address');
    });

    it('shows Not verified and the prompt when enabled and unverified', function () {
        config(['nadabo.require_email_verification' => true]);

        $html = renderDashboard(dashCustomer(['email_verified_at' => null]));

        expect($html)->toContain('data-verification-prompt')
            ->toContain('Please verify your email address')
            ->toContain('href="'.route('verification.notice').'"')
            ->toMatch('/data-summary="email-verification".*Not verified/s');
    });

    it('keeps the approved redirect to the verification page for unverified customers when enabled', function () {
        config(['nadabo.require_email_verification' => true]);

        $this->actingAs(dashCustomer(['email_verified_at' => null]))->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    });

    it('shows no prompt for verified customers when enabled', function () {
        config(['nadabo.require_email_verification' => true]);

        $this->actingAs(dashCustomer())->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['data-summary="email-verification"', 'Verified'], false)
            ->assertDontSee('data-verification-prompt', false);
    });
});

describe('shortcuts', function () {
    it('links Account and Security from the dashboard', function () {
        $this->actingAs(dashCustomer())->get('/dashboard')
            ->assertSee('data-shortcut="account"', false)
            ->assertSee('data-shortcut="security"', false)
            ->assertSee('href="'.CustomerNav::Account->url().'" data-shortcut="account"', false)
            ->assertSee('href="'.CustomerNav::Security->url().'" data-shortcut="security"', false);
    });

    it('opens the pages the shortcuts point to', function () {
        $user = dashCustomer();
        $this->actingAs($user);

        $this->get(parse_url(CustomerNav::Account->url(), PHP_URL_PATH))->assertOk()->assertSee('Account details');
        $this->get(parse_url(CustomerNav::Security->url(), PHP_URL_PATH))->assertOk()->assertSee('id="security"', false)->assertSee('Change password');
    });

    it('offers no other shortcuts', function () {
        $html = $this->actingAs(dashCustomer())->get('/dashboard')->getContent();

        expect(substr_count($html, 'data-shortcut="'))->toBe(2);
    });
});

it('gives every customer type the same dashboard structure', function () {
    $markers = ['data-account-summary', 'data-summary="name"', 'data-summary="type"', 'data-summary="status"',
        'data-summary="member-since"', 'data-summary="email-verification"', 'data-shortcut="account"', 'data-shortcut="security"'];

    $structures = [];
    foreach (UserType::cases() as $i => $type) {
        $html = $this->actingAs(dashCustomer(['email' => "t{$i}@example.com", 'phone' => '0803000000'.$i, 'user_type' => $type]))
            ->get('/dashboard')->assertOk()->getContent();
        preg_match_all('/data-(?:account-summary|summary="[^"]+"|shortcut="[^"]+"|verification-prompt)/', $html, $m);
        $structures[$type->value] = $m[0];

        foreach ($markers as $marker) {
            expect($html)->toContain($marker);
        }
    }

    expect(array_unique(array_map('serialize', $structures)))->toHaveCount(1);
});

it('shows no money, wallet, transaction, service or future-module content', function () {
    $html = mb_strtolower($this->actingAs(dashCustomer())->get('/dashboard')->getContent());

    foreach (['₦', 'ngn', 'naira', 'wallet', 'balance', 'fund', 'transaction', 'airtime', 'data plan', 'buy data',
        'cable', 'electricity', 'bill', 'referral', 'commission', 'withdraw', 'payment', 'provider', 'service', 'coming soon'] as $word) {
        expect($html)->not->toContain($word, "found \"{$word}\"");
    }
});

it('never renders password hashes or tokens', function () {
    $user = dashCustomer(['password' => 'DashSecret123']);
    $token = $user->createToken('phone')->plainTextToken;

    $html = $this->actingAs($user->fresh())->get('/dashboard')->getContent();

    expect($html)->not->toContain('$2y$')->not->toContain('DashSecret123')->not->toContain($token);
});
