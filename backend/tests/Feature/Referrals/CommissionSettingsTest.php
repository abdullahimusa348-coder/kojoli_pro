<?php

use App\Actions\Admin\Referrals\SaveCommissionSetting;
use App\Http\Controllers\Admin\CommissionSettingController;
use App\Http\Requests\Admin\Referrals\CommissionSettingRequest;
use App\Models\CommissionSetting;
use App\Models\CommissionSettingChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Settings\SettingsStore;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Permissions\PermissionModule;
use App\Support\Pricing\PricingLimits;
use App\Support\Referrals\QualifyingServices;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Phase 12 CP2: the admin Referral & Commission module. The Commissions tab
 * (an empty state until commissions are paid), the Rates & caps tab and the
 * edit flow for the qualifying services only (T9), referrals.view and
 * referrals.manage enforced on the routes and again in the action,
 * validation with the pricing input conventions, one append-only history
 * row per real change, stale-form refusal, a first save that loses a race
 * (the real race runs on MariaDB in tests/Concurrency/CommissionSettingRaceTest.php)
 * and escaping of staff-entered text (T17 part 1). Nothing is seeded and no
 * setting is created until staff save one.
 */

const CST_OTHER_SLUGS = ['smile-data', 'airtime-to-cash', 'alpha-topup', 'cable-tv', 'electricity', 'bills-payment', 'withdraw', 'referral-and-commission'];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(ServiceCatalogSeeder::class);
});

function cstStaff(SystemRole|string $role = SystemRole::SuperAdmin, array $attributes = []): SystemUser
{
    $staff = SystemUser::factory()->create($attributes);
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Staff with a custom role holding exactly the given permissions. */
function cstRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'CST '.implode(' ', $permissions), 'guard_name' => 'admin'])->givePermissionTo($permissions);

    return cstStaff($role->name);
}

function cstService(string $slug = 'data'): Service
{
    return Service::where('slug', $slug)->sole();
}

/** A valid edit-form submission for the service as it is now. */
function cstForm(Service $service, array $values = []): array
{
    return $values + ['rate' => '2.5', 'cap' => '100', 'reason' => 'Launch rate agreed with management', 'confirm' => '1',
        'fingerprint' => SaveCommissionSetting::fingerprint($service)];
}

/** Saves through the action, as the edit form would from a fresh page. */
function cstSave(Service $service, int $rateBps, int $capKobo, ?SystemUser $staff = null, string $reason = 'Rate set for this test'): bool
{
    return app(SaveCommissionSetting::class)->handle($service, $rateBps, $capKobo, $reason, $staff ?? cstStaff(), SaveCommissionSetting::fingerprint($service));
}

function cstNothingSaved(): void
{
    expect(CommissionSetting::count())->toBe(0)->and(CommissionSettingChange::count())->toBe(0);
}

/** The opening tag of one Referral & Commission tab link. */
function cstTab(string $html, string $tab): string
{
    preg_match('/<a [^>]*data-referrals-tab="'.$tab.'"[^>]*>/', $html, $match);

    return $match[0] ?? '';
}

