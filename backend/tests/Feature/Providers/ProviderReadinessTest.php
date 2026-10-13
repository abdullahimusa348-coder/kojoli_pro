<?php

use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderService;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\Wallet;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Providers\RouteReadiness;
use App\Services\Providers\RouteResolver;
use App\Support\Enums\SystemRole;
use App\Support\Providers\CredentialKey;
use App\Support\Purchases\PurchaseStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;
use Tests\Support\Providers\HttpTestProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 2, CP1: route readiness. A plan route is runnable only when
 * Phase 7 calls it eligible AND its provider's adapter is installed, supports
 * the service, has every credential it needs and accepts the configured base
 * URL host. Test-only adapters; no real provider, credentials or HTTP calls.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    config(['providers.drivers' => ['fake-provider' => FakeProvider::class, 'http-test-provider' => HttpTestProvider::class]]);
    Http::preventStrayRequests();
});

/** @return list<RouteReadiness> */
function rdReadiness(Plan $plan): array
{
    $registry = app(ProviderAdapterRegistry::class);

    return array_map(fn ($c) => $registry->routeReadiness($c), app(RouteResolver::class)->candidatesFor($plan->fresh()));
}

function rdBaseUrl(Provider $provider, ?string $url): void
{
    $provider->forceFill(['settings' => array_merge($provider->settings ?? [], ['base_url' => $url])])->save();
}

/** A data plan with one FakeProvider route that can run. */
function rdPlan(): Plan
{
    $plan = puxPlan('data', 50_000);
    puxRoute($plan);

    return $plan->fresh();
}

function rdProvider(Plan $plan, int $priority = 1): Provider
{
    return $plan->providerRoutes()->where('priority', $priority)->sole()->provider;
}

function rdStaff(?array $permissions = null): SystemUser
{
    $staff = SystemUser::factory()->create();
    if ($permissions === null) {
        $staff->assignRole(SystemRole::SuperAdmin->value);
    } else {
        $staff->assignRole(Role::create(['name' => 'RD '.implode(' ', $permissions), 'guard_name' => 'admin'])
            ->givePermissionTo(['admin.access', ...$permissions]));
    }

    return $staff;
}

