<?php

use App\Actions\Admin\Providers\BulkAddRoutes;
use App\Actions\Admin\Providers\SavePlanRoute;
use App\Actions\Admin\Providers\SaveProvider;
use App\Actions\Admin\Providers\SaveProviderCredentials;
use App\Actions\Admin\Providers\SaveProviderService;
use App\Actions\Admin\Providers\SetProviderStatus;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanProviderRoute;
use App\Models\PlanProviderRouteChange;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderCredentialChange;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Providers\RouteResolver;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Obviously fake test secret: must never appear in any output. */
const PV_SECRET = 'fake-test-secret-NOT-REAL-7c1e';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    Http::preventStrayRequests();
});

function pvStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Custom role holding exactly the given permissions (plus admin.access). */
function pvRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'PV '.implode(' ', $permissions), 'guard_name' => 'admin'])
        ->givePermissionTo(['admin.access', ...$permissions]);

    return pvStaff($role->name);
}

/** Active fixed plan in an active chain (service "data"). */
function pvPlan(string $name = '1GB', ?Product $product = null, array $attributes = []): Plan
{
    $product ??= Product::factory()->create(['service_id' => Service::factory()->create(['name' => 'Data', 'slug' => 'data'])->id, 'name' => 'MTN', 'code' => 'data-mtn', 'network' => 'mtn']);

    return Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => $name, 'code' => $product->code.'-'.Str::slug($name)]);
}

function pvVariablePlan(): Plan
{
    $product = Product::factory()->create(['service_id' => Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime'])->id, 'name' => 'MTN', 'code' => 'airtime-mtn']);

    return pvPlan('VTU', $product, ['amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 5_000_000]);
}

function pvProvider(string $name = 'Alpha Provider', string $status = 'active', bool $configured = true): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'code' => Str::slug($name), 'status' => $status]);
    if ($configured) {
        $provider->forceFill(['settings' => ['required_credentials' => ['api_key']]])->save();
        (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => CredentialKey::ApiKey, 'value' => 'fake-test-key-0000', 'hint' => '0000'])->save();
    }

    return $provider;
}

function pvCapability(Provider $provider, Service $service, bool $requiresCode = true, bool $active = true): ProviderService
{
    return tap((new ProviderService)->forceFill(['provider_id' => $provider->id, 'service_id' => $service->id, 'requires_plan_code' => $requiresCode, 'is_active' => $active]))->save();
}

function pvRoute(Plan $plan, Provider $provider, int $priority, array $attributes = []): PlanProviderRoute
{
    return tap((new PlanProviderRoute)->forceFill($attributes + ['plan_id' => $plan->id, 'provider_id' => $provider->id, 'priority' => $priority, 'provider_plan_code' => 'CODE'.$priority, 'is_active' => true]))->save();
}

function candidates(Plan $plan): array
{
    return app(RouteResolver::class)->candidatesFor(Plan::find($plan->id));
}