describe('module', function () {
    it('is built: sidebar link without the coming-soon mark, built in the permission matrix, no placeholder', function () {
        expect(AdminModule::Referrals->isBuilt())->toBeTrue()
            ->and(AdminModule::Referrals->plannedPhase())->toBeNull()
            ->and(AdminModule::Referrals->routeName())->toBe('admin.referrals')
            ->and(PermissionModule::Referrals->isBuilt())->toBeTrue()
            ->and(array_map(fn (SystemPermission $p) => $p->value, SystemPermission::byModule()['referrals']))->toBe(['referrals.view', 'referrals.manage']);
        $this->actingAs(cstStaff(), 'admin');

        $this->get('/admin')->assertSee('data-nav="referrals"', false)->assertSee('href="'.url('/admin/referrals').'"', false)
            ->assertDontSee('Coming soon (Phase 12)');
        $matrix = $this->get('/admin/roles/create')->assertOk()->assertSee('value="referrals.view"', false)->assertSee('value="referrals.manage"', false)->getContent();
        preg_match('#<fieldset[^>]*data-module="referrals".*?</fieldset>#s', $matrix, $fieldset);
        expect($fieldset[0])->toContain('Referral &amp; Commission')->not->toContain('Not built yet');
        $this->get('/admin/referrals')->assertOk()->assertDontSee('data-placeholder', false)->assertDontSee('is not built yet');
    });

    it('shows the Commissions tab as an empty state', function () {
        $html = $this->actingAs(cstStaff(), 'admin')->get('/admin/referrals')->assertOk()
            ->assertSee('data-commissions-empty', false)->assertSee('No commissions yet')
            ->assertSee('Commissions appear here when referred customers\' purchases of the qualifying services succeed')->getContent(); // CP5: the list's empty state

        expect(cstTab($html, 'commissions'))->toContain('aria-current="page"')
            ->and(cstTab($html, 'rates'))->toContain('href="'.url('/admin/referrals/rates').'"')->not->toContain('aria-current');
        cstNothingSaved();
    });

    it('lists only the five qualifying services, in order, and creates nothing by being opened', function () {
        $html = $this->actingAs(cstStaff(), 'admin')->get('/admin/referrals/rates')->assertOk()
            ->assertSee('No changes yet.')->assertDontSee('Smile')->assertDontSee('Cable TV')->getContent();
        preg_match_all('/data-commission-rate="([^"]+)"/', $html, $rows);
        preg_match_all('/data-not-set/', $html, $notSet);

        expect($rows[1])->toBe(QualifyingServices::SLUGS)->toBe(['data', 'airtime', 'nin', 'bvn', 'exam-pin'])
            ->and($notSet[0])->toHaveCount(5)
            ->and(cstTab($html, 'rates'))->toContain('aria-current="page"')
            ->and(cstTab($html, 'commissions'))->not->toContain('aria-current');
        foreach (QualifyingServices::SLUGS as $slug) {
            expect($html)->toContain('href="'.url("/admin/referrals/rates/{$slug}/edit").'" data-edit-rate="'.$slug.'"');
            $this->get("/admin/referrals/rates/{$slug}/edit")->assertOk()->assertSee('not set (no commission)');
        }
        cstNothingSaved();
    });
});