describe('runnable routes', function () {
    it('is runnable when eligible, the adapter is installed, supports the service and has its credentials', function () {
        $plan = rdPlan();

        expect(rdReadiness($plan)[0]->runnable())->toBeTrue()
            ->and(rdReadiness($plan)[0]->reasons)->toBe([])
            ->and(app(ProviderAdapterRegistry::class)->executableFor($plan))->toHaveCount(1);
    });

    it('accepts a base URL on a host the adapter declares', function (string $url) {
        $plan = rdPlan();
        rdBaseUrl(rdProvider($plan), $url);

        expect(rdReadiness($plan)[0]->runnable())->toBeTrue();
    })->with([
        'host only' => 'https://api.fake-provider.test',
        'with a path' => 'https://api.fake-provider.test/v1/',
        'different case' => 'https://API.Fake-Provider.test',
    ]);

    it('is not runnable when the base URL is not on a declared https host, although Phase 7 still calls it eligible', function (string $url) {
        $plan = rdPlan();
        rdBaseUrl(rdProvider($plan), $url);

        expect(app(RouteResolver::class)->eligibleFor($plan->fresh()))->toHaveCount(1)
            ->and(rdReadiness($plan)[0]->reasons)->toBe(['The base URL is not on a host this adapter may call (https only, no port).'])
            ->and(app(ProviderAdapterRegistry::class)->executableFor($plan->fresh()))->toBe([]);
    })->with([
        'undeclared host' => 'https://other-host.example',
        'plain http' => 'http://api.fake-provider.test',
        'port' => 'https://api.fake-provider.test:8443',
        'lookalike host' => 'https://api.fake-provider.test.other-host.example',
        'login in the URL' => 'https://user@api.fake-provider.test',
        'IP address' => 'https://169.254.169.254',
        'malformed' => 'https:///no-host',
    ]);

    it('is not runnable without an installed adapter', function (?string $driver, string $reason) {
        $plan = rdPlan();
        rdProvider($plan)->forceFill(['driver' => $driver])->save();

        $readiness = app(ProviderAdapterRegistry::class)->providerReadiness(rdProvider($plan));
        expect(rdReadiness($plan)[0]->reasons)->toBe([$reason])
            ->and($readiness->installed)->toBeFalse()
            ->and($readiness->ready())->toBeFalse()
            ->and($readiness->services)->toBe([])
            ->and($readiness->credentialKeys)->toBe([]);
    })->with([
        'no driver' => [null, 'No driver is set, so no adapter can run this provider.'],
        'empty driver' => ['', 'No driver is set, so no adapter can run this provider.'],
        'driver without an adapter' => ['not_installed', 'No adapter is installed for driver “not_installed”.'],
    ]);

    it('reports the installed adapter, the services it supports and the credentials it needs', function () {
        $plan = rdPlan();

        $readiness = app(ProviderAdapterRegistry::class)->providerReadiness(rdProvider($plan));
        expect($readiness->installed)->toBeTrue()
            ->and($readiness->ready())->toBeTrue()
            ->and($readiness->label)->toBe('Fake test provider')
            ->and($readiness->services)->toBe(['data', 'airtime'])
            ->and($readiness->credentialKeys)->toBe([CredentialKey::ApiKey])
            ->and($readiness->missingCredentialKeys)->toBe([])
            ->and($readiness->baseUrlAllowed)->toBeNull()
            ->and($readiness->canQuery)->toBeTrue();
    });

    it('is not runnable when the adapter does not support the plan service', function () {
        $plan = puxPlan('other-service', 50_000);
        puxRoute($plan);

        expect(app(RouteResolver::class)->eligibleFor($plan->fresh()))->toHaveCount(1)
            ->and(rdReadiness($plan)[0]->reasons)->toBe(['The adapter does not support the Other Service service.']);
    });

    it('requires every credential the adapter needs, even when Phase 7 calls the provider configured', function () {
        $plan = rdPlan();
        $provider = rdProvider($plan);
        // The administrator declared only a token as required, so Phase 7 is satisfied; the adapter needs an API key.
        ProviderCredential::where('provider_id', $provider->id)->delete();
        $provider->forceFill(['settings' => ['required_credentials' => [CredentialKey::Token->value]]])->save();
        (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => CredentialKey::Token, 'value' => 'fake-token-NOT-REAL-rd1', 'hint' => 'rd1x'])->save();

        expect(app(RouteResolver::class)->eligibleFor($plan->fresh()))->toHaveCount(1)
            ->and(rdReadiness($plan)[0]->reasons)->toBe(['Credentials the adapter needs are not set: API key.'])
            ->and(app(ProviderAdapterRegistry::class)->providerReadiness($provider->fresh())->missingCredentialKeys)->toBe([CredentialKey::ApiKey]);

        (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => CredentialKey::ApiKey, 'value' => PUX_KEY, 'hint' => 'cp3x'])->save();
        expect(rdReadiness($plan)[0]->runnable())->toBeTrue();
    });

    it('names each missing credential of another adapter', function () {
        $plan = puxPlan('airtime', 0, true);
        puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300], 'http-test-provider');

        expect(rdReadiness($plan)[0]->reasons)->toBe(['Credentials the adapter needs are not set: Secret key.']);
    });

    it('keeps credential values out of readiness results', function () {
        $plan = rdPlan();
        $registry = app(ProviderAdapterRegistry::class);

        expect(print_r($registry->providerReadiness(rdProvider($plan)), true))->not->toContain(PUX_KEY)
            ->and(print_r(rdReadiness($plan), true))->not->toContain(PUX_KEY);
    });

    it('lists the Phase 7 reasons first, then the adapter reasons', function () {
        $plan = rdPlan();
        $plan->providerRoutes()->sole()->forceFill(['is_active' => false])->save();
        rdProvider($plan)->forceFill(['driver' => 'not_installed'])->save();

        expect(rdReadiness($plan)[0]->runnable())->toBeFalse()
            ->and(rdReadiness($plan)[0]->reasons)->toBe(['Route disabled.', 'No adapter is installed for driver “not_installed”.']);
    });

    it('keeps priority order and drops only the routes that cannot run', function () {
        $plan = rdPlan();
        puxRoute($plan, 2);
        puxRoute($plan, 3);
        rdBaseUrl(rdProvider($plan, 2), 'https://other-host.example');

        expect(array_map(fn ($c) => $c->route->priority, app(ProviderAdapterRegistry::class)->executableFor($plan->fresh())))->toBe([1, 3]);
    });
});

