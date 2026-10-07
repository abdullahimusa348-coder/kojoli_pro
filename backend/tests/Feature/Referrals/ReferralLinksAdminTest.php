<?php

use App\Actions\Auth\RegisterUser;
use App\Models\Referral;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/*
 * Phase 12 CP3: the read-only Referrals tab of the admin Referral & Commission
 * area (referrals.view). Every link, newest first, with both customers' name
 * and email, the link date and the referrer's code; search by name, email or
 * code; names link to the customer pages only for staff with customers.view;
 * names escaped; and no route for staff to add, change or remove a link.
 */

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function rlaStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Staff with a custom role holding exactly the given permissions. */
function rlaRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'RLA '.implode(' ', $permissions), 'guard_name' => 'admin'])->givePermissionTo($permissions);

    return rlaStaff($role->name);
}

/** A referrer with their code: [referrer, code]. */
function rlaReferrer(string $name, string $email): array
{
    $referrer = User::factory()->create(['name' => $name, 'email' => $email]);

    return [$referrer, app(ReferralCodeIssuer::class)->codeFor($referrer)->code];
}

/** A customer who signs up with $code, through RegisterUser as on the signup form. */
function rlaSignup(string $code, string $name, string $email): User
{
    static $phone = 8031000000;

    return app(RegisterUser::class)->handle(['name' => $name, 'email' => $email, 'phone' => '0'.$phone++, 'password' => 'Secret123'], $code);
}

it('lists every link newest first, with both customers\' names and emails, the link date and the referrer\'s code', function () {
    [, $code] = rlaReferrer('Amina Yusuf', 'amina@example.com');
    $this->travelTo(now()->setDateTime(2026, 10, 6, 9, 15));
    rlaSignup($code, 'Bisi Ade', 'bisi@example.com');
    $this->travelTo(now()->setDateTime(2026, 10, 7, 14, 40));
    rlaSignup($code, 'Chidi Okafor', 'chidi@example.com');

    $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')->assertOk()
        ->assertSeeInOrder(['data-referral-link=',
            'Referred customer', 'Chidi Okafor', 'chidi@example.com', 'Referred by', 'Amina Yusuf', 'amina@example.com', $code, '7 Oct 2026, 14:40',
            'Referred customer', 'Bisi Ade', 'bisi@example.com', 'Referred by', 'Amina Yusuf', 'amina@example.com', $code, '6 Oct 2026, 09:15']);
});

it('shows an empty state before anyone signs up with a code', function () {
    $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')->assertOk()
        ->assertSee('data-links-empty', false)->assertSee('No referrals yet');
});

it('searches by either customer\'s name or email, or by the referrer\'s code in any case', function () {
    [, $amina] = rlaReferrer('Amina Yusuf', 'amina@example.com');
    [, $emeka] = rlaReferrer('Emeka Nwosu', 'emeka@example.com');
    rlaSignup($amina, 'Bisi Ade', 'bisi@example.com');
    rlaSignup($emeka, 'Chidi Okafor', 'chidi@example.com');
    $this->actingAs(rlaStaff(), 'admin');
    $found = fn (string $q) => array_map(fn ($link) => $link->referred_name, $this->get('/admin/referrals/links?q='.urlencode($q))->assertOk()->viewData('links')->items());

    expect($found('bisi'))->toBe(['Bisi Ade'])
        ->and($found('EMEKA@example'))->toBe(['Chidi Okafor'])
        ->and($found('Yusuf'))->toBe(['Bisi Ade'])
        ->and($found(' '.strtolower($emeka).' '))->toBe(['Chidi Okafor'])
        ->and($found('%'))->toBe([])
        ->and($found('nobody'))->toBe([]);
    $this->get('/admin/referrals/links?q=nobody')->assertSee('No referrals found');
    $this->get('/admin/referrals/links?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
});

it('sits between Commissions and Rates & caps', function () {
    $html = $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')->getContent();

    expect(preg_match_all('/data-referrals-tab="([a-z]+)"/', $html, $tabs))->toBe(3)
        ->and($tabs[1])->toBe(['commissions', 'links', 'rates'])
        ->and(preg_match('/<a [^>]*data-referrals-tab="links"[^>]*aria-current="page"/', $html))->toBe(1);
});

it('links the names to the customer pages only for staff who can view customers', function () {
    [$referrer, $code] = rlaReferrer('Amina Yusuf', 'amina@example.com');
    $customer = rlaSignup($code, 'Bisi Ade', 'bisi@example.com');

    $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')
        ->assertSee('href="'.route('admin.users.show', $customer).'"', false)->assertSee('href="'.route('admin.users.show', $referrer).'"', false);
    $this->actingAs(rlaRole(['admin.access', 'referrals.view']), 'admin')->get('/admin/referrals/links')->assertOk()
        ->assertSee('Bisi Ade')->assertDontSee('href="'.route('admin.users.show', $customer).'"', false);
});

it('escapes customer names and emails', function () {
    [, $code] = rlaReferrer('<b>Amina</b>', 'amina@example.com');
    rlaSignup($code, '<script>alert(1)</script>', 'bisi@example.com');

    $html = $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')->assertOk()
        ->assertSee('&lt;b&gt;Amina&lt;/b&gt;', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->getContent();

    expect($html)->not->toContain('<b>Amina</b>')->not->toContain('<script>alert(1)');
});

it('pages the links 25 at a time', function () {
    [, $code] = rlaReferrer('Amina Yusuf', 'amina@example.com');
    foreach (range(1, 26) as $i) {
        rlaSignup($code, "Customer {$i}", "customer{$i}@example.com");
    }

    $this->actingAs(rlaStaff(), 'admin')->get('/admin/referrals/links')->assertSee('Showing 1–25 of 26');
    expect(Referral::count())->toBe(26);
});

it('lets referrals.view staff look, keeps everyone else out, and has no way to change a link', function () {
    $this->actingAs(rlaRole(['admin.access', 'referrals.view']), 'admin')->get('/admin/referrals/links')->assertOk();

    foreach ([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer] as $role) {
        $this->actingAs(rlaStaff($role), 'admin')->get('/admin/referrals/links')->assertForbidden();
    }
    $this->actingAs(rlaRole(['admin.access', 'customers.view']), 'admin')->get('/admin/referrals/links')->assertForbidden();

    auth('admin')->logout();
    $this->get('/admin/referrals/links')->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create(), 'web')->get('/admin/referrals/links')->assertRedirect(route('admin.login'));

    // The only write in the admin Referral & Commission area is a commission rate and cap (CP2).
    $writes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/referrals')
        && array_diff($route->methods(), ['GET', 'HEAD']) !== [])->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();
    expect($writes)->toBe(['PUT admin/referrals/rates/{service}']);
});