describe('schema', function () {
    it('creates the provider tables', function () {
        expect(Schema::hasColumns('providers', ['id', 'name', 'code', 'description', 'status', 'driver', 'settings', 'sort_order', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('provider_services', ['provider_id', 'service_id', 'is_active', 'requires_plan_code', 'provider_service_code']))->toBeTrue()
            ->and(Schema::hasColumns('plan_provider_routes', ['plan_id', 'provider_id', 'priority', 'provider_plan_code', 'is_active', 'cost_type', 'cost_kobo', 'cost_discount_bps', 'updated_by']))->toBeTrue()
            ->and(Schema::hasColumns('plan_provider_route_changes', ['plan_provider_route_id', 'event', 'old_priority', 'new_priority', 'old_cost_kobo', 'new_cost_kobo', 'changed_by', 'created_at']))->toBeTrue()
            ->and(Schema::hasColumns('provider_credentials', ['provider_id', 'key', 'value', 'hint', 'updated_by']))->toBeTrue()
            ->and(Schema::hasColumns('provider_credential_changes', ['provider_id', 'key', 'action', 'changed_by', 'created_at']))->toBeTrue();
    });

    it('keeps secrets out of providers and history, and provider data out of the catalog and pricing', function () {
        foreach (['api_key', 'secret', 'password', 'token', 'credentials'] as $column) {
            expect(Schema::hasColumn('providers', $column))->toBeFalse("providers.{$column} exists");
        }
        foreach (['value', 'hint'] as $column) {
            expect(Schema::hasColumn('provider_credential_changes', $column))->toBeFalse("history stores {$column}");
        }
        foreach (['plans', 'plan_prices', 'products', 'services'] as $table) {
            foreach (['provider_id', 'provider_plan_code', 'cost_kobo', 'cost_type', 'cost_discount_bps', 'priority'] as $column) {
                expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} exists");
            }
        }
    });

    it('enforces unique capabilities, one route per provider per plan and unique priorities', function () {
        $plan = pvPlan();
        $a = pvProvider('Alpha');
        $b = pvProvider('Beta');
        pvCapability($a, $plan->product->service);
        pvRoute($plan, $a, 1);

        expect(fn () => pvCapability($a, $plan->product->service))->toThrow(QueryException::class)
            ->and(fn () => pvRoute($plan, $a, 2))->toThrow(QueryException::class)
            ->and(fn () => pvRoute($plan, $b, 1))->toThrow(QueryException::class);
        pvRoute($plan, $b, 2);
        expect(PlanProviderRoute::count())->toBe(2);
    });
});

describe('providers', function () {
    it('lists providers with search, status filter, configuration badge and counts', function () {
        $plan = pvPlan();
        $alpha = pvProvider('Alpha Net');
        pvCapability($alpha, $plan->product->service);
        pvRoute($plan, $alpha, 1);
        pvProvider('Beta Link', 'maintenance', configured: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->get('/admin/providers')->assertOk()->assertDontSee('is not built yet')
            ->assertSee('data-provider="alpha-net"', false)->assertSee('data-provider="beta-link"', false)
            ->assertSee('1 service')->assertSee('1 route')->assertSee('data-configured="yes"', false)->assertSee('data-configured="no"', false);
        $this->get('/admin/providers?q=beta')->assertSee('data-provider="beta-link"', false)->assertDontSee('data-provider="alpha-net"', false);
        $this->get('/admin/providers?status=maintenance')->assertSee('data-provider="beta-link"', false)->assertDontSee('data-provider="alpha-net"', false);
        $this->get('/admin/providers?status=bogus')->assertSessionHasErrors('status');
        $this->get('/admin/providers?q=zzz')->assertSee('No providers found');
    });

    it('creates an inactive provider with a locked generated code and non-secret settings', function () {
        $this->actingAs(pvStaff(), 'admin');

        $this->get('/admin/providers/create')->assertOk()->assertSee('Required credentials');
        $this->post('/admin/providers', ['name' => 'Gamma Connect', 'description' => 'Test provider', 'driver' => 'gamma_v1',
            'base_url' => 'https://api.example.test', 'required_credentials' => ['api_key', 'username'], 'sort_order' => 3])
            ->assertRedirect()->assertSessionHas('status', 'Provider “Gamma Connect” created (inactive).');

        $provider = Provider::firstWhere('code', 'gamma-connect');
        expect($provider->status)->toBe(ProviderStatus::Inactive)->and($provider->driver)->toBe('gamma_v1')
            ->and($provider->baseUrl())->toBe('https://api.example.test')
            ->and(array_map(fn ($k) => $k->value, $provider->requiredCredentialKeys()))->toBe(['api_key', 'username'])
            ->and($provider->isConfigured())->toBeFalse()->and($provider->sort_order)->toBe(3);

        $this->put("/admin/providers/{$provider->id}", ['name' => 'Gamma Renamed', 'code' => 'hacked'])->assertRedirect();
        expect($provider->fresh()->name)->toBe('Gamma Renamed')->and($provider->fresh()->code)->toBe('gamma-connect')
            ->and($provider->fresh()->settings)->toBeNull();
        $this->get("/admin/providers/{$provider->id}/edit")->assertOk()->assertSee('locked after creation');
    });

    it('validates provider fields and duplicate codes', function (array $input, string $field) {
        pvProvider('Taken Name');
        $this->actingAs(pvStaff(), 'admin');

        $this->post('/admin/providers', $input + ['name' => 'Valid Provider'])->assertSessionHasErrors($field);
        expect(Provider::count())->toBe(1);
    })->with([
        'duplicate code' => [['name' => 'taken name!'], 'name'],
        'symbol-only name' => [['name' => '-- !!'], 'name'],
        'short name' => [['name' => 'A'], 'name'],
        'bad driver' => [['driver' => 'Bad Driver'], 'driver'],
        'http base url' => [['base_url' => 'http://insecure.example.test'], 'base_url'],
        'not a url' => [['base_url' => 'not a url'], 'base_url'],
        'unknown credential key' => [['required_credentials' => ['private_key']], 'required_credentials.0'],
    ]);

    it('changes provider status between active, maintenance and inactive', function () {
        $provider = pvProvider('Delta', 'inactive');
        $this->actingAs(pvStaff(), 'admin');

        foreach (['active', 'maintenance', 'inactive'] as $status) {
            $this->patch("/admin/providers/{$provider->id}/status", ['status' => $status])->assertRedirect()->assertSessionHas('status');
            expect($provider->fresh()->status->value)->toBe($status);
        }
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'unavailable'])->assertSessionHasErrors('status');
    });

    it('seeds no providers, credentials or routes', function () {
        $this->seed();

        expect(Provider::count())->toBe(0)->and(ProviderCredential::count())->toBe(0)->and(PlanProviderRoute::count())->toBe(0)
            ->and(Provider::whereIn('name', ['Ratel', 'Bangansuba'])->exists())->toBeFalse();
    });
});

describe('capabilities', function () {
    it('adds, edits and toggles supported services', function () {
        $data = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
        $provider = pvProvider();
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/providers/{$provider->id}/services", ['service_id' => $data->id, 'requires_plan_code' => '1', 'provider_service_code' => 'DATA'])->assertRedirect();
        $capability = $provider->services()->first();
        expect($capability->requires_plan_code)->toBeTrue()->and($capability->provider_service_code)->toBe('DATA')->and($capability->is_active)->toBeTrue();

        $this->post("/admin/providers/{$provider->id}/services", ['service_id' => $data->id])->assertSessionHasErrors(['service_id' => 'This provider already has this service.']);
        $this->put("/admin/providers/{$provider->id}/services/{$capability->id}", ['provider_service_code' => ''])->assertRedirect();
        expect($capability->fresh()->requires_plan_code)->toBeFalse()->and($capability->fresh()->provider_service_code)->toBeNull();

        $this->patch("/admin/providers/{$provider->id}/services/{$capability->id}/status", ['is_active' => 0]);
        expect($capability->fresh()->is_active)->toBeFalse();
        $this->get("/admin/providers/{$provider->id}")->assertSee('data-capability="data"', false)->assertSee('Disabled');

        $other = pvProvider('Other');
        $this->patch("/admin/providers/{$other->id}/services/{$capability->id}/status", ['is_active' => 1])->assertNotFound();
    });
});