describe('roles and permissions', function () {
    it('gives referrals.view and referrals.manage to Super Admin only by default', function () {
        foreach (SystemRole::cases() as $role) {
            $staff = cstStaff($role);
            foreach (['referrals.view', 'referrals.manage'] as $permission) {
                expect($staff->can($permission))->toBe($role === SystemRole::SuperAdmin, "{$role->value} {$permission}");
            }
        }
    });

    it('returns 403 on every Referral & Commission route for the other built-in roles', function (SystemRole $role) {
        $service = cstService();
        $this->actingAs(cstStaff($role), 'admin');

        $this->get('/admin')->assertDontSee('data-nav="referrals"', false);
        $this->get('/admin/referrals')->assertForbidden();
        $this->get('/admin/referrals/rates')->assertForbidden();
        $this->get('/admin/referrals/rates/data/edit')->assertForbidden();
        $this->put('/admin/referrals/rates/data', cstForm($service))->assertForbidden();
        cstNothingSaved();
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('lets referrals.view look at both tabs but never change a rate', function () {
        cstSave(cstService(), 250, 10_000);
        $before = CommissionSetting::sole()->toArray();
        $this->actingAs(cstRole(['admin.access', 'referrals.view']), 'admin');

        $this->get('/admin')->assertSee('data-nav="referrals"', false);
        $this->get('/admin/referrals')->assertOk()->assertSee('data-commissions-empty', false);
        $this->get('/admin/referrals/rates')->assertOk()->assertSee('Rate 2.5%')->assertDontSee('data-edit-rate', false);
        $this->get('/admin/referrals/rates/data/edit')->assertForbidden();
        $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => '5']))->assertForbidden();

        expect(CommissionSetting::sole()->toArray())->toBe($before)->and(CommissionSettingChange::count())->toBe(1);
    });

    it('needs referrals.view and admin access alongside referrals.manage', function () {
        $service = cstService();

        $this->actingAs(cstRole(['admin.access', 'referrals.manage']), 'admin');
        $this->get('/admin/referrals/rates')->assertForbidden();
        $this->get('/admin/referrals/rates/data/edit')->assertForbidden();
        $this->put('/admin/referrals/rates/data', cstForm($service))->assertForbidden();

        $this->actingAs(cstRole(['referrals.view', 'referrals.manage']), 'admin');
        $this->get('/admin/referrals/rates')->assertRedirect(route('admin.login'));
        $this->put('/admin/referrals/rates/data', cstForm($service))->assertRedirect(route('admin.login'));
        cstNothingSaved();
    });

    it('lets referrals.view with referrals.manage set rates', function () {
        $this->actingAs(cstRole(['admin.access', 'referrals.view', 'referrals.manage']), 'admin');

        $this->get('/admin/referrals/rates')->assertOk()->assertSee('data-edit-rate="data"', false);
        $this->get('/admin/referrals/rates/data/edit')->assertOk();
        $this->put('/admin/referrals/rates/data', cstForm(cstService()))->assertRedirect(route('admin.referrals.rates'))->assertSessionHasNoErrors();

        expect(CommissionSetting::sole()->rate_bps)->toBe(250);
    });

    it('guards every route with admin access and referrals.view, and the edit routes with referrals.manage, before the action re-checks', function () {
        $middleware = fn (string $name) => Route::getRoutes()->getByName($name)->gatherMiddleware();
        $view = ['web', 'auth:admin', 'staff.active', 'permission:admin.access,admin', 'permission:referrals.view,admin'];

        foreach (['admin.referrals', 'admin.referrals.rates'] as $name) {
            expect($middleware($name))->toBe($view);
        }
        foreach (['admin.referrals.rates.edit', 'admin.referrals.rates.update'] as $name) {
            expect($middleware($name))->toBe([...$view, 'permission:referrals.manage,admin']);
        }
    });

    it('keeps guests and customers out', function () {
        $service = cstService();
        $routes = ['/admin/referrals', '/admin/referrals/rates', '/admin/referrals/rates/data/edit'];

        foreach ($routes as $uri) {
            $this->get($uri)->assertRedirect(route('admin.login'));
        }
        $this->put('/admin/referrals/rates/data', cstForm($service))->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        foreach ($routes as $uri) {
            $this->get($uri)->assertRedirect(route('admin.login'));
        }
        $this->put('/admin/referrals/rates/data', cstForm($service))->assertRedirect(route('admin.login'));
        cstNothingSaved();
    });

    it('signs out disabled staff instead of saving', function () {
        $staff = cstStaff();
        $staff->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($staff, 'admin')->put('/admin/referrals/rates/data', cstForm(cstService()))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->get('/admin/referrals/rates')->assertRedirect(route('admin.login'));
        cstNothingSaved();
    });

    it('re-checks authorization inside the action', function () {
        $service = cstService();
        $disabled = cstStaff();
        $disabled->forceFill(['status' => 'disabled'])->save();
        $removed = cstStaff();
        $removed->delete();

        foreach ([cstStaff(SystemRole::Viewer), cstStaff(SystemRole::Finance), cstRole(['admin.access', 'referrals.view']),
            cstRole(['admin.access', 'referrals.manage']), $disabled, $removed] as $actor) {
            expect(fn () => app(SaveCommissionSetting::class)->handle($service, 250, 10_000, 'Not allowed to do this', $actor, SaveCommissionSetting::fingerprint($service)))
                ->toThrow(AuthorizationException::class, 'You are not allowed to manage referral commissions.');
        }
        cstNothingSaved();
    });
});

