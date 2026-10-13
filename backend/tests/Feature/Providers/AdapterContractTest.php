<?php

use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderResult;
use App\Support\Providers\CredentialKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Providers\AdapterContract;
use Tests\Support\Providers\ContractProbeProvider;
use Tests\Support\Providers\FakeProvider;
use Tests\Support\Providers\HttpTestProvider;

/*
 * Phase 10 Step 2, CP1: the shared provider adapter contract kit
 * (tests/Support/Providers/AdapterContract.php) and the production adapter
 * policy. The kit is checked against the test-only adapters and against a
 * probe adapter broken one rule at a time. Every HTTP call is faked.
 */

beforeEach(function () {
    ContractProbeProvider::reset();
    FakeProvider::reset();
    HttpTestProvider::$timeout = 25;
    Http::preventStrayRequests();
});

describe('definition contract', function () {
    it('accepts the test-only adapters', function (string $class) {
        expect(AdapterContract::definitionViolations(app($class)))->toBe([]);
    })->with([FakeProvider::class, HttpTestProvider::class, ContractProbeProvider::class]);

    it('names the rule a broken declaration breaks', function (Closure $break, string $rule) {
        $break();

        expect(AdapterContract::definitionViolations(new ContractProbeProvider))->toContain($rule);
    })->with([
        'driver with spaces' => [fn () => ContractProbeProvider::$driver = 'Bad Driver', 'driver() must be 2 to 50 lowercase letters, digits, "-" or "_", starting with a letter.'],
        'driver too long' => [fn () => ContractProbeProvider::$driver = 'a'.str_repeat('b', 50), 'driver() must be 2 to 50 lowercase letters, digits, "-" or "_", starting with a letter.'],
        'empty label' => [fn () => ContractProbeProvider::$label = '  ', 'label() must not be empty.'],
        'no services' => [fn () => ContractProbeProvider::$services = [], 'supportedServices() must be a non-empty list.'],
        'service that is not a slug' => [fn () => ContractProbeProvider::$services = ['Data Bundle'], 'supportedServices() must list catalog service slugs only.'],
        'repeated service' => [fn () => ContractProbeProvider::$services = ['data', 'data'], 'supportedServices() must not repeat a service.'],
        'credential that is not a key' => [fn () => ContractProbeProvider::$credentialKeys = ['api_key'], 'credentialKeys() must list CredentialKey cases only.'],
        'repeated credential' => [fn () => ContractProbeProvider::$credentialKeys = [CredentialKey::ApiKey, CredentialKey::ApiKey], 'credentialKeys() must not repeat a key.'],
        'no hosts' => [fn () => ContractProbeProvider::$hosts = [], 'apiHosts() must be a non-empty list of host names.'],
        'host with a scheme' => [fn () => ContractProbeProvider::$hosts = ['https://api.contract-probe.test'], 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).'],
        'host with a port' => [fn () => ContractProbeProvider::$hosts = ['api.contract-probe.test:8443'], 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).'],
        'IP address' => [fn () => ContractProbeProvider::$hosts = ['169.254.169.254'], 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).'],
        'wildcard host' => [fn () => ContractProbeProvider::$hosts = ['*.contract-probe.test'], 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).'],
        'uppercase host' => [fn () => ContractProbeProvider::$hosts = ['API.contract-probe.test'], 'apiHosts() must list bare lowercase host names (no scheme, port, path or IP address).'],
        'repeated host' => [fn () => ContractProbeProvider::$hosts = ['api.contract-probe.test', 'api.contract-probe.test'], 'apiHosts() must not repeat a host.'],
        'timeout too short' => [fn () => ContractProbeProvider::$timeout = 1, 'timeoutSeconds() must be between 5 and 60 seconds.'],
        'timeout too long' => [fn () => ContractProbeProvider::$timeout = 120, 'timeoutSeconds() must be between 5 and 60 seconds.'],
    ]);
});