describe('routes', function () {
    it('adds multiple providers to a plan in priority order with provider-specific codes', function () {
        $plan = pvPlan();
        $alpha = pvProvider('Alpha');
        $beta = pvProvider('Beta');
        pvCapability($alpha, $plan->product->service);
        pvCapability($beta, $plan->product->service);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $beta->id, 'priority' => 2, 'provider_plan_code' => 'Mtn_1GB_30D'])->assertRedirect();
        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $alpha->id, 'priority' => 1, 'provider_plan_code' => 'MTN1G30'])->assertSessionHasNoErrors();

        $routes = $plan->providerRoutes()->get();
        expect($routes->map(fn ($r) => [$r->priority, $r->provider->name, $r->provider_plan_code])->all())->toBe([[1, 'Alpha', 'MTN1G30'], [2, 'Beta', 'Mtn_1GB_30D']])
            ->and($plan->fresh()->code)->toBe('data-mtn-1gb')
            ->and(PlanProviderRouteChange::where('event', 'created')->count())->toBe(2);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk()->assertSee('Will try: 1 Alpha → 2 Beta')
            ->assertSee('Created: priority 2, code Mtn_1GB_30D, Cost not set');
    });

    it('limits route providers to those with an active service for the plan', function () {
        $plan = pvPlan();
        $capable = pvProvider('Capable');
        $disabledCap = pvProvider('Paused Cap');
        $other = pvProvider('Unrelated');
        pvCapability($capable, $plan->product->service);
        pvCapability($disabledCap, $plan->product->service, active: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/routes")->assertSee('Capable (Active)')->assertDontSee('Paused Cap (')->assertDontSee('Unrelated (');
        foreach ([$disabledCap, $other] as $provider) {
            $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'provider_plan_code' => 'X'])
                ->assertSessionHasErrors(['provider_id' => 'This provider has no active service for this plan.']);
        }
        expect(PlanProviderRoute::count())->toBe(0);
    });

    it('rejects duplicate priorities and duplicate providers', function () {
        $plan = pvPlan();
        [$a, $b] = [pvProvider('Alpha'), pvProvider('Beta')];
        pvCapability($a, $plan->product->service);
        pvCapability($b, $plan->product->service);
        pvRoute($plan, $a, 1);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $b->id, 'priority' => 1, 'provider_plan_code' => 'X'])
            ->assertSessionHasErrors(['priority' => 'This priority is already used by another route for this plan.']);
        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $a->id, 'priority' => 2, 'provider_plan_code' => 'X'])
            ->assertSessionHasErrors(['provider_id' => 'This provider already has a route for this plan.']);
        expect(fn () => app(SavePlanRoute::class)->create($plan, $b, ['priority' => 1], pvStaff()))->toThrow(ValidationException::class)
            ->and(PlanProviderRoute::count())->toBe(1);
    });

    it('requires a provider plan code only when the capability requires one', function () {
        $plan = pvPlan();
        [$coded, $uncoded] = [pvProvider('Coded'), pvProvider('Uncoded')];
        pvCapability($coded, $plan->product->service, requiresCode: true);
        pvCapability($uncoded, $plan->product->service, requiresCode: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $coded->id, 'priority' => 1])
            ->assertSessionHasErrors(['provider_plan_code' => 'This provider requires its own plan code for this service.']);
        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $uncoded->id, 'priority' => 1])->assertSessionHasNoErrors();
        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $coded->id, 'priority' => 2, 'provider_plan_code' => "bad\ncode"])
            ->assertSessionHasErrors('provider_plan_code');

        expect($plan->providerRoutes()->first()->provider_plan_code)->toBeNull()->and(candidates($plan)[0]->eligible)->toBeTrue();
    });

    it('edits code and cost without changing the provider and records history', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/routes/{$route->id}/edit")->assertOk()->assertSee('The provider of a route cannot change');
        $this->put("/admin/services/plans/{$plan->id}/routes/{$route->id}", ['provider_plan_code' => 'NEW1', 'cost' => '250.50', 'provider_id' => 999, 'priority' => 9])
            ->assertSessionHas('status', 'Route updated.');
        $this->put("/admin/services/plans/{$plan->id}/routes/{$route->id}", ['provider_plan_code' => 'NEW1', 'cost' => '250.50'])->assertSessionHas('status', 'No route changes to save.');

        $route->refresh();
        $change = PlanProviderRouteChange::where('event', 'updated')->first();
        expect($route->provider_id)->toBe($provider->id)->and($route->priority)->toBe(1)->and($route->provider_plan_code)->toBe('NEW1')
            ->and($route->cost_kobo)->toBe(25_050)->and($route->cost_type->value)->toBe('fixed')
            ->and(PlanProviderRouteChange::where('event', 'updated')->count())->toBe(1)
            ->and($change->old_provider_plan_code)->toBe('CODE1')->and($change->new_provider_plan_code)->toBe('NEW1')
            ->and($change->old_cost_kobo)->toBeNull()->and($change->new_cost_kobo)->toBe(25_050);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertSee('Updated: code CODE1 → NEW1, cost Cost not set → ₦250.50');

        $otherPlan = pvPlan('2GB', $plan->product);
        $this->get("/admin/services/plans/{$otherPlan->id}/routes/{$route->id}/edit")->assertNotFound();
    });

    it('enables and disables a route with history', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');

        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 0])->assertRedirect();
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 0]);
        expect($route->fresh()->is_active)->toBeFalse()->and(candidates($plan)[0]->reasons)->toBe(['Route disabled.']);
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 1]);

        expect(PlanProviderRouteChange::pluck('event')->all())->toBe(['disabled', 'enabled'])->and(candidates($plan)[0]->eligible)->toBeTrue();
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertSee('Disabled')->assertDontSee('Disabled: disabled');
    });

    it('moves routes up and down safely, swapping priorities in a transaction', function () {
        $plan = pvPlan();
        $providers = collect(['Alpha', 'Beta', 'Gamma'])->map(fn ($n) => pvProvider($n));
        $providers->each(fn ($p) => pvCapability($p, $plan->product->service));
        $routes = $providers->values()->map(fn ($p, $i) => pvRoute($plan, $p, [1, 2, 5][$i]));
        $this->actingAs(pvStaff(), 'admin');
        $order = fn () => $plan->providerRoutes()->get()->map(fn ($r) => $r->provider->name.':'.$r->priority)->all();

        $this->patch("/admin/services/plans/{$plan->id}/routes/{$routes[2]->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'Route order updated.');
        expect($order())->toBe(['Alpha:1', 'Gamma:2', 'Beta:5']);
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$routes[2]->id}/move", ['direction' => 'up']);
        expect($order())->toBe(['Gamma:1', 'Alpha:2', 'Beta:5']);
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$routes[2]->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'This route is already first.');
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$routes[1]->id}/move", ['direction' => 'down'])->assertSessionHas('status', 'This route is already last.');
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$routes[1]->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');

        expect(PlanProviderRouteChange::where('event', 'moved')->count())->toBe(4)
            ->and(PlanProviderRoute::where('priority', 0)->exists())->toBeFalse()
            ->and(PlanProviderRoute::where('plan_id', $plan->id)->pluck('priority')->unique()->count())->toBe(3);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertSee('Will try: 1 Gamma → 2 Alpha → 3 Beta');
    });
});