describe('validation', function () {
    it('rejects invalid rates', function (mixed $rate, string $message) {
        $this->actingAs(cstStaff(), 'admin')->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => $rate]))
            ->assertSessionHasErrors(['rate' => $message]);
        cstNothingSaved();
    })->with([
        'empty' => ['', 'Enter a rate (use 0 for no commission).'],
        'spaces only' => ['   ', 'Enter a rate (use 0 for no commission).'],
        '100%' => ['100', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'three decimals' => ['2.555', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'negative' => ['-1', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'with a percent sign' => ['2.5%', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'decimal comma' => ['2,5', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'exponent' => ['1e1', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'words' => ['five', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'too long' => ['00000000002', 'Enter a rate from 0 to 99.99 (percent, up to 2 decimal places).'],
        'a list' => [['2'], 'The rate field must be a string.'],
    ]);

    it('rejects invalid caps', function (mixed $cap, string $message) {
        $this->actingAs(cstStaff(), 'admin')->put('/admin/referrals/rates/data', cstForm(cstService(), ['cap' => $cap]))
            ->assertSessionHasErrors(['cap' => $message]);
        cstNothingSaved();
    })->with([
        'empty' => ['', 'Enter a cap in naira (use 0 for no commission).'],
        'negative' => ['-5', 'Enter an amount in naira, e.g. 1,250.50.'],
        'three decimals' => ['1.005', 'Enter an amount in naira, e.g. 1,250.50.'],
        'exponent' => ['1e3', 'Enter an amount in naira, e.g. 1,250.50.'],
        'misplaced comma' => ['1,25', 'Enter an amount in naira, e.g. 1,250.50.'],
        'naira sign' => ['₦100', 'Enter an amount in naira, e.g. 1,250.50.'],
        'above the system maximum' => ['10,000,000.01', 'The cap may not be more than ₦10,000,000.00 (system maximum).'],
        'too long' => [str_repeat('9', 26), 'Enter an amount in naira, e.g. 1,250.50.'],
        'a list' => [['100'], 'The cap field must be a string.'],
    ]);

    it('accepts the boundaries, converting percent to basis points and naira to kobo', function (string $rate, string $cap, int $rateBps, int $capKobo) {
        $this->actingAs(cstStaff(), 'admin')->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => $rate, 'cap' => $cap]))
            ->assertSessionHasNoErrors();

        expect(CommissionSetting::sole()->only(['rate_bps', 'cap_kobo']))->toBe(['rate_bps' => $rateBps, 'cap_kobo' => $capKobo]);
    })->with([
        'zero' => ['0', '0', 0, 0],
        'smallest' => ['0.01', '0.01', 1, 1],
        'largest' => ['99.99', '10,000,000', 9_999, 1_000_000_000],
        'formatted' => [' 2.50 ', '1,250.50', 250, 125_050],
    ]);

    it('limits the cap to the system maximum as that setting changes', function () {
        app(SettingsStore::class)->set(PricingLimits::SETTING, 50_000); // ₦500
        $this->actingAs(cstStaff(), 'admin');

        $this->get('/admin/referrals/rates/data/edit')->assertSee('cap up to ₦500.00 (system setting)');
        $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['cap' => '500.01']))
            ->assertSessionHasErrors(['cap' => 'The cap may not be more than ₦500.00 (system maximum).']);
        cstNothingSaved();
        $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['cap' => '500']))->assertSessionHasNoErrors();

        expect(CommissionSetting::sole()->cap_kobo)->toBe(50_000);
    });

    it('needs a reason of 10 to 500 characters, counted in characters', function () {
        $this->actingAs(cstStaff(), 'admin');
        $put = fn (mixed $reason) => $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['reason' => $reason]));

        $put(null)->assertSessionHasErrors(['reason' => 'Give a reason for this change.']);
        $put('          ')->assertSessionHasErrors(['reason' => 'Give a reason for this change.']);
        $put('Too short')->assertSessionHasErrors(['reason' => 'Give a reason of at least 10 characters (kept in the rate history).']);
        $put(str_repeat('a', 501))->assertSessionHasErrors(['reason' => 'Keep the reason to 500 characters or fewer.']);
        $put(['A reason in a list'])->assertSessionHasErrors('reason');
        $put(str_repeat('ƙ', 9))->assertSessionHasErrors('reason');
        cstNothingSaved();

        $put(str_repeat('ƙ', 10))->assertSessionHasNoErrors(); // 10 characters (20 bytes)
        $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => '3', 'reason' => str_repeat('ɗ', 500)]))->assertSessionHasNoErrors();

        expect(CommissionSettingChange::orderBy('id')->pluck('reason')->all())->toBe([str_repeat('ƙ', 10), str_repeat('ɗ', 500)]);
    });

    it('needs the confirmation and the form fingerprint; the confirmation is never pre-ticked', function () {
        $service = cstService();
        $this->actingAs(cstStaff(), 'admin');

        $this->put('/admin/referrals/rates/data', array_diff_key(cstForm($service), ['confirm' => true]))
            ->assertSessionHasErrors(['confirm' => 'Confirm these values before saving.']);
        foreach ([null, 'abc', str_repeat('a', 41), ['x']] as $fingerprint) {
            $this->put('/admin/referrals/rates/data', cstForm($service, ['fingerprint' => $fingerprint]))
                ->assertSessionHasErrors(['fingerprint' => 'The form has expired. Reload the page and try again.']);
        }
        $this->put('/admin/referrals/rates/data', cstForm($service, ['fingerprint' => str_repeat('a', 40)]))
            ->assertSessionHasErrors(['setting' => SaveCommissionSetting::STALE]);
        cstNothingSaved();

        $this->from('/admin/referrals/rates/data/edit')->put('/admin/referrals/rates/data', cstForm($service, ['rate' => 'abc']))
            ->assertRedirect('/admin/referrals/rates/data/edit');
        $html = $this->get('/admin/referrals/rates/data/edit')->assertSee('value="abc"', false)->assertSee('Launch rate agreed with management')->getContent();
        expect(preg_match('/<input type="checkbox" name="confirm"[^>]*checked/', $html))->toBe(0);
    });

    it('refuses out-of-range values inside the action as well', function (int $rateBps, int $capKobo) {
        expect(fn () => cstSave(cstService(), $rateBps, $capKobo))->toThrow(InvalidArgumentException::class, 'Invalid commission rate or cap.');
        cstNothingSaved();
    })->with([
        'rate of 100%' => [10_000, 100],
        'negative rate' => [-1, 100],
        'negative cap' => [100, -1],
        'cap above the system maximum' => [100, 1_000_000_001],
    ]);
});

