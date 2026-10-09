<?php

use App\Exceptions\Providers\ProviderCallUncertain;
use App\Exceptions\Providers\ProviderNotConfigured;
use App\Exceptions\Providers\ProviderRequestRefused;
use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderService;
use App\Models\Service;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\ProviderHttpClient;
use App\Services\Providers\RouteResolver;
use App\Support\Providers\CredentialKey;
use App\Support\Providers\ProviderCallType;
use App\Support\Providers\ProviderOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;
use Tests\Support\Providers\HttpTestProvider;

/*
 * Phase 10 Step 1, CP2: provider adapter contract, registry, executable-route
 * check, ProviderHttpClient and the outcome rule. Test-only adapters; no real
 * provider, credentials or external HTTP calls.
 */

const PV2_KEY = 'fake-api-key-NOT-REAL-cp2a';
const PV2_SECRET = 'fake-secret-key-NOT-REAL-cp2b';

beforeEach(function () {
    config(['providers.drivers' => ['fake-provider' => FakeProvider::class, 'http-test-provider' => HttpTestProvider::class]]);
    config(['providers.http.retry_sleep_ms' => 0]);
    FakeProvider::reset();
    HttpTestProvider::$timeout = 25;
    Http::preventStrayRequests();
});

function pv2Plan(string $serviceSlug = 'data'): Plan
{
    $service = Service::where('slug', $serviceSlug)->first()
        ?? Service::factory()->create(['name' => Str::headline($serviceSlug), 'slug' => $serviceSlug]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => $serviceSlug.'-mtn-'.Str::lower(Str::random(5)), 'network' => 'mtn']);

    return Plan::factory()->create(['product_id' => $product->id, 'name' => 'Plan '.Str::random(4), 'code' => $product->code.'-p', 'is_active' => true]);
}

/** A configured, active provider (Phase 7 rules) with the given driver, supporting the plan's service. */
function pv2Provider(?string $driver, Plan $plan, array $credentials = [CredentialKey::ApiKey->value => PV2_KEY]): Provider
{
    $provider = Provider::factory()->create(['name' => 'Prov '.Str::random(4), 'code' => 'prov-'.Str::lower(Str::random(6)), 'status' => 'active',
        'driver' => $driver, 'settings' => ['required_credentials' => array_keys($credentials)]]);
    foreach ($credentials as $key => $value) {
        (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => $key, 'value' => $value, 'hint' => substr($value, -4)])->save();
    }
    (new ProviderService)->forceFill(['provider_id' => $provider->id, 'service_id' => $plan->product->service_id, 'requires_plan_code' => true, 'is_active' => true])->save();

    return $provider;
}

function pv2Route(Plan $plan, Provider $provider, int $priority = 1, array $attributes = []): PlanProviderRoute
{
    return tap((new PlanProviderRoute)->forceFill($attributes + ['plan_id' => $plan->id, 'provider_id' => $provider->id, 'priority' => $priority,
        'provider_plan_code' => 'CODE'.$priority, 'is_active' => true]))->save();
}

function pv2Registry(): ProviderAdapterRegistry
{
    return app(ProviderAdapterRegistry::class);
}

function pv2Request(): ProviderPurchaseRequest
{
    return new ProviderPurchaseRequest('PRA-TEST-1', 'airtime', 'mtn', null, '08012345678', 10_000, 10_000);
}

describe('contract and results', function () {
    it('normalizes every result to succeeded, failed_definite or unknown', function () {
        expect(array_map(fn ($c) => $c->value, ProviderOutcome::cases()))->toBe(['succeeded', 'failed_definite', 'unknown'])
            ->and(ProviderResult::succeeded('P-1')->outcome)->toBe(ProviderOutcome::Succeeded)
            ->and(ProviderResult::failedDefinite('E1')->outcome)->toBe(ProviderOutcome::FailedDefinite)
            ->and(ProviderResult::unknown()->outcome)->toBe(ProviderOutcome::Unknown)
            ->and(ProviderOutcome::Unknown->isDefinite())->toBeFalse();
    });

    it('makes result messages and codes safe', function () {
        $result = ProviderResult::failedDefinite(str_repeat('E', 80), "Number 08012345678 failed\n for meter 45012345678901 ".str_repeat('x', 300));

        expect($result->message)->not->toContain('08012345678')->not->toContain('45012345678901')->toContain('678')
            ->and(mb_strlen($result->message))->toBe(255)
            ->and(mb_strlen($result->errorCode))->toBe(50)
            ->and(ProviderResult::succeeded('  ')->providerReference)->toBeNull();
    });

    it('keeps the recipient and credentials out of debug output', function () {
        $plan = pv2Plan();
        $context = pv2Registry()->contextFor(pv2Provider('fake-provider', $plan));

        expect(print_r(pv2Request(), true))->not->toContain('08012345678')
            ->and(print_r($context, true))->not->toContain(PV2_KEY)
            ->and($context->credential(CredentialKey::ApiKey))->toBe(PV2_KEY);
    });

    it('is implemented by the test-only adapters, which live only in tests', function () {
        expect(new FakeProvider)->toBeInstanceOf(ProviderAdapter::class)
            ->and((new FakeProvider)->purchaseIsIdempotent())->toBeFalse()
            ->and(file_exists(app_path('Services/Providers/FakeProvider.php')))->toBeFalse();
    });
});