describe('provider cost', function () {
    it('stores fixed cost in kobo and variable cost as a discount', function () {
        $fixed = pvPlan();
        $variable = pvVariablePlan();
        $provider = pvProvider();
        pvCapability($provider, $fixed->product->service, requiresCode: false);
        pvCapability($provider, $variable->product->service, requiresCode: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$fixed->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'cost' => '1,250.05'])->assertSessionHasNoErrors();
        $this->post("/admin/services/plans/{$variable->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'cost_discount' => '3.5'])->assertSessionHasNoErrors();

        $f = $fixed->providerRoutes()->first();
        $v = $variable->providerRoutes()->first();
        expect($f->cost_type->value)->toBe('fixed')->and($f->cost_kobo)->toBe(125_005)->and($f->cost_discount_bps)->toBeNull()
            ->and($v->cost_type->value)->toBe('percent')->and($v->cost_discount_bps)->toBe(350)->and($v->cost_kobo)->toBeNull()
            ->and($v->costLabel())->toBe('3.5% off face value');
    });

    it('validates cost for the plan amount type and the system maximum', function (bool $variablePlan, array $input, string $field) {
        $plan = $variablePlan ? pvVariablePlan() : pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service, requiresCode: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", $input + ['provider_id' => $provider->id, 'priority' => 1])->assertSessionHasErrors($field);
        expect(PlanProviderRoute::count())->toBe(0);
    })->with([
        'fixed: discount prohibited' => [false, ['cost_discount' => '2'], 'cost_discount'],
        'fixed: invalid money' => [false, ['cost' => '1.005'], 'cost'],
        'fixed: zero' => [false, ['cost' => '0'], 'cost'],
        'fixed: above maximum' => [false, ['cost' => '10,000,000.01'], 'cost'],
        'variable: fixed cost prohibited' => [true, ['cost' => '100'], 'cost'],
        'variable: 100% discount' => [true, ['cost_discount' => '100'], 'cost_discount'],
        'variable: bad percent' => [true, ['cost_discount' => 'abc'], 'cost_discount'],
    ]);

    it('respects a lowered system maximum from the Settings Store', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service, requiresCode: false);
        app(SettingsStore::class)->set('pricing.max_amount_kobo', 10_000);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'cost' => '100.01'])
            ->assertSessionHasErrors(['cost' => 'The cost may not be more than ₦100.00 (system maximum).']);
    });

    it('treats cost as optional: shows a warning and never affects eligibility', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');

        expect(candidates($plan)[0]->eligible)->toBeTrue();
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertSee('data-cost-warnings', false)->assertSee('Alpha Provider: cost not set.')->assertSee('Eligible');
    });

    it('warns when cost is above an active selling price without blocking', function () {
        $fixed = pvPlan();
        $variable = pvVariablePlan();
        $provider = pvProvider();
        pvCapability($provider, $fixed->product->service, requiresCode: false);
        pvCapability($provider, $variable->product->service, requiresCode: false);
        PlanPrice::factory()->create(['plan_id' => $fixed->id, 'user_type' => UserType::Vendor, 'price_kobo' => 20_000]);
        PlanPrice::factory()->create(['plan_id' => $fixed->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 30_000]);
        PlanPrice::factory()->create(['plan_id' => $fixed->id, 'user_type' => UserType::Affiliate, 'price_kobo' => 10_000, 'is_active' => false]);
        PlanPrice::factory()->variable(400, 0)->create(['plan_id' => $variable->id, 'user_type' => UserType::ApiUser]);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$fixed->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'cost' => '250'])->assertSessionHasNoErrors();
        $this->post("/admin/services/plans/{$variable->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'cost_discount' => '3'])->assertSessionHasNoErrors();

        $this->get("/admin/services/plans/{$fixed->id}/routes")->assertSee('Alpha Provider: cost ₦250.00 is higher than the Vendor selling price ₦200.00.')
            ->assertDontSee('Subscriber selling price')->assertDontSee('Affiliate selling price')->assertSee('Eligible');
        $this->get("/admin/services/plans/{$variable->id}/routes")->assertSee('provider discount 3% is lower than the API User selling discount 4%');
        expect(candidates($fixed)[0]->eligible)->toBeTrue()->and(PlanPrice::where('plan_id', $fixed->id)->where('user_type', 'vendor')->value('price_kobo'))->toBe(20_000);
    });

    it('locks the plan amount type once a route has a cost', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');
        $change = ['product_id' => $plan->product_id, 'name' => $plan->name, 'amount_type' => 'variable', 'min_amount' => '1', 'max_amount' => '100'];

        $this->put("/admin/services/plans/{$plan->id}", $change)->assertSessionHasNoErrors();
        $plan->refresh()->forceFill(['amount_type' => 'fixed', 'min_amount_kobo' => null, 'max_amount_kobo' => null])->save();
        $route->forceFill(['cost_type' => 'fixed', 'cost_kobo' => 100])->save();

        $this->put("/admin/services/plans/{$plan->id}", $change)
            ->assertSessionHasErrors(['amount_type' => 'The amount type cannot be changed because a provider route of this plan has a cost.']);
    });
});