describe('saving', function () {
    it('creates the setting on its first save, with exactly one history row, and shows it', function () {
        $staff = cstStaff(attributes: ['name' => 'Amina Yusuf']);
        $service = cstService();
        $this->actingAs($staff, 'admin');

        $this->get('/admin/referrals/rates/data/edit')->assertOk()->assertSee('Data commission')->assertSee('value=""', false);
        $this->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '2.5', 'cap' => '1,250.50']))
            ->assertRedirect(route('admin.referrals.rates'))->assertSessionHas('status', 'Data saved: rate 2.5%, cap ₦1,250.50.');

        $setting = CommissionSetting::sole();
        $change = CommissionSettingChange::sole();
        expect($setting->only(['service_id', 'rate_bps', 'cap_kobo', 'updated_by']))->toBe(['service_id' => $service->id, 'rate_bps' => 250, 'cap_kobo' => 125_050, 'updated_by' => $staff->id])
            ->and($change->only(['commission_setting_id', 'service_id', 'old_rate_bps', 'new_rate_bps', 'old_cap_kobo', 'new_cap_kobo', 'reason', 'changed_by']))->toBe([
                'commission_setting_id' => $setting->id, 'service_id' => $service->id, 'old_rate_bps' => null, 'new_rate_bps' => 250,
                'old_cap_kobo' => null, 'new_cap_kobo' => 125_050, 'reason' => 'Launch rate agreed with management', 'changed_by' => $staff->id,
            ])
            ->and($change->created_at)->not->toBeNull();

        $this->get('/admin/referrals/rates')->assertSee('Data saved: rate 2.5%, cap ₦1,250.50.')
            ->assertSee('Rate 2.5%')->assertSee('cap ₦1,250.50 per purchase')->assertSee('Amina Yusuf')
            ->assertSee('Not set → rate 2.5%, cap ₦1,250.50')->assertSee('>Edit<span class="sr-only">&nbsp;for Data</span>', false)
            ->assertSee('>Set rate<span class="sr-only">&nbsp;for Airtime</span>', false);
        $this->get('/admin/referrals/rates/data/edit')->assertSee('value="2.5"', false)->assertSee('value="1250.50"', false)
            ->assertSee('Now: rate 2.5%, cap ₦1,250.50 per purchase');
    });

    it('records each real change with its old and new values, one row per change', function () {
        $service = cstService();
        $first = cstStaff();
        $second = cstStaff();
        cstSave($service, 250, 10_000, $first);

        $this->actingAs($second, 'admin');
        $this->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '3', 'cap' => '100']))->assertSessionHas('status', 'Data saved: rate 3%, cap ₦100.00.');
        $this->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '3', 'cap' => '150.75']))->assertSessionHasNoErrors();

        $changes = CommissionSettingChange::orderBy('id')->get();
        expect($changes)->toHaveCount(3)
            ->and($changes->map->only(['old_rate_bps', 'new_rate_bps', 'old_cap_kobo', 'new_cap_kobo', 'changed_by'])->all())->toBe([
                ['old_rate_bps' => null, 'new_rate_bps' => 250, 'old_cap_kobo' => null, 'new_cap_kobo' => 10_000, 'changed_by' => $first->id],
                ['old_rate_bps' => 250, 'new_rate_bps' => 300, 'old_cap_kobo' => 10_000, 'new_cap_kobo' => 10_000, 'changed_by' => $second->id],
                ['old_rate_bps' => 300, 'new_rate_bps' => 300, 'old_cap_kobo' => 10_000, 'new_cap_kobo' => 15_075, 'changed_by' => $second->id],
            ])
            ->and(CommissionSetting::sole()->only(['rate_bps', 'cap_kobo', 'updated_by']))->toBe(['rate_bps' => 300, 'cap_kobo' => 15_075, 'updated_by' => $second->id]);
    });

    it('writes nothing when the values are unchanged, however they are typed', function () {
        $service = cstService();
        $first = cstStaff();
        cstSave($service, 250, 10_000, $first);
        $before = CommissionSetting::sole()->toArray();
        $this->travel(5)->minutes();

        $this->actingAs(cstStaff(), 'admin')->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '2.50', 'cap' => '100.00', 'reason' => 'Saving the same values again']))
            ->assertRedirect(route('admin.referrals.rates'))->assertSessionHas('status', 'No changes to save for Data.');

        expect(CommissionSetting::sole()->toArray())->toBe($before)->and(CommissionSetting::sole()->updated_by)->toBe($first->id)
            ->and(CommissionSettingChange::count())->toBe(1)
            ->and(cstSave($service, 250, 10_000))->toBeFalse()
            ->and(CommissionSettingChange::count())->toBe(1);
    });

    it('saves a rate or cap of 0, which means no commission', function () {
        $this->actingAs(cstStaff(), 'admin');

        $this->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => '0', 'cap' => '100']))->assertSessionHas('status', 'Data saved: rate 0%, cap ₦100.00.');
        $this->put('/admin/referrals/rates/airtime', cstForm(cstService('airtime'), ['rate' => '2', 'cap' => '0']))->assertSessionHasNoErrors();

        expect(CommissionSetting::count())->toBe(2)->and(CommissionSettingChange::count())->toBe(2);
        $html = $this->get('/admin/referrals/rates')->getContent();
        preg_match_all('/data-no-commission/', $html, $marks);
        expect($marks[0])->toHaveCount(2);
    });

    it('keeps each service separate', function () {
        cstSave(cstService('nin'), 500, 20_000);
        cstSave(cstService('exam-pin'), 125, 5_000);

        expect(CommissionSetting::with('service')->get()->mapWithKeys(fn ($s) => [$s->service->slug => [$s->rate_bps, $s->cap_kobo]])->all())
            ->toBe(['nin' => [500, 20_000], 'exam-pin' => [125, 5_000]])
            ->and(CommissionSettingChange::with('service')->get()->map(fn ($c) => $c->service->slug)->all())->toBe(['nin', 'exam-pin']);
    });
});

