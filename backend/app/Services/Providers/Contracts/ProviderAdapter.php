<?php

namespace App\Services\Providers\Contracts;

use App\Services\Providers\Data\ProviderContext;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Support\Providers\CredentialKey;

/**
 * Contract every provider adapter implements. All provider-specific behaviour
 * (endpoints, authentication, request and response shapes, statuses, amount
 * units) lives inside the adapter and must come from the provider's verified
 * official documentation. Adapters make HTTP calls only through
 * ProviderContext::$http, to the hosts they declare.
 *
 * Outcome rule: return succeeded or failed_definite only when the provider's
 * documented response says so. Anything else (timeout, connection error, 5xx,
 * malformed or undocumented response, ambiguity) is unknown. The engine never
 * refunds or fails over after an unknown outcome.
 */
interface ProviderAdapter
{
    /** Stable driver name, matched against providers.driver and the key in config('providers.drivers'). */
    public function driver(): string;

    public function label(): string;

    /** @return list<string> slugs of the catalog services this adapter can deliver */
    public function supportedServices(): array;

    /** @return list<CredentialKey> credentials the adapter needs */
    public function credentialKeys(): array;

    /** @return list<string> https hosts this adapter may call (enforced by ProviderHttpClient) */
    public function apiHosts(): array;

    /** Request timeout in seconds (clamped by config('providers.http')). Keep it conservative. */
    public function timeoutSeconds(): int;

    /** True only if the provider documents that repeating a purchase with the same request reference cannot deliver twice. */
    public function purchaseIsIdempotent(): bool;

    /** True only if the provider documents a status query for an earlier purchase. */
    public function canQuery(): bool;

    public function purchase(ProviderPurchaseRequest $request, ProviderContext $context): ProviderResult;

    /** Only called when canQuery() is true. */
    public function query(ProviderQueryRequest $request, ProviderContext $context): ProviderResult;
}
