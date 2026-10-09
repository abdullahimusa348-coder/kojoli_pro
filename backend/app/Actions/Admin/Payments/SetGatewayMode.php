<?php

namespace App\Actions\Admin\Payments;

use App\Exceptions\Payments\PaymentException;
use App\Models\PaymentGateway;
use App\Models\SystemUser;
use App\Services\Payments\GatewayRegistry;
use App\Support\Enums\SystemPermission;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\PaymentLimits;

/**
 * Sandbox or live (payments.gateways). Switching to live needs live payments
 * switched on in Settings (payments.live_enabled) and every live credential
 * the adapter requires. Existing payments keep the mode they were created in.
 */
class SetGatewayMode
{
    public function __construct(private PaymentRules $rules, private GatewayRegistry $registry) {}

    public function handle(PaymentGateway $gateway, GatewayMode $mode, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsGateways);

        if ($mode === GatewayMode::Live) {
            if (! PaymentLimits::liveEnabled()) {
                throw new PaymentException('Live payments are switched off in Settings.');
            }
            if (($problem = $this->registry->configurationProblem($gateway->fresh('credentials'), GatewayMode::Live)) !== null) {
                throw new PaymentException("This gateway cannot go live: {$problem}.");
            }
        }

        $gateway->forceFill(['mode' => $mode])->save();
    }
}