describe('registry', function () {
    it('ships with no adapters in production configuration', function () {
        $config = require base_path('config/providers.php');

        expect($config['drivers'])->toBe([]);
        config(['providers.drivers' => $config['drivers']]);
        expect(pv2Registry()->adapters())->toBe([])->and(pv2Registry()->hasAdapter('fake-provider'))->toBeFalse();
    });

    it('resolves explicitly registered adapters by driver', function () {
        $registry = pv2Registry();

        expect(array_keys($registry->adapters()))->toBe(['fake-provider', 'http-test-provider'])
            ->and($registry->hasAdapter('fake-provider'))->toBeTrue()
            ->and($registry->hasAdapter('ratel'))->toBeFalse()
            ->and($registry->hasAdapter(null))->toBeFalse()
            ->and($registry->hasAdapter(''))->toBeFalse();
    });

    it('ignores classes that are not adapters or whose driver name does not match', function () {
        config(['providers.drivers' => ['wrong-name' => FakeProvider::class, 'not-adapter' => stdClass::class, 'missing' => 'App\\Nope']]);

        expect(pv2Registry()->adapters())->toBe([]);
    });

    it('answers whether an adapter supports a service', function () {
        $adapter = new FakeProvider;

        expect(pv2Registry()->supportsService($adapter, 'data'))->toBeTrue()
            ->and(pv2Registry()->supportsService($adapter, 'cable-tv'))->toBeFalse();
    });

    it('builds a call context only with an adapter and every credential it needs', function () {
        $plan = pv2Plan();

        expect(fn () => pv2Registry()->contextFor(pv2Provider(null, $plan)))->toThrow(ProviderNotConfigured::class)
            ->and(fn () => pv2Registry()->contextFor(pv2Provider('fake-provider', $plan, [CredentialKey::Token->value => 'x-token-value'])))
            ->toThrow(ProviderNotConfigured::class, 'api_key');
    });
});

describe('executable routes', function () {
    it('is executable when Phase 7 says eligible, an adapter is installed and it supports the service', function () {
        $plan = pv2Plan();
        pv2Route($plan, pv2Provider('fake-provider', $plan));

        expect(pv2Registry()->executableFor($plan))->toHaveCount(1);
    });

    it('is not executable without an installed adapter, although Phase 7 still calls it eligible', function (?string $driver) {
        $plan = pv2Plan();
        pv2Route($plan, pv2Provider($driver, $plan));

        expect(app(RouteResolver::class)->eligibleFor($plan))->toHaveCount(1)
            ->and(pv2Registry()->executableFor($plan))->toBe([]);
    })->with(['no driver' => [null], 'unknown driver' => ['ratel'], 'empty driver' => ['']]);

    it('is not executable when the adapter does not support the service', function () {
        $plan = pv2Plan('cable-tv');
        pv2Route($plan, pv2Provider('fake-provider', $plan));

        expect(app(RouteResolver::class)->eligibleFor($plan))->toHaveCount(1)
            ->and(pv2Registry()->executableFor($plan))->toBe([]);
    });

    it('never makes a Phase 7 ineligible route executable', function (Closure $break) {
        $plan = pv2Plan();
        $provider = pv2Provider('fake-provider', $plan);
        $route = pv2Route($plan, $provider);
        $break($plan, $provider, $route);
        $plan->refresh();

        $candidate = app(RouteResolver::class)->candidatesFor($plan)[0];
        expect($candidate->eligible)->toBeFalse()
            ->and(pv2Registry()->isExecutable($candidate))->toBeFalse()
            ->and(pv2Registry()->executableFor($plan))->toBe([]);
    })->with([
        'route disabled' => fn ($plan, $provider, $route) => $route->forceFill(['is_active' => false])->save(),
        'provider inactive' => fn ($plan, $provider, $route) => $provider->forceFill(['status' => 'inactive'])->save(),
        'plan disabled' => fn ($plan, $provider, $route) => $plan->forceFill(['is_active' => false])->save(),
        'missing plan code' => fn ($plan, $provider, $route) => $route->forceFill(['provider_plan_code' => null])->save(),
        'credentials missing' => fn ($plan, $provider, $route) => ProviderCredential::where('provider_id', $provider->id)->delete(),
    ]);

    it('keeps Phase 7 order and drops only non-executable routes', function () {
        $plan = pv2Plan();
        pv2Route($plan, pv2Provider('fake-provider', $plan), 1);
        pv2Route($plan, pv2Provider(null, $plan), 2);
        $third = pv2Route($plan, pv2Provider('fake-provider', $plan), 3);

        $executable = pv2Registry()->executableFor($plan);
        expect(array_map(fn ($c) => $c->route->priority, $executable))->toBe([1, 3])
            ->and($executable[1]->route->is($third))->toBeTrue();
    });
});