describe('route resolver', function () {
    it('returns routes in priority order with eligible and skipped reasons', function () {
        $plan = pvPlan();
        $service = $plan->product->service;
        $ok = pvProvider('Ok');
        $maint = pvProvider('Maint', 'maintenance');
        $off = pvProvider('Off', 'inactive');
        $nocap = pvProvider('NoCap');
        $capoff = pvProvider('CapOff');
        $nocode = pvProvider('NoCode');
        $unconf = pvProvider('Unconf', configured: false);
        $disabled = pvProvider('DisabledRoute');
        foreach ([$ok, $maint, $off, $capoff, $nocode, $unconf, $disabled] as $p) {
            pvCapability($p, $service, active: $p !== $capoff);
        }
        pvRoute($plan, $unconf, 7);
        pvRoute($plan, $maint, 2);
        pvRoute($plan, $ok, 4);
        pvRoute($plan, $off, 3);
        pvRoute($plan, $nocap, 5);
        pvRoute($plan, $capoff, 6);
        pvRoute($plan, $nocode, 8, ['provider_plan_code' => null]);
        pvRoute($plan, $disabled, 1, ['is_active' => false]);

        $result = collect(candidates($plan))->mapWithKeys(fn ($c) => [$c->route->provider->name => $c->eligible ? 'eligible' : implode(' ', $c->reasons)]);

        expect($result->keys()->all())->toBe(['DisabledRoute', 'Maint', 'Off', 'Ok', 'NoCap', 'CapOff', 'Unconf', 'NoCode'])
            ->and($result->all())->toBe([
                'DisabledRoute' => 'Route disabled.',
                'Maint' => 'Provider Maintenance.',
                'Off' => 'Provider Inactive.',
                'Ok' => 'eligible',
                'NoCap' => 'Provider does not support this service.',
                'CapOff' => 'Provider service disabled.',
                'Unconf' => 'Provider not configured.',
                'NoCode' => 'Provider plan code required.',
            ])
            ->and(array_map(fn ($c) => $c->route->provider->name, app(RouteResolver::class)->eligibleFor(Plan::find($plan->id))))->toBe(['Ok']);
    });

    it('marks every route skipped when the plan is unavailable through the catalog chain', function (string $level, string $label) {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $target = match ($level) {
            'category' => $plan->product->service->category, 'service' => $plan->product->service, 'product' => $plan->product, 'plan' => $plan,
        };
        $target->forceFill(['is_active' => false])->save();

        expect(candidates($plan)[0]->eligible)->toBeFalse()->and(candidates($plan)[0]->reasons)->toBe(["Plan unavailable: {$label}."]);
    })->with([['category', 'Active (category disabled)'], ['service', 'Active (service disabled)'], ['product', 'Active (product disabled)'], ['plan', 'Disabled']]);

    it('skips routes when a capability is switched off without changing route flags', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        $capability = pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');

        $this->patch("/admin/providers/{$provider->id}/services/{$capability->id}/status", ['is_active' => 0]);

        expect($route->fresh()->is_active)->toBeTrue()->and(candidates($plan)[0]->reasons)->toBe(['Provider service disabled.']);
        $this->patch("/admin/providers/{$provider->id}/services/{$capability->id}/status", ['is_active' => 1]);
        expect(candidates($plan)[0]->eligible)->toBeTrue();
    });

    it('is deterministic and side-effect free', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $before = collect(['plan_provider_routes', 'plan_provider_route_changes', 'providers', 'provider_services', 'provider_credentials'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);

        $first = candidates($plan);
        $second = candidates($plan);

        expect(array_map(fn ($c) => [$c->route->id, $c->eligible, $c->reasons], $first))->toBe(array_map(fn ($c) => [$c->route->id, $c->eligible, $c->reasons], $second));
        foreach ($before as $table => $json) {
            expect(DB::table($table)->get()->toJson())->toBe($json);
        }
        Http::assertNothingSent();
    });

    it('has no service-specific logic', function () {
        $source = collect(token_get_all(file_get_contents(app_path('Services/Providers/RouteResolver.php'))))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');

        foreach (['airtime', 'electricity', 'smile', 'mtn', 'ratel', 'bangansuba', 'slug', 'network', 'Http::', 'cost'] as $word) {
            expect(str_contains(strtolower($source), strtolower($word)))->toBeFalse("resolver mentions {$word}");
        }
    });
});

describe('bulk helper', function () {
    it('adds the provider at one priority to every plan of a product as individual routes', function () {
        $plan1 = pvPlan('1GB');
        $plan2 = pvPlan('2GB', $plan1->product);
        $plan3 = pvPlan('3GB', $plan1->product);
        $provider = pvProvider('Bulk Co');
        $other = pvProvider('Other Co');
        pvCapability($provider, $plan1->product->service);
        pvCapability($other, $plan1->product->service);
        pvRoute($plan2, $other, 1);
        pvRoute($plan3, $provider, 5);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/providers/{$provider->id}/bulk-routes", ['product_id' => $plan1->product_id, 'priority' => 1])
            ->assertRedirect()->assertSessionHas('status', '1 route(s) created for MTN; skipped: 2GB (Priority 1 is already used by another route for this plan.); 3GB (This provider already has a route for this plan.).');

        $created = $plan1->providerRoutes()->first();
        expect($created->provider_id)->toBe($provider->id)->and($created->priority)->toBe(1)->and($created->provider_plan_code)->toBeNull()
            ->and($created->hasCost())->toBeFalse()->and($created->is_active)->toBeTrue()
            ->and(PlanProviderRoute::count())->toBe(3)
            ->and(PlanProviderRouteChange::where('plan_id', $plan1->id)->where('event', 'created')->count())->toBe(1)
            ->and(candidates($plan1)[0]->reasons)->toBe(['Provider plan code required.']);
    });

    it('requires an active capability for the product service', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service, active: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/providers/{$provider->id}/bulk-routes", ['product_id' => $plan->product_id, 'priority' => 1])
            ->assertSessionHasErrors(['product_id' => 'This provider has no active service for this product.']);
        $this->post("/admin/providers/{$provider->id}/bulk-routes", ['product_id' => $plan->product_id, 'priority' => 0])->assertSessionHasErrors('priority');
        expect(PlanProviderRoute::count())->toBe(0);
    });
});

