<?php

namespace App\Services\Providers;

use App\Exceptions\Providers\ProviderNotConfigured;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\ProviderContext;

/**
 * Joins provider records with the adapters that exist in code
 * (config('providers.drivers')). A route is executable only when:
 * 1. Phase 7 RouteResolver marks it eligible (unchanged rules), AND
 * 2. its provider's driver has an installed adapter, AND
 * 3. that adapter supports the route's service.
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
        if (! $candidate->eligible) {
            return false;
        }
        $route = $candidate->route->loadMissing('provider', 'plan.product.service');
        $adapter = $this->adapterFor($route->provider);
        $slug = $route->plan?->product?->service?->slug;

        return $adapter !== null && $slug !== null && $this->supportsService($adapter, $slug);
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