describe('history', function () {
    it('lists changes newest first with service, values, reason, time and staff, also once the staff member is disabled or removed', function () {
        $amina = cstStaff(attributes: ['name' => 'Amina Yusuf']);
        $bello = cstStaff(attributes: ['name' => 'Bello Musa']);
        $this->travelTo(now()->setDateTime(2026, 10, 7, 9, 15));
        cstSave(cstService(), 250, 10_000, $amina, 'First rate for data bundles');
        $this->travelTo(now()->setDateTime(2026, 10, 7, 11, 40));
        cstSave(cstService('bvn'), 100, 5_000, $bello, 'BVN rate for the launch');
        $this->travelTo(now()->setDateTime(2026, 10, 8, 8, 5));
        cstSave(cstService(), 300, 10_000, $bello, 'Raised after the first week');
        $amina->forceFill(['status' => 'disabled'])->save();
        $bello->delete();

        $this->actingAs(cstStaff(), 'admin')->get('/admin/referrals/rates')->assertSeeInOrder([
            'data-commission-history',
            'Data:', 'rate 2.5%, cap ₦100.00 → rate 3%, cap ₦100.00', 'Raised after the first week', '8 Oct 2026, 08:05 · Bello Musa',
            'BVN:', 'Not set → rate 1%, cap ₦50.00', 'BVN rate for the launch', '7 Oct 2026, 11:40 · Bello Musa',
            'Data:', 'Not set → rate 2.5%, cap ₦100.00', 'First rate for data bundles', '7 Oct 2026, 09:15 · Amina Yusuf',
        ]);
    });

    it('pages the history 25 changes at a time', function () {
        $staff = cstStaff();
        foreach (range(1, 26) as $i) {
            cstSave(cstService(), $i, 10_000, $staff, "Change number {$i} for paging");
        }
        $this->actingAs($staff, 'admin');

        $first = $this->get('/admin/referrals/rates')->assertSee('Showing 1–25 of 26')->assertSee('Change number 26 for paging')->getContent();
        expect($first)->not->toContain('Change number 1 for paging<');
        $this->get('/admin/referrals/rates?history=2')->assertSee('Showing 26–26 of 26')->assertSee('Change number 1 for paging')
            ->assertDontSee('Change number 2 for paging');
    });

    it('escapes staff-entered reasons and names wherever they are shown (T17 part 1)', function () {
        $reason = '<script>alert("x")</script> & <b>bold</b>';
        $staff = cstStaff(attributes: ['name' => '<img src=x onerror=alert(1)>']);
        cstSave(cstService(), 250, 10_000, $staff, $reason);
        $this->actingAs(cstStaff(), 'admin');

        $html = $this->get('/admin/referrals/rates')->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->getContent();
        expect($html)->not->toContain('<script>alert')->not->toContain('<b>bold</b>')->not->toContain('<img src=x');

        $this->from('/admin/referrals/rates/data/edit')->put('/admin/referrals/rates/data', cstForm(cstService(), ['rate' => 'abc', 'reason' => '</textarea><script>alert(2)</script>']));
        $edit = $this->get('/admin/referrals/rates/data/edit')->assertSee('&lt;/textarea&gt;&lt;script&gt;alert(2)&lt;/script&gt;', false)->getContent();
        expect($edit)->not->toContain('<script>alert(2)');
    });
});