describe('credentials', function () {
    it('stores credentials encrypted with only a last-four hint and never shows them', function () {
        $provider = pvProvider('Secure Co', configured: false);
        $staff = pvStaff();
        $this->actingAs($staff, 'admin');

        $response = $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET, 'pin' => '1234']]);
        $response->assertRedirect()->assertSessionHas('status', 'Credentials saved (2).');

        $raw = DB::table('provider_credentials')->where('key', 'api_key')->value('value');
        $credential = ProviderCredential::where('key', 'api_key')->first();
        expect($raw)->not->toContain(PV_SECRET)->and(decrypt($raw, false))->toBe(PV_SECRET)
            ->and($credential->value)->toBe(PV_SECRET)->and($credential->hint)->toBe('7c1e')
            ->and(ProviderCredential::where('key', 'pin')->value('hint'))->toBeNull()
            ->and(json_encode(session()->all()))->not->toContain(PV_SECRET)
            ->and($response->getContent())->not->toContain(PV_SECRET);

        $html = $this->get("/admin/providers/{$provider->id}")->assertOk()->assertSee('Set (••••7c1e)')->assertSee('Set (••••)')->getContent();
        expect(str_contains($html, PV_SECRET))->toBeFalse()->and(str_contains($html, '1234'))->toBeFalse()
            ->and(str_contains(Provider::with('credentials')->find($provider->id)->toJson(), PV_SECRET))->toBeFalse()
            ->and(str_contains($credential->toJson(), PV_SECRET))->toBeFalse()
            ->and(array_key_exists('value', $credential->toArray()))->toBeFalse();
        foreach (['/admin/providers', "/admin/providers/{$provider->id}/edit"] as $url) {
            expect(str_contains($this->get($url)->getContent(), PV_SECRET))->toBeFalse();
        }
    });

    it('never flashes secrets as old input or in validation errors', function () {
        $provider = pvProvider('Secure Co', configured: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->from("/admin/providers/{$provider->id}")
            ->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET, 'username' => str_repeat('x', 2001)]])
            ->assertSessionHasErrors('credentials.username');
        $session = json_encode(session()->all());
        expect(str_contains($session, PV_SECRET))->toBeFalse()->and(session()->getOldInput('credentials'))->toBeNull();

        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['private_key' => PV_SECRET]])->assertSessionHasErrors('credentials');
        expect(str_contains(json_encode(session()->all()), PV_SECRET))->toBeFalse()
            ->and(str_contains($this->get("/admin/providers/{$provider->id}")->getContent(), PV_SECRET))->toBeFalse()
            ->and(ProviderCredential::count())->toBe(0);
    });

    it('keeps values on blank submit, records set, replaced and cleared events without values', function () {
        $provider = pvProvider('Secure Co', configured: false);
        $staff = pvStaff();
        $this->actingAs($staff, 'admin');

        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]]);
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => '', 'secret_key' => '']])
            ->assertSessionHas('status', 'No credentials entered; nothing changed.');
        expect(ProviderCredential::first()->value)->toBe(PV_SECRET);

        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => 'fake-replacement-NOT-REAL-9d2a']]);
        expect(ProviderCredential::first()->value)->toBe('fake-replacement-NOT-REAL-9d2a')->and(ProviderCredential::first()->hint)->toBe('9d2a');

        $this->patch("/admin/providers/{$provider->id}/credentials/api_key/clear")->assertSessionHas('status', 'API key cleared.');
        $this->patch("/admin/providers/{$provider->id}/credentials/api_key/clear")->assertSessionHas('status', 'API key was not set.');
        $this->patch("/admin/providers/{$provider->id}/credentials/private_key/clear")->assertNotFound();

        expect(ProviderCredential::count())->toBe(0)
            ->and(ProviderCredentialChange::pluck('action')->all())->toBe(['set', 'replaced', 'cleared'])
            ->and(ProviderCredentialChange::pluck('changed_by')->unique()->all())->toBe([$staff->id])
            ->and(str_contains(DB::table('provider_credential_changes')->get()->toJson(), PV_SECRET))->toBeFalse();
        $this->get("/admin/providers/{$provider->id}")->assertSee('data-credential-history', false)->assertSee('replaced')->assertSee('cleared');

        $change = ProviderCredentialChange::first();
        expect(fn () => $change->forceFill(['action' => 'cleared'])->save())->toThrow(LogicException::class)
            ->and(fn () => $change->delete())->toThrow(LogicException::class);
    });

    it('derives configuration status from required credentials', function () {
        $plan = pvPlan();
        $provider = pvProvider('Config Co', configured: false);
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff(), 'admin');

        expect($provider->fresh()->configurationLabel())->toBe('Not configured (no required credentials chosen)')
            ->and(candidates($plan)[0]->reasons)->toBe(['Provider not configured.']);

        $this->put("/admin/providers/{$provider->id}", ['name' => 'Config Co', 'required_credentials' => ['api_key', 'username']]);
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]]);
        expect($provider->fresh()->configurationLabel())->toBe('Not configured (missing: Username)');

        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['username' => 'fake-user']]);
        expect($provider->fresh()->isConfigured())->toBeTrue()->and(candidates($plan)[0]->eligible)->toBeTrue()
            ->and(Schema::hasColumn('providers', 'configured'))->toBeFalse();
    });

    it('never writes secrets to the log', function () {
        // A temporary log file, so the test never touches the application's own log.
        $log = sys_get_temp_dir().'/provider-engine-test-'.uniqid().'.log';
        config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);
        Log::forgetChannel('single');
        $provider = pvProvider('Log Co', configured: false);
        $this->actingAs(pvStaff(), 'admin');

        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]]);
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['username' => str_repeat('y', 2001), 'token' => PV_SECRET]]);
        Log::info('provider credential flow finished');

        $contents = File::get($log);
        File::delete($log);
        expect($contents)->toContain('provider credential flow finished')->and(str_contains($contents, PV_SECRET))->toBeFalse();
    });
});

