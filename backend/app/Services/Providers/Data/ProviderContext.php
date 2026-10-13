<?php

namespace App\Services\Providers\Data;

use App\Models\Provider;
use App\Services\Providers\ProviderHttpClient;
use App\Support\Providers\CredentialKey;

/**
 * Everything an adapter needs for one call: the provider record, decrypted
 * credentials (in memory only, never logged or serialized) and the shared
 * provider HTTP client. Adapters never use the admin-entered base URL: they
 * call only the hosts they declare.
 */
final class ProviderContext
{
    /** @param  array<string, string>  $credentials  keyed by CredentialKey value */
    public function __construct(
        public readonly Provider $provider,
        private readonly array $credentials,
        public readonly ProviderHttpClient $http,
    ) {}

    public function credential(CredentialKey $key): ?string
    {
        return $this->credentials[$key->value] ?? null;
    }

    /** @return array<string, mixed> never exposes credential values */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider->code, 'credentials' => array_fill_keys(array_keys($this->credentials), '[redacted]')];
    }
}