describe('stale forms and racing first saves', function () {
    it('refuses a first-save form when someone else saved the service in the meantime, keeping their values', function () {
        $service = cstService();
        $stale = SaveCommissionSetting::fingerprint($service);
        cstSave($service, 400, 20_000); // someone else, meanwhile
        $this->actingAs(cstStaff(), 'admin');

        $this->from('/admin/referrals/rates/data/edit')->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '2.5', 'fingerprint' => $stale]))
            ->assertRedirect('/admin/referrals/rates/data/edit')->assertSessionHasErrors(['setting' => SaveCommissionSetting::STALE]);

        expect(CommissionSetting::sole()->only(['rate_bps', 'cap_kobo']))->toBe(['rate_bps' => 400, 'cap_kobo' => 20_000])
            ->and(CommissionSettingChange::count())->toBe(1);
    });

    it('refuses an edit form opened before someone else changed the values', function () {
        $service = cstService();
        cstSave($service, 250, 10_000);
        $stale = SaveCommissionSetting::fingerprint($service);
        cstSave($service, 400, 10_000);

        $this->actingAs(cstStaff(), 'admin')->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '3', 'fingerprint' => $stale]))
            ->assertSessionHasErrors(['setting' => SaveCommissionSetting::STALE]);

        expect(CommissionSetting::sole()->rate_bps)->toBe(400)->and(CommissionSettingChange::count())->toBe(2);
    });

    it('still refuses the stale form when the values were changed and changed back', function () {
        $service = cstService();
        cstSave($service, 250, 10_000);
        $stale = SaveCommissionSetting::fingerprint($service);
        $this->travel(2)->minutes();
        cstSave($service, 400, 10_000);
        $this->travel(2)->minutes();
        cstSave($service, 250, 10_000);

        expect(fn () => app(SaveCommissionSetting::class)->handle($service, 300, 10_000, 'Edited on an old page', cstStaff(), $stale))
            ->toThrow(ValidationException::class, SaveCommissionSetting::STALE);
        expect(CommissionSetting::sole()->rate_bps)->toBe(250)->and(CommissionSettingChange::count())->toBe(3);
    });

    it('shows the values saved now after a stale refusal, never the refused ones', function () {
        $service = cstService();
        $stale = SaveCommissionSetting::fingerprint($service);
        cstSave($service, 400, 20_000);
        $this->actingAs(cstStaff(), 'admin');

        $this->from('/admin/referrals/rates/data/edit')->put('/admin/referrals/rates/data', cstForm($service, ['rate' => '7.25', 'cap' => '999', 'fingerprint' => $stale]));
        $html = $this->get('/admin/referrals/rates/data/edit')->assertSee('data-stale', false)->assertSee(SaveCommissionSetting::STALE)
            ->assertSee('value="4"', false)->assertSee('value="200.00"', false)->assertDontSee('value="7.25"', false)->assertDontSee('value="999"', false)
            ->assertSee('value="'.SaveCommissionSetting::fingerprint($service).'"', false)->getContent();
        expect(preg_match('/<input type="checkbox" name="confirm"[^>]*checked/', $html))->toBe(0);
    });

    it('turns a first save that collides with another first save on the unique service key into the reload message', function () {
        $service = cstService();
        $other = cstStaff();
        $fingerprint = SaveCommissionSetting::fingerprint($service);
        // Another request inserts this service's setting just before ours does.
        CommissionSetting::creating(fn () => DB::table('commission_settings')->insert(['service_id' => $service->id, 'rate_bps' => 400, 'cap_kobo' => 20_000,
            'updated_by' => $other->id, 'created_at' => now(), 'updated_at' => now()]));

        expect(fn () => app(SaveCommissionSetting::class)->handle($service, 250, 10_000, 'Losing the first-save race', cstStaff(), $fingerprint))
            ->toThrow(ValidationException::class, SaveCommissionSetting::STALE);
        expect(CommissionSettingChange::count())->toBe(0);
    });
});