describe('permissions', function () {
    it('gives Super Admin every provider permission and no other built-in role', function () {
        foreach (['providers.view', 'providers.create', 'providers.update', 'providers.credentials'] as $permission) {
            expect(Role::findByName('super-admin', 'admin')->hasPermissionTo($permission))->toBeTrue();
            foreach ([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer] as $role) {
                expect(Role::findByName($role->value, 'admin')->hasPermissionTo($permission))->toBeFalse();
            }
        }
        $this->actingAs(pvStaff(), 'admin')->get('/admin/roles/create')->assertSee('value="providers.credentials"', false)->assertSee('Manage credentials');
    });

    it('shows the built Providers module in the sidebar to permitted staff only', function () {
        $this->actingAs(pvStaff(), 'admin')->get('/admin/providers')->assertSee('data-nav="providers"', false)->assertDontSee('is not built yet');
        $this->actingAs(pvStaff(SystemRole::Manager), 'admin')->get('/admin')->assertDontSee('data-nav="providers"', false);
    });

    it('returns 403 everywhere for the other built-in roles', function (SystemRole $role) {
        $plan = pvPlan();
        $provider = pvProvider();
        $cap = pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);
        $this->actingAs(pvStaff($role), 'admin');

        foreach (['/admin/providers', '/admin/providers/create', "/admin/providers/{$provider->id}", "/admin/providers/{$provider->id}/edit",
            "/admin/services/plans/{$plan->id}/routes", "/admin/services/plans/{$plan->id}/routes/{$route->id}/edit"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/providers', ['name' => 'Sneaky'])->assertForbidden();
        $this->put("/admin/providers/{$provider->id}", ['name' => 'Changed'])->assertForbidden();
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'inactive'])->assertForbidden();
        $this->patch("/admin/providers/{$provider->id}/services/{$cap->id}/status", ['is_active' => 0])->assertForbidden();
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]])->assertForbidden();
        $this->post("/admin/providers/{$provider->id}/bulk-routes", ['product_id' => $plan->product_id, 'priority' => 2])->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 0])->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/move", ['direction' => 'down'])->assertForbidden();

        expect(Provider::count())->toBe(1)->and($provider->fresh()->status->value)->toBe('active')->and($route->fresh()->is_active)->toBeTrue()
            ->and($cap->fresh()->is_active)->toBeTrue()->and(ProviderCredential::where('value', '!=', '')->count())->toBe(1);
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('grants exactly what each providers.* permission allows', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $route = pvRoute($plan, $provider, 1);

        $this->actingAs(pvRole(['providers.view', 'services.view']), 'admin');
        $this->get('/admin/providers')->assertOk()->assertDontSee('Add provider');
        $this->get("/admin/providers/{$provider->id}")->assertOk()->assertDontSee('data-set-status', false)->assertDontSee('data-credentials-form', false)->assertDontSee('data-bulk-routes', false);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk()->assertDontSee('data-move', false)->assertDontSee('data-add-route', false);
        $this->get('/admin/providers/create')->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 0])->assertForbidden();

        $this->actingAs(pvRole(['providers.view', 'providers.create']), 'admin');
        $this->post('/admin/providers', ['name' => 'Creator Co'])->assertRedirect();
        $this->put("/admin/providers/{$provider->id}", ['name' => 'Nope'])->assertForbidden();

        $this->actingAs(pvRole(['providers.view', 'providers.update', 'services.view']), 'admin');
        $this->patch("/admin/services/plans/{$plan->id}/routes/{$route->id}/status", ['is_active' => 0])->assertRedirect();
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'maintenance'])->assertRedirect();
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]])->assertForbidden();
        $this->get("/admin/providers/{$provider->id}")->assertDontSee('data-credentials-form', false)->assertSee('data-bulk-routes', false);

        $this->actingAs(pvRole(['providers.view', 'providers.credentials']), 'admin');
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['secret_key' => PV_SECRET]])->assertRedirect();
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'active'])->assertForbidden();

        expect(Provider::where('code', 'creator-co')->exists())->toBeTrue()->and($provider->fresh()->name)->toBe('Alpha Provider')
            ->and($route->fresh()->is_active)->toBeFalse()->and($provider->fresh()->status->value)->toBe('maintenance')
            ->and(ProviderCredential::where('key', 'secret_key')->exists())->toBeTrue();
    });

    it('requires providers.view alongside the other provider permissions', function () {
        $provider = pvProvider();

        $this->actingAs(pvRole(['providers.update', 'providers.credentials', 'providers.create']), 'admin');
        $this->get("/admin/providers/{$provider->id}/edit")->assertForbidden();
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]])->assertForbidden();
        expect(ProviderCredential::where('key', 'api_key')->count())->toBe(1);
    });

    it('does not let services.* or pricing.* alone reach providers or routes', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $this->actingAs(pvRole(['services.view', 'services.create', 'services.update', 'services.delete', 'pricing.view', 'pricing.update']), 'admin');

        $this->get('/admin/providers')->assertForbidden();
        $this->get("/admin/providers/{$provider->id}")->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}")->assertOk()->assertDontSee('data-plan-routes', false)->assertDontSee('Manage routes');
        $this->get('/admin/services/plans')->assertOk()->assertDontSee('data-routes=', false);
        $this->get('/admin')->assertDontSee('data-nav="providers"', false);
    });

    it('needs services.view for plan routes and the bulk helper', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $this->actingAs(pvRole(['providers.view', 'providers.update']), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/routes")->assertForbidden();
        $this->post("/admin/providers/{$provider->id}/bulk-routes", ['product_id' => $plan->product_id, 'priority' => 1])->assertForbidden();
        expect(PlanProviderRoute::count())->toBe(0);
    });

    it('shows route eligibility on plan pages only with providers.view', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        pvRoute($plan, $provider, 1);
        $this->actingAs(pvRole(['services.view', 'providers.view']), 'admin');

        $this->get("/admin/services/plans/{$plan->id}")->assertSee('data-plan-routes', false)->assertSee('Routes 1/1 eligible');
        $this->get('/admin/services/plans')->assertSee('data-routes="1/1"', false);
    });

    it('keeps guests and customers out', function () {
        $plan = pvPlan();
        $provider = pvProvider();

        $this->get('/admin/providers')->assertRedirect(route('admin.login'));
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]])->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/providers/{$provider->id}")->assertRedirect(route('admin.login'));
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertRedirect(route('admin.login'));
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'inactive'])->assertRedirect(route('admin.login'));

        expect($provider->fresh()->status->value)->toBe('active')->and(ProviderCredential::count())->toBe(1);
    });

    it('re-checks authorization inside every provider action', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        $cap = pvCapability($provider, $plan->product->service, requiresCode: false);
        $route = pvRoute($plan, $provider, 1);
        $disabled = pvStaff();
        $disabled->forceFill(['status' => 'disabled'])->save();

        foreach ([pvStaff(SystemRole::Viewer), pvRole(['providers.view']), pvRole(['services.view', 'pricing.update']), $disabled] as $actor) {
            expect(fn () => app(SaveProvider::class)->create(['name' => 'X Co'], $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SaveProvider::class)->update($provider, ['name' => 'X'], $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SetProviderStatus::class)->handle($provider, ProviderStatus::Inactive, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SaveProviderService::class)->setActive($cap, false, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SavePlanRoute::class)->setActive($route, false, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SavePlanRoute::class)->move($route, 'down', $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(BulkAddRoutes::class)->handle($provider, $plan->product, 2, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SaveProviderCredentials::class)->handle($provider, ['token' => PV_SECRET], $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SaveProviderCredentials::class)->clear($provider, CredentialKey::ApiKey, $actor))->toThrow(AuthorizationException::class);
        }
        expect(fn () => app(SaveProviderCredentials::class)->handle($provider, ['token' => PV_SECRET], pvRole(['providers.view', 'providers.update'])))->toThrow(AuthorizationException::class)
            ->and($route->fresh()->is_active)->toBeTrue()->and(ProviderCredential::count())->toBe(1);
    });
});

