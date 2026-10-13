<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\GatewayNotConfigured;
use App\Models\PaymentGateway;
use App\Services\Payments\Contracts\PaymentGateway as GatewayAdapter;
use App\Services\Payments\Data\GatewayContext;
use App\Support\Payments\GatewayCapability;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentLimits;
use Illuminate\Support\Collection;

/**
 * Joins gateway records with the adapters that exist in code
 * (config('payments.drivers')) and decides which gateways are usable. No
 * gateway selection lives in controllers. A gateway is configured only when
 * its adapter exists, its mode is allowed (live needs payments.live_enabled)
 * and every credential the adapter requires for that mode is set. There is
 * never a fallback from live to sandbox.
 */
class GatewayRegistry
{
    public function __construct(private PaymentHttpClient $http) {}

    /** @return array<string, GatewayAdapter> driver => adapter */
    public function adapters(): array
    {
        $adapters = [];
        foreach (config('payments.drivers', []) as $driver => $class) {
            if (is_string($class) && is_subclass_of($class, GatewayAdapter::class)) {
                $adapters[$driver] = app($class);
            }
        }

        return $adapters;
    }

    public function adapterFor(PaymentGateway $gateway): ?GatewayAdapter
    {
        return $this->adapters()[$gateway->driver] ?? null;
    }

    /** Why the gateway cannot be used in the mode (default: its current mode), or null when it is configured. */
    public function configurationProblem(PaymentGateway $gateway, ?GatewayMode $mode = null): ?string
    {
        $mode ??= $gateway->mode;
        $adapter = $this->adapterFor($gateway);
        if ($adapter === null) {
            return 'Driver not available';
        }
        if ($mode === GatewayMode::Live && ! PaymentLimits::liveEnabled()) {
            return 'Live payments are switched off';
        }

        $gateway->loadMissing('credentials');
        $missing = array_diff($adapter->requiredCredentials($mode), $gateway->credentialKeysSet($mode));

        return $missing === [] ? null : 'Missing '.$mode->value.' credentials: '.implode(', ', $missing);
    }

    public function isConfigured(PaymentGateway $gateway, ?GatewayMode $mode = null): bool
    {
        return $this->configurationProblem($gateway, $mode) === null;
    }

    /** Active, configured gateways that offer wallet funding, highest priority first. @return Collection<int, PaymentGateway> */
    public function usableForFunding(): Collection
    {
        return PaymentGateway::with('credentials')
            ->where('status', GatewayStatus::Active->value)->where('wallet_funding', true)
            ->orderBy('priority')->get()
            ->filter(fn (PaymentGateway $g) => $this->isConfigured($g)
                && in_array(GatewayCapability::WalletFunding, $this->adapterFor($g)->capabilities(), true))
            ->values();
    }

    /**
     * Call context with decrypted credentials for exactly one mode: the
     * payment's own mode for verification, the gateway's current mode
     * otherwise. Never falls back to another mode.
     *
     * @throws GatewayNotConfigured
     */
    public function contextFor(PaymentGateway $gateway, ?GatewayMode $mode = null): GatewayContext
    {
        $mode ??= $gateway->mode;
        if (($problem = $this->configurationProblem($gateway, $mode)) !== null) {
            throw new GatewayNotConfigured("Gateway not configured: {$problem}.");
        }

        $credentials = $gateway->credentials->filter(fn ($c) => $c->mode === $mode)
            ->mapWithKeys(fn ($c) => [$c->key => $c->value])->all();

        return new GatewayContext($gateway, $mode, $credentials, $this->http);
    }
}