describe('outcome rule (ProviderCaller)', function () {
    function pv2Call(string $script): ProviderResult
    {
        $plan = pv2Plan();
        FakeProvider::$purchaseScript = [$script];

        return app(ProviderCaller::class)->purchase(new FakeProvider, pv2Request(), pv2Registry()->contextFor(pv2Provider('fake-provider', $plan)));
    }

    it('passes documented outcomes through', function (string $script, ProviderOutcome $outcome) {
        expect(pv2Call($script)->outcome)->toBe($outcome);
    })->with([['succeeded', ProviderOutcome::Succeeded], ['failed_definite', ProviderOutcome::FailedDefinite], ['unknown', ProviderOutcome::Unknown]]);

    it('turns a timeout into unknown, never a failure', function () {
        $result = pv2Call('timeout');

        expect($result->outcome)->toBe(ProviderOutcome::Unknown)->and($result->errorCode)->toBe('no_response');
    });

    it('turns an adapter exception into unknown without leaking its message', function () {
        Log::spy();
        $result = pv2Call('exception');

        expect($result->outcome)->toBe(ProviderOutcome::Unknown)->and($result->message)->not->toContain('NOT-REAL');
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $context) => ! str_contains(json_encode($context), 'NOT-REAL'));
    });

    it('calls the provider exactly once per purchase call', function () {
        pv2Call('unknown');

        expect(FakeProvider::$calls)->toHaveCount(1);
    });

    it('refuses to query an adapter without a documented status query', function () {
        $plan = pv2Plan();
        FakeProvider::$queryable = false;

        expect(fn () => app(ProviderCaller::class)->query(new FakeProvider, new ProviderQueryRequest('PRA-1', null, 'data'),
            pv2Registry()->contextFor(pv2Provider('fake-provider', $plan))))->toThrow(LogicException::class);
    });
});