describe('behaviour contract', function () {
    it('accepts the HTTP test adapters', function (string $class) {
        expect(AdapterContract::behaviourViolations(app($class)))->toBe([]);
    })->with([HttpTestProvider::class, ContractProbeProvider::class]);

    it('catches an adapter that treats a server error as delivered', function () {
        ContractProbeProvider::$map = fn (int $status) => $status >= 500 ? ProviderResult::succeeded('X-1') : ProviderResult::unknown();

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('purchase() for data on HTTP 500 with a JSON body: must be unknown, not succeeded.')
            ->toContain('query() for airtime on HTTP 503 without JSON: must be unknown, not succeeded.');
    });

    it('catches an adapter that treats an undocumented answer as a definite failure', function () {
        ContractProbeProvider::$map = fn (int $status) => $status === 200 ? ProviderResult::failedDefinite('declined') : ProviderResult::unknown();

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('purchase() for airtime on HTTP 200 with an undocumented status: must be unknown, not failed_definite.')
            ->toContain('purchase() for data on HTTP 200 with an empty JSON body: must be unknown, not failed_definite.');
    });

    it('catches an adapter that puts a credential value into its result', function () {
        ContractProbeProvider::$map = fn (int $status, ?array $json, ProviderContext $context) => ProviderResult::unknown('err', 'Key '.$context->credential(CredentialKey::ApiKey));

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('purchase() for data on HTTP 503 without JSON: the result contains the api_key credential value.');
    });

    it('catches an adapter that logs a credential value or the recipient', function () {
        ContractProbeProvider::$map = function (int $status, ?array $json, ProviderContext $context) {
            Log::info('debug', ['key' => $context->credential(CredentialKey::ApiKey), 'to' => '08012345678']);

            return ProviderResult::unknown();
        };

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('A log entry contains the api_key credential value.')
            ->toContain('A log entry contains the recipient number.');
    });

    it('catches an adapter that sends a purchase request twice', function () {
        ContractProbeProvider::$sendsPerPurchase = 2;

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('purchase() for data on HTTP 500 with a JSON body: sent the same purchase request more than once (purchase calls are never retried).');
    });

    it('catches an adapter that calls a host it does not declare', function () {
        ContractProbeProvider::$directUrl = 'https://undeclared.contract-probe.test/buy';

        expect(AdapterContract::behaviourViolations(new ContractProbeProvider))
            ->toContain('purchase() for data on HTTP 500 with a JSON body: sent a request to a host the adapter does not declare (https only, no port).');
    });

    it('fakes every request and puts the original HTTP client back', function () {
        $before = Http::getFacadeRoot();

        AdapterContract::behaviourViolations(new ContractProbeProvider);

        expect(Http::getFacadeRoot())->toBe($before);
    });
});

describe('production adapter policy', function () {
    it('holds for every adapter registered in config/providers.php', function () {
        $config = require base_path('config/providers.php');

        expect($config['drivers'])->toBeArray()
            ->and(AdapterContract::productionViolations($config['drivers']))->toBe([]);
    });

    it('accepts an adapter that meets every rule', function () {
        expect(AdapterContract::productionViolations(['contract_probe' => ContractProbeProvider::class], allowTestAdapters: true))->toBe([]);
    });

    it('requires a documented status check before an adapter can be enabled', function () {
        ContractProbeProvider::$canQuery = false;

        expect(AdapterContract::productionViolations(['contract_probe' => ContractProbeProvider::class], allowTestAdapters: true))
            ->toBe(['contract_probe: production adapters must have a documented status check (canQuery()), so unclear outcomes can be settled with the provider.']);
    });

    it('rejects test-only adapters, mismatched names, missing classes and drivers an administrator cannot enter', function () {
        $violations = AdapterContract::productionViolations(['fake-provider' => FakeProvider::class, 'other_name' => ContractProbeProvider::class, 'missing' => 'App\\Nope']);

        expect($violations)->toContain('fake-provider: test-only adapters must never be registered for production.')
            ->toContain('fake-provider: an administrator cannot enter this driver on the provider form.')
            ->toContain("other_name: the adapter's driver() must equal its registered name.")
            ->toContain('other_name: test-only adapters must never be registered for production.')
            ->toContain('missing: must map a driver name to a ProviderAdapter class.');
    });

    it('rejects an adapter that breaks the behaviour contract', function () {
        ContractProbeProvider::$sendsPerPurchase = 2;

        expect(AdapterContract::productionViolations(['contract_probe' => ContractProbeProvider::class], allowTestAdapters: true))
            ->toContain('contract_probe: purchase() for data on HTTP 500 with a JSON body: sent the same purchase request more than once (purchase calls are never retried).');
    });
});
