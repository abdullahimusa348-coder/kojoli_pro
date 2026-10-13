<?php

namespace App\Services\Payments\Data;

use App\Models\PaymentGateway;
use App\Services\Payments\PaymentHttpClient;
use App\Support\Payments\GatewayMode;

/**
 * Everything an adapter needs for one call: the gateway record, the effective
 * mode, decrypted credentials (in memory only, never logged or serialized)
 * and the shared HTTP client.
 */
final class GatewayContext
{
    /** @param  array<string, string>  $credentials */
    public function __construct(
        public readonly PaymentGateway $gateway,
        public readonly GatewayMode $mode,
        private readonly array $credentials,
        public readonly PaymentHttpClient $http,
    ) {}

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /** @return array<string, mixed> never exposes credential values */
    public function __debugInfo(): array
    {
        return ['gateway' => $this->gateway->code, 'mode' => $this->mode->value, 'credentials' => array_fill_keys(array_keys($this->credentials), '[redacted]')];
    }
}