describe('ProviderHttpClient', function () {
    function pv2HttpContext(): ProviderContext
    {
        $plan = pv2Plan('airtime');

        return pv2Registry()->contextFor(pv2Provider('http-test-provider', $plan, [CredentialKey::SecretKey->value => PV2_SECRET]));
    }

    function pv2HttpPurchase(): ProviderResult
    {
        return app(ProviderCaller::class)->purchase(new HttpTestProvider, pv2Request(), pv2HttpContext());
    }

    it('sends over https to the declared host with the adapter credential', function () {
        Http::fake([HttpTestProvider::API.'/topup' => Http::response(['status' => 'delivered', 'id' => 'HP-1'])]);

        $result = pv2HttpPurchase();

        expect($result->outcome)->toBe(ProviderOutcome::Succeeded)->and($result->providerReference)->toBe('HP-1');
        Http::assertSent(fn (Request $r) => $r->url() === HttpTestProvider::API.'/topup' && $r->hasHeader('Authorization', 'Bearer '.PV2_SECRET));
        Http::assertSentCount(1);
    });

    it('refuses non-https and undeclared hosts without sending anything', function (string $url) {
        expect(fn () => app(ProviderHttpClient::class)->send(ProviderCallType::Purchase, 'POST', $url, ['api.http-provider.test'], 20))
            ->toThrow(ProviderRequestRefused::class);
        Http::assertNothingSent();
    })->with([
        'http' => 'http://api.http-provider.test/topup',
        'other host' => 'https://evil.example/topup',
        'metadata ip' => 'https://169.254.169.254/latest',
        'lookalike' => 'https://api.http-provider.test.evil.example/topup',
        'userinfo' => 'https://api.http-provider.test@evil.example/topup',
        'port' => 'https://api.http-provider.test:8443/topup',
    ]);

    it('maps a documented rejection to failed_definite', function () {
        Http::fake(['*' => Http::response(['status' => 'rejected', 'code' => 'invalid_number', 'id' => 'HP-2'])]);

        expect(pv2HttpPurchase()->outcome)->toBe(ProviderOutcome::FailedDefinite);
    });

    it('treats 5xx, 4xx without a documented status, non-JSON and undocumented statuses as unknown', function (Closure $response) {
        Http::fake(['*' => $response]);

        expect(pv2HttpPurchase()->outcome)->toBe(ProviderOutcome::Unknown);
    })->with([
        '500' => fn () => Http::response(['error' => 'down'], 500),
        '503' => fn () => Http::response('', 503),
        '400' => fn () => Http::response(['error' => 'bad'], 400),
        'non-json' => fn () => Http::response('<html>ok</html>', 200),
        'undocumented status' => fn () => Http::response(['status' => 'queued'], 200),
        'missing status' => fn () => Http::response(['ok' => true], 200),
    ]);

    it('treats a timeout or connection failure as unknown', function () {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        expect(pv2HttpPurchase()->outcome)->toBe(ProviderOutcome::Unknown);
    });

    it('never retries a purchase call, even on errors that a query would retry', function (Closure $response) {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls, $response) {
            $calls++;

            return $response();
        }]);

        pv2HttpPurchase();
        expect($calls)->toBe(1);
    })->with([
        'server error' => fn () => Http::response(['error' => 'down'], 502),
        'connection error' => fn () => throw new ConnectionException('reset'),
    ]);

    it('retries a status query only on network errors and 5xx, within the configured limit', function () {
        Http::fakeSequence(HttpTestProvider::API.'/status/*')
            ->pushFailedConnection()->push(['error' => 'busy'], 503)->push(['status' => 'delivered', 'id' => 'HP-9']);

        $result = app(ProviderCaller::class)->query(new HttpTestProvider, new ProviderQueryRequest('PRA-9', null, 'airtime'), pv2HttpContext());

        expect($result->outcome)->toBe(ProviderOutcome::Succeeded);
        Http::assertSentCount(3);
    });

    it('gives up after the query retries and reports unknown', function () {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls) {
            $calls++;

            return Http::response(['error' => 'down'], 500);
        }]);

        $result = app(ProviderCaller::class)->query(new HttpTestProvider, new ProviderQueryRequest('PRA-9', null, 'airtime'), pv2HttpContext());

        expect($result->outcome)->toBe(ProviderOutcome::Unknown)->and($calls)->toBe(3);
    });

    it('uses the adapter timeout clamped to the configured range', function () {
        expect(ProviderHttpClient::timeout(25))->toBe(25)
            ->and(ProviderHttpClient::timeout(1))->toBe(5)
            ->and(ProviderHttpClient::timeout(600))->toBe(60)
            ->and(ProviderHttpClient::timeout(0))->toBe(30)
            ->and(config('providers.http.connect_timeout'))->toBe(5);
    });

    it('logs failures without credentials, tokens or customer data', function () {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => 'bad', 'phone' => '08012345678', 'customer_name' => 'Ada Obi', 'access_token' => 'tok-leak-1',
            'detail' => 'number 08012345678 rejected', 'nested' => ['meter_number' => '45012345678901', 'note' => 'ok']], 500)]);

        pv2HttpPurchase();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $logged = json_encode($context);
            foreach (['08012345678', 'Ada Obi', 'tok-leak-1', '45012345678901', PV2_SECRET, 'Bearer'] as $secret) {
                if (str_contains($logged, $secret)) {
                    return false;
                }
            }

            return $context['endpoint'] === 'api.http-provider.test/topup' && $context['status'] === 500 && $context['detail']['nested']['note'] === 'ok';
        });
    });

    it('masks numbers in logged paths and never logs query strings', function () {
        Log::spy();
        Http::fake(['*' => Http::response('down', 500)]);

        try {
            app(ProviderHttpClient::class)->send(ProviderCallType::Query, 'GET', 'https://api.http-provider.test/lookup/08012345678?token=tok-leak-2',
                ['api.http-provider.test'], 20);
        } catch (ProviderCallUncertain) {
        }

        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $context) => $context['endpoint'] === 'api.http-provider.test/lookup/[number]'
            && ! str_contains(json_encode($context), 'tok-leak-2'));
    });
});

it('adds no real provider adapter or provider credentials', function () {
    expect(collect(File::allFiles(app_path()))->map->getFilename()->filter(fn ($f) => preg_match('/(ratel|bangansuba|monnify|aspfiy|paymentpoint)/i', $f))->values()->all())->toBe([])
        ->and(ProviderCredential::count())->toBe(0);
});
