<?php

use App\Models\SystemUser;
use App\Models\User;
use App\Support\Customer\CustomerNav;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

function navCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge(['name' => 'Ada Obi', 'email' => 'ada@example.com'], $attributes));
}

describe('navigation list', function () {
    it('contains only implemented customer pages', function () {
        expect(array_map(fn (CustomerNav $i) => $i->value, CustomerNav::cases()))
            ->toBe(['dashboard', 'account', 'security', 'email-verification']);
    });

    it('points every item at a registered route', function () {
        $this->actingAs(navCustomer());

        foreach (CustomerNav::cases() as $item) {
            $path = parse_url($item->url(), PHP_URL_PATH);
            expect(collect(Route::getRoutes())->contains(fn ($r) => '/'.ltrim($r->uri(), '/') === $path))->toBeTrue($item->value);
        }
    });

    it('puts Dashboard and Account in the main bar and the rest in the menu', function () {
        $user = navCustomer();

        expect(CustomerNav::primaryFor($user))->toBe([CustomerNav::Dashboard, CustomerNav::Account])
            ->and(CustomerNav::menuFor($user))->toBe([CustomerNav::Security]);
    });

    it('adds Email verification to the menu only when verification is enabled', function () {
        config(['nadabo.require_email_verification' => true]);

        expect(CustomerNav::menuFor(navCustomer()))->toBe([CustomerNav::Security, CustomerNav::EmailVerification]);
    });
});

describe('layout', function () {
    it('renders the text brand, desktop nav, bottom nav and menu on the dashboard', function () {
        $html = $this->actingAs(navCustomer())->get('/dashboard')->assertOk()
            ->assertSee('Nadabo Global Data')
            ->assertSee('data-brand', false)
            ->assertSee('data-customer-nav="dashboard"', false)
            ->assertSee('data-customer-nav="account"', false)
            ->assertSee('data-customer-bottom-nav="dashboard"', false)
            ->assertSee('data-customer-bottom-nav="account"', false)
            ->assertSee('data-customer-bottom-nav="menu"', false)
            ->assertSee('data-customer-menu="security"', false)
            ->assertSee('data-customer-menu="logout"', false)
            ->assertSee('aria-label="Account menu"', false)
            ->assertSee('Ada Obi')
            ->assertSee('ada@example.com')
            ->getContent();

        // The menu and the desktop dropdown each render the menu items once.
        expect(substr_count($html, 'data-customer-menu="logout"'))->toBe(2)
            ->and(substr_count($html, 'action="'.route('logout').'"'))->toBe(2);
    });

    it('marks the current page in both navigations', function () {
        $user = navCustomer();

        $dashboard = $this->actingAs($user)->get('/dashboard')->getContent();
        expect($dashboard)->toMatch('/data-customer-nav="dashboard"[^>]*aria-current="page"/')
            ->and($dashboard)->toMatch('/data-customer-bottom-nav="dashboard"[^>]*aria-current="page"/')
            ->and($dashboard)->not->toMatch('/data-customer-nav="account"[^>]*aria-current="page"/');

        $account = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
        expect($account)->toMatch('/data-customer-nav="account"[^>]*aria-current="page"/')
            ->and($account)->toMatch('/data-customer-bottom-nav="account"[^>]*aria-current="page"/')
            ->and($account)->toContain('data-security-link');
    });

    it('links Security to the Security page', function () {
        $this->actingAs(navCustomer())->get('/dashboard')
            ->assertSee('href="'.route('security').'"', false);
    });

    it('shows Email verification in the menu only when enabled', function () {
        $user = navCustomer();

        $this->actingAs($user)->get('/dashboard')->assertDontSee('data-customer-menu="email-verification"', false);

        config(['nadabo.require_email_verification' => true]);
        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-customer-menu="email-verification"', false)
            ->assertSee('href="'.route('verification.notice').'"', false);
    });

    it('shows no future-module links or coming-soon placeholders', function () {
        $html = mb_strtolower($this->actingAs(navCustomer())->get('/dashboard')->getContent());

        foreach (['wallet', 'airtime', 'cable', 'electricity', 'transaction', 'referral', 'withdraw', 'payment', 'support', 'notification', 'coming soon', 'data plan', '/services'] as $word) {
            expect($html)->not->toContain($word, "found \"{$word}\"");
        }
    });

    it('uses the same navigation for every customer type', function (string $type) {
        $this->actingAs(navCustomer(['user_type' => $type]))->get('/dashboard')->assertOk()
            ->assertSee('data-customer-bottom-nav="dashboard"', false)
            ->assertSee('data-customer-bottom-nav="account"', false)
            ->assertSee('data-customer-bottom-nav="menu"', false);
    })->with(['subscriber', 'vendor', 'affiliate', 'api_user']);

    it('never renders password hashes or tokens in the layout', function () {
        $user = navCustomer(['password' => 'LayoutSecret123']);
        $token = $user->createToken('phone')->plainTextToken;

        $html = $this->actingAs($user->fresh())->get('/dashboard')->getContent();
        expect($html)->not->toContain('$2y$')->not->toContain('LayoutSecret123')->not->toContain($token)
            ->not->toContain((string) $user->fresh()->remember_token);
    });
});

describe('behaviour is unchanged', function () {
    it('logs out from the menu', function () {
        $this->actingAs(navCustomer())->post('/logout')->assertRedirect('/');
        $this->assertGuest('web');
    });

    it('still redirects guests and disabled customers', function () {
        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->actingAs(navCustomer(['status' => 'disabled']))->get('/dashboard')->assertRedirect(route('login'));
    });

    it('keeps the admin area on its own layout without customer navigation', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();

        $this->actingAs($staff, 'admin')->get('/admin')->assertOk()
            ->assertSee('data-nav="dashboard"', false)
            ->assertDontSee('data-customer-nav', false)
            ->assertDontSee('data-customer-bottom-nav', false);
    });

    it('leaves login and registration pages on the guest layout', function () {
        $this->get('/login')->assertOk()->assertDontSee('data-customer-bottom-nav', false);
        $this->get('/register')->assertOk()->assertDontSee('data-customer-bottom-nav', false);
    });
});