describe('only the qualifying services (T9)', function () {
    it('returns 404 for editing or saving any other service, by slug, case or id, and saves nothing', function () {
        $this->actingAs(cstStaff(), 'admin');

        foreach ([...CST_OTHER_SLUGS, 'unknown-service', 'DATA', 'Exam-PIN', (string) cstService()->id, (string) cstService('smile-data')->id] as $key) {
            $form = cstForm(Service::where('slug', strtolower($key))->first() ?? cstService());
            $this->get("/admin/referrals/rates/{$key}/edit")->assertNotFound();
            $this->put("/admin/referrals/rates/{$key}", $form)->assertNotFound();
            $this->patch("/admin/referrals/rates/{$key}", $form)->assertNotFound();
            $this->delete("/admin/referrals/rates/{$key}")->assertNotFound();
        }
        // A qualifying service has no other way in either: no patch or delete route.
        $this->patch('/admin/referrals/rates/data', cstForm(cstService()))->assertStatus(405);
        $this->delete('/admin/referrals/rates/data')->assertStatus(405);
        cstNothingSaved();
    });

    it('accepts only the qualifying slugs in the edit routes themselves', function () {
        foreach (['admin.referrals.rates.edit', 'admin.referrals.rates.update'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            expect($route->wheres)->toBe(['service' => 'data|airtime|nin|bvn|exam-pin'])
                ->and($route->bindingFieldFor('service'))->toBe('slug');
        }
    });

    it('refuses other services inside the controller and the action as well', function () {
        $controller = app(CommissionSettingController::class);
        $save = app(SaveCommissionSetting::class);

        foreach (CST_OTHER_SLUGS as $slug) {
            $service = cstService($slug);
            expect(fn () => $controller->edit($service))->toThrow(NotFoundHttpException::class)
                ->and(fn () => $controller->update(new CommissionSettingRequest, $service, $save))->toThrow(NotFoundHttpException::class)
                ->and(fn () => $save->handle($service, 250, 10_000, 'Trying a service that never earns', cstStaff(), SaveCommissionSetting::fingerprint($service)))
                ->toThrow(InvalidArgumentException::class, 'Referral commission applies only to the qualifying services.');
        }
        cstNothingSaved();
    });
});

describe('scope', function () {
    it('seeds no commission setting or history, and the pages never create one', function () {
        $this->seed();
        $this->actingAs(cstStaff(), 'admin');

        $this->get('/admin/referrals')->assertOk();
        $this->get('/admin/referrals/rates')->assertOk();
        foreach (QualifyingServices::SLUGS as $slug) {
            $this->get("/admin/referrals/rates/{$slug}/edit")->assertOk();
        }
        cstNothingSaved();
        expect(SystemPermission::cases())->toHaveCount(46);
    });
});