describe('scope', function () {
    it('makes no outgoing HTTP requests across provider flows', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $this->actingAs(pvStaff(), 'admin');

        $this->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'provider_plan_code' => 'X1'])->assertRedirect();
        $this->put("/admin/providers/{$provider->id}/credentials", ['credentials' => ['api_key' => PV_SECRET]]);
        $this->patch("/admin/providers/{$provider->id}/status", ['status' => 'active']);
        $this->get("/admin/services/plans/{$plan->id}/routes")->assertOk();
        $this->get("/admin/providers/{$provider->id}")->assertOk();

        Http::assertNothingSent();
        // Provider configuration makes no HTTP calls. The only HTTP clients in app/ are the payment
        // gateway client (Phase 9) and the provider client (Phase 10), both restricted to adapter-declared hosts.
        $usesHttp = collect(File::allFiles(app_path()))->filter(fn ($f) => str_contains($f->getContents(), 'Facades\\Http') || str_contains($f->getContents(), 'GuzzleHttp'));
        expect($usesHttp->map->getRelativePathname()->sort()->values()->all())->toBe(['Services/Payments/PaymentHttpClient.php', 'Services/Providers/ProviderHttpClient.php']);
    });

    it('adds no purchase, customer, mobile API, wallet or delete routes', function () {
        // Phase 10 CP6 customer Buy Data/Airtime and My purchases routes are the only allowed exceptions.
        $public = collect(Route::getRoutes())->filter(fn ($r) => ! str_starts_with($r->uri(), 'admin')
            && ! in_array($r->uri(), ['buy', 'buy/{service}', 'buy/{service}/confirm', 'purchases', 'purchases/{reference}'], true)
            && preg_match('/(provider|route|purchase|buy|vend|order|price)/i', $r->uri()));
        $deletes = collect(Route::getRoutes())->filter(fn ($r) => (str_starts_with($r->uri(), 'admin/providers') || str_contains($r->uri(), '/routes'))
            && in_array('DELETE', $r->methods(), true));

        expect($public->map->uri()->values()->all())->toBe([])->and($deletes)->toBeEmpty();
        // payments exists since Phase 9 and purchases (with purchase_attempts) since Phase 10.
        foreach (['orders', 'commissions', 'provider_attempts'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse("{$table} exists");
        }
    });

    it('keeps route history append-only', function () {
        $plan = pvPlan();
        $provider = pvProvider();
        pvCapability($provider, $plan->product->service);
        $this->actingAs(pvStaff(), 'admin')->post("/admin/services/plans/{$plan->id}/routes", ['provider_id' => $provider->id, 'priority' => 1, 'provider_plan_code' => 'X1']);
        $change = PlanProviderRouteChange::first();

        expect(fn () => $change->forceFill(['event' => 'x'])->save())->toThrow(LogicException::class)
            ->and(fn () => $change->delete())->toThrow(LogicException::class);
    });
});
