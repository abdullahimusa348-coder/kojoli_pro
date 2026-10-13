<?php

namespace App\Actions\Admin\Payments;

use App\Models\PaymentGateway;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Payments\GatewayStatus;

/**
 * Active, maintenance or inactive (payments.gateways). Only active gateways
 * that are fully configured are offered to customers; there is no automatic
 * failover between gateways.
 */
class SetGatewayStatus
{
    public function __construct(private PaymentRules $rules) {}

    public function handle(PaymentGateway $gateway, GatewayStatus $status, SystemUser $actor): void
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsGateways);

        $gateway->forceFill(['status' => $status])->save();
    }
}