describe('purchases', function () {
    it('does not sell a plan whose only route cannot run, and debits nothing', function () {
        $plan = rdPlan();
        rdBaseUrl(rdProvider($plan), 'https://other-host.example');
        $user = puxCustomer(100_000);

        expect(fn () => puxService()->purchase($user, $plan->fresh(), '08012345678', null, 'rd-key-1'))
            ->toThrow(PurchaseException::class, 'This plan is not available right now.');
        expect(Purchase::count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000)
            ->and(FakeProvider::$calls)->toBe([]);

        $this->actingAs($user, 'web');
        $this->get('/buy/data')->assertOk()->assertSee('Not available right now');
    });

    it('never calls a provider for a pending purchase whose only route stopped being runnable', function () {
        $plan = rdPlan();
        $user = puxCustomer(100_000);
        $purchase = puxService()->create($user, $plan, '08012345678', null, 'rd-key-2');
        rdBaseUrl(rdProvider($plan), 'https://other-host.example');

        $after = puxService()->execute($purchase);

        expect($after->status)->toBe(PurchaseStatus::Failed)
            ->and(FakeProvider::$calls)->toBe([])
            ->and(Wallet::where('user_id', $user->id)->sole()->balance_kobo)->toBe(100_000);
    });
});

describe('admin readiness pages', function () {
    it('shows a provider without an installed adapter as not ready', function () {
        $plan = rdPlan();
        rdProvider($plan)->forceFill(['driver' => 'not_installed'])->save();
        $this->actingAs(rdStaff(), 'admin');

        $this->get('/admin/providers/'.rdProvider($plan)->id)->assertOk()
            ->assertSee('data-adapter="none"', false)
            ->assertSee('data-adapter-readiness="not-ready"', false)
            ->assertSee('data-adapter-installed="no"', false)
            ->assertSee('No adapter is installed for driver “not_installed”.')
            ->assertDontSee('data-capability-adapter', false);
    });

    it('shows an installed, ready adapter with its services, credentials and status check', function () {
        $plan = rdPlan();
        $this->actingAs(rdStaff(), 'admin');

        $this->get('/admin/providers/'.rdProvider($plan)->id)->assertOk()
            ->assertSee('data-adapter="installed"', false)
            ->assertSee('data-adapter-readiness="ready"', false)
            ->assertSee('Installed · Fake test provider')
            ->assertSee('data-adapter-query="yes"', false)
            ->assertSeeInOrder(['data-adapter-services', 'Data, airtime'], false)
            ->assertSee('data-adapter-credential="api_key" data-state="set"', false)
            ->assertSee('data-adapter-base-url="not-set"', false)
            ->assertSee('data-capability-adapter="yes"', false)
            ->assertDontSee('data-adapter-problems', false);
    });

    it('shows missing adapter credentials, a base URL the adapter cannot call and services it does not support', function () {
        $plan = rdPlan();
        $provider = rdProvider($plan);
        ProviderCredential::where('provider_id', $provider->id)->delete();
        rdBaseUrl($provider, 'https://other-host.example/api');
        $other = puxPlan('other-service', 50_000);
        (new ProviderService)->forceFill(['provider_id' => $provider->id, 'service_id' => $other->product->service_id,
            'requires_plan_code' => true, 'is_active' => true])->save();
        $this->actingAs(rdStaff(), 'admin');

        $this->get('/admin/providers/'.$provider->id)->assertOk()
            ->assertSee('data-adapter-readiness="not-ready"', false)
            ->assertSee('data-adapter-credential="api_key" data-state="missing"', false)
            ->assertSee('data-adapter-base-url="not-allowed"', false)
            ->assertSee('Credentials the adapter needs are not set: API key.')
            ->assertSee('The base URL is not on a host this adapter may call (https only, no port).')
            ->assertSee('data-capability-adapter="no"', false)
            ->assertSee('Adapter does not support it');
    });

    it('marks installed adapters in the provider list', function () {
        $plan = rdPlan();
        $missing = puxRoute(puxPlan('data', 50_000), 1, ['cost_type' => 'fixed', 'cost_kobo' => 45_000], 'not_installed')->provider;
        $this->actingAs(rdStaff(), 'admin');

        $this->get('/admin/providers')->assertOk()
            ->assertSeeInOrder(['data-provider="'.rdProvider($plan)->code.'"', 'data-adapter="installed"'], false)
            ->assertSeeInOrder(['data-provider="'.$missing->code.'"', 'data-adapter="none"'], false);
    });

    it('shows on the routes page which routes can run now and why the others cannot', function () {
        $plan = rdPlan();
        puxRoute($plan, 2);
        rdProvider($plan, 2)->forceFill(['driver' => 'not_installed'])->save();
        $this->actingAs(rdStaff(), 'admin');

        $response = $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk();
        $response->assertSee('Eligible order: 1 '.rdProvider($plan)->name.' → 2 '.rdProvider($plan, 2)->name)
            ->assertSee('Runnable now: 1 '.rdProvider($plan)->name)
            ->assertSee('data-preview-runnable="1"', false)
            ->assertSeeInOrder(['data-priority="1"', 'data-route-runnable="yes"', 'data-priority="2"', 'data-route-runnable="no"', 'No adapter is installed for driver “not_installed”.'], false);
    });

    it('says when no route of a plan can run', function () {
        $plan = rdPlan();
        rdBaseUrl(rdProvider($plan), 'https://other-host.example');
        $this->actingAs(rdStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk()
            ->assertSee('data-preview-runnable="0"', false)
            ->assertSee('Runnable now: none. Customers cannot buy this plan until a route can run.')
            ->assertSee('The base URL is not on a host this adapter may call (https only, no port).');
    });

    it('keeps readiness behind the provider permissions', function () {
        $plan = rdPlan();
        $provider = rdProvider($plan);

        $this->actingAs(rdStaff(['services.view']), 'admin');
        $this->get('/admin/providers/'.$provider->id)->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertForbidden();

        $this->actingAs(rdStaff(['services.view', 'providers.view']), 'admin');
        $this->get('/admin/providers/'.$provider->id)->assertOk()->assertSee('data-adapter-readiness="ready"', false)
            ->assertDontSee('data-credentials-form', false);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk()->assertSee('data-route-runnable="yes"', false);
    });

    it('never shows credential values or the adapter hosts on readiness pages', function () {
        $plan = rdPlan();
        $provider = rdProvider($plan);
        $this->actingAs(rdStaff(), 'admin');

        foreach (['/admin/providers', '/admin/providers/'.$provider->id, "/admin/services/plans/{$plan->id}/routes"] as $url) {
            $this->get($url)->assertOk()->assertDontSee(PUX_KEY)->assertDontSee('api.fake-provider.test');
        }
    });
});

describe('customer pages', function () {
    it('never show provider names, hosts, codes or readiness details', function () {
        $plan = rdPlan();
        rdProvider($plan)->forceFill(['name' => 'Hidden Runnable Provider'])->save();
        puxRoute($plan, 2);
        rdProvider($plan, 2)->forceFill(['name' => 'Hidden Broken Provider'])->save();
        rdBaseUrl(rdProvider($plan, 2), 'https://other-host.example');
        $this->actingAs(puxCustomer(100_000), 'web');

        foreach ([$this->get('/buy/data'), $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])] as $response) {
            $response->assertOk()->assertSee($plan->name);
            foreach (['Hidden Runnable Provider', 'Hidden Broken Provider', 'other-host.example', 'api.fake-provider.test', 'CODE1', 'CODE2',
                'fake-provider', 'adapter', 'Runnable', 'base URL'] as $hidden) {
                $response->assertDontSee($hidden);
            }
        }
    });
});
