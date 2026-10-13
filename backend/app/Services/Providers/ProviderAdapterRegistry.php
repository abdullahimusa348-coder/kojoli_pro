<?php

namespace App\Services\Providers;

use App\Exceptions\Providers\ProviderNotConfigured;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;
use App\Support\Providers\CredentialKey;

/**
 * Joins provider records with the adapters that exist in code
 * (config('providers.drivers')). A route is executable ("runnable" in the
 * admin area) only when:
 * 1. Phase 7 RouteResolver marks it eligible (unchanged rules), AND
 * 2. its provider's driver has an installed adapter, AND
 * 3. that adapter supports the route's service, AND
 * 4. every credential the adapter needs is set, AND
 * 5. a configured base URL is on a host the adapter declares (https, no
 *    port or login), by the same rule ProviderHttpClient enforces.
 * The executable check is separate from Phase 7 eligibility and never
 * changes it. Production ships with no adapters, so nothing is executable.
 */
class ProviderAdapterRegistry
{
    public function __construct(private RouteResolver $routes, private ProviderHttpClient $http) {}

    /** @return array<string, ProviderAdapter> driver => adapter */
    public function adapters(): array
    {
        $adapters = [];
        foreach (config('providers.drivers', []) as $driver => $class) {
            if (is_string($driver) && is_string($class) && is_subclass_of($class, ProviderAdapter::class)) {
                $adapter = app($class);
                if ($adapter->driver() === $driver) {
                    $adapters[$driver] = $adapter;
                }
            }
        }

        return $adapters;
    }

    public function hasAdapter(?string $driver): bool
    {
        return $driver !== null && $driver !== '' && array_key_exists($driver, $this->adapters());
    }

    public function adapterFor(Provider $provider): ?ProviderAdapter
    {
        return $this->hasAdapter($provider->driver) ? $this->adapters()[$provider->driver] : null;
    }

    public function supportsService(ProviderAdapter $adapter, string $serviceSlug): bool
    {
        return in_array($serviceSlug, $adapter->supportedServices(), true);
    }

    public function isExecutable(RouteCandidate $candidate): bool
    {
        return $this->routeReadiness($candidate)->runnable();
    }

    /**
     * Whether the provider's adapter can be used now (conditions 2, 4 and 5).
     * Reads which credentials are set, never their values.
     */
    public function providerReadiness(Provider $provider): ProviderReadiness
    {
        $adapter = $this->adapterFor($provider);
        if ($adapter === null) {
            return new ProviderReadiness(false, null, [], [], [], null, false, [blank($provider->driver)
                ? 'No driver is set, so no adapter can run this provider.'
                : "No adapter is installed for driver “{$provider->driver}”."]);
        }

        $keys = array_values($adapter->credentialKeys());
        $missing = $provider->loadMissing('credentials')->missingCredentialKeys($keys);
        $baseUrl = $provider->baseUrl();
        $baseUrlAllowed = blank($baseUrl) ? null : ProviderHttpClient::allows($baseUrl, $adapter->apiHosts());

        $problems = [];
        if ($missing !== []) {
            $problems[] = 'Credentials the adapter needs are not set: '.implode(', ', array_map(fn (CredentialKey $k) => $k->label(), $missing)).'.';
        }
        if ($baseUrlAllowed === false) {
            $problems[] = 'The base URL is not on a host this adapter may call (https only, no port).';
        }

        return new ProviderReadiness(true, $adapter->label(), array_values($adapter->supportedServices()), $keys, $missing,
            $baseUrlAllowed, $adapter->canQuery(), $problems);
    }

    /** Whether purchases can use this route now, with every reason it cannot (conditions 1 to 5). */
    public function routeReadiness(RouteCandidate $candidate): RouteReadiness
    {
        $route = $candidate->route->loadMissing('provider', 'plan.product.service');
        $reasons = $candidate->reasons;
        if (! $candidate->eligible && $reasons === []) {
            $reasons[] = 'Not eligible under the route rules.';
        }

        $provider = $this->providerReadiness($route->provider);
        $reasons = [...$reasons, ...$provider->problems];
        $service = $route->plan?->product?->service;
        if ($provider->installed && ($service === null || ! in_array($service->slug, $provider->services, true))) {
            $reasons[] = 'The adapter does not support the '.($service->name ?? 'plan’s').' service.';
        }

        return new RouteReadiness($candidate, array_values($reasons));
    }

    /** @return list<RouteCandidate> executable routes in the order they would be tried */
    public function executableFor(Plan $plan): array
    {
        return array_values(array_filter($this->routes->eligibleFor($plan), fn (RouteCandidate $c) => $this->isExecutable($c)));
    }

    /**
     * Call context with the provider's decrypted credentials.
     *
     * @throws ProviderNotConfigured when there is no adapter or a credential the adapter needs is missing
     */
    public function contextFor(Provider $provider): ProviderContext
    {
        $adapter = $this->adapterFor($provider) ?? throw new ProviderNotConfigured('No adapter is installed for this provider.');
        $provider->loadMissing('credentials');
        $credentials = $provider->credentials->mapWithKeys(fn (ProviderCredential $c) => [$c->key->value => $c->value])->all();

        $missing = array_filter($adapter->credentialKeys(), fn ($key) => ! isset($credentials[$key->value]));
        if ($missing !== []) {
            throw new ProviderNotConfigured('The provider is missing credentials its adapter needs: '.implode(', ', array_map(fn ($k) => $k->value, $missing)).'.');
        }

        return new ProviderContext($provider, $credentials, $this->http);
    }
}
