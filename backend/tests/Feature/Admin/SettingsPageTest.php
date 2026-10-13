<?php

use App\Actions\Settings\UpdateSettings;
use App\Models\Setting;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SettingType;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SettingsSeeder::class]);
});

function validSettings(array $overrides = []): array
{
    return ['settings' => array_merge([
        'app__name' => 'Nadabo Global Data',
        'app__currency' => 'NGN',
        'app__currency_symbol' => '₦',
        'app__timezone' => 'Africa/Lagos',
        'app__maintenance_mode' => '0',
        'pricing__max_amount_kobo' => '1000000000',
        'payments__min_funding_kobo' => '10000',
        'payments__max_funding_kobo' => '50000000',
        'payments__pending_expiry_minutes' => '60',
        'payments__live_enabled' => '0',
    ], $overrides)];
}

function superAdmin(): SystemUser
{
    return SystemUser::factory()->withRole(SystemRole::SuperAdmin)->create();
}

it('shows grouped settings to super admin inside the admin layout', function () {
    $this->actingAs(superAdmin(), 'admin')
        ->get('/admin/settings')
        ->assertOk()
        ->assertSee('data-settings-group="app"', false)
        ->assertSee('General')
        ->assertSee('Platform name')
        ->assertSee('value="Nadabo Global Data"', false)
        ->assertSee('data-nav="settings"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('Save settings')
        ->assertDontSee('is not built yet');
});

it('forbids the settings page and updates for roles without settings permissions', function (SystemRole $role) {
    $staff = SystemUser::factory()->withRole($role)->create();

    $this->actingAs($staff, 'admin')->get('/admin/settings')->assertForbidden();
    $this->actingAs($staff, 'admin')->put('/admin/settings', validSettings(['app__name' => 'Hacked']))->assertForbidden();

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo Global Data');
})->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('lets staff with settings.view only read, not save', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::Viewer)->create();
    $staff->givePermissionTo(SystemPermission::SettingsView->value);

    $this->actingAs($staff, 'admin')->get('/admin/settings')
        ->assertOk()
        ->assertSee('You can view settings but not change them.')
        ->assertDontSee('Save settings');

    $this->actingAs($staff, 'admin')->put('/admin/settings', validSettings(['app__name' => 'Changed']))->assertForbidden();

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo Global Data');
});

it('lets staff granted settings.view and settings.update save', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::Manager)->create();
    $staff->givePermissionTo([SystemPermission::SettingsView->value, SystemPermission::SettingsUpdate->value]);

    $this->actingAs($staff, 'admin')->put('/admin/settings', validSettings(['app__name' => 'Nadabo NG']))
        ->assertRedirect(route('admin.settings'));

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo NG');
});

it('saves valid changes, records who made them and shows success', function () {
    $super = superAdmin();

    $this->actingAs($super, 'admin')
        ->put('/admin/settings', validSettings(['app__name' => 'Nadabo Data', 'app__maintenance_mode' => '1', 'app__timezone' => 'UTC']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings'))
        ->assertSessionHas('status', 'Settings saved.');

    $store = app(SettingsStore::class);
    expect($store->get('app.name'))->toBe('Nadabo Data')
        ->and($store->get('app.maintenance_mode'))->toBeTrue()
        ->and($store->get('app.timezone'))->toBe('UTC')
        ->and(Setting::firstWhere('key', 'app.name')->updated_by)->toBe($super->id);

    $this->get('/admin/settings')->assertSee('Settings saved.')->assertSee('value="Nadabo Data"', false);
});

it('rejects invalid values and saves nothing', function () {
    $this->actingAs(superAdmin(), 'admin')
        ->from('/admin/settings')
        ->put('/admin/settings', validSettings([
            'app__name' => 'Valid New Name',
            'app__currency' => 'naira',
            'app__timezone' => 'Mars/Olympus',
            'app__maintenance_mode' => 'maybe',
        ]))
        ->assertRedirect('/admin/settings')
        ->assertSessionHasErrors(['settings.app__currency', 'settings.app__timezone', 'settings.app__maintenance_mode']);

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo Global Data');

    $this->get('/admin/settings')->assertSee('Settings were not saved.');
});

it('requires values for required settings', function () {
    $this->actingAs(superAdmin(), 'admin')
        ->put('/admin/settings', validSettings(['app__name' => '']))
        ->assertSessionHasErrors('settings.app__name');
});

it('ignores unknown keys instead of creating settings', function () {
    $this->actingAs(superAdmin(), 'admin')
        ->put('/admin/settings', validSettings(['payments__secret_key' => 'not-a-real-key']))
        ->assertSessionHasNoErrors();

    expect(Setting::where('key', 'payments.secret_key')->exists())->toBeFalse()
        ->and(Setting::count())->toBe(10);
});

it('never shows encrypted values and keeps them when left blank', function () {
    Setting::create(['key' => 'demo.api_secret', 'type' => SettingType::String, 'group' => 'demo', 'label' => 'Demo secret', 'is_encrypted' => true]);
    app(SettingsStore::class)->set('demo.api_secret', 'not-a-real-secret');

    $super = superAdmin();
    $this->actingAs($super, 'admin')->get('/admin/settings')
        ->assertOk()
        ->assertSee('Demo secret')
        ->assertDontSee('not-a-real-secret')
        ->assertSee('leave blank to keep');

    $this->actingAs($super, 'admin')->put('/admin/settings', validSettings(['demo__api_secret' => '']))->assertSessionHasNoErrors();

    expect(app(SettingsStore::class)->get('demo.api_secret'))->toBe('not-a-real-secret');
});

it('keeps customers and guests out of settings', function () {
    $this->put('/admin/settings', validSettings())->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create(), 'web')->get('/admin/settings')->assertRedirect(route('admin.login'));
    $this->actingAs(User::factory()->create(), 'web')->put('/admin/settings', validSettings(['app__name' => 'x']))->assertRedirect(route('admin.login'));

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo Global Data');
});

it('keeps disabled super admins out of settings', function () {
    $super = SystemUser::factory()->disabled()->withRole(SystemRole::SuperAdmin)->create();

    $this->actingAs($super, 'admin')->put('/admin/settings', validSettings(['app__name' => 'x']))->assertRedirect(route('admin.login'));

    expect(app(SettingsStore::class)->get('app.name'))->toBe('Nadabo Global Data');
});

it('refuses the update action for staff without settings.update', function () {
    $staff = SystemUser::factory()->withRole(SystemRole::Manager)->create();

    expect(fn () => app(UpdateSettings::class)->handle(['app.name' => 'x'], $staff))
        ->toThrow(AuthorizationException::class);
});
