<?php

namespace App\Actions\Admin\Payments;

use App\Exceptions\Payments\GatewayException;
use App\Models\Payment;
use App\Models\SystemUser;
use App\Services\Payments\PaymentService;
use App\Support\Enums\SystemPermission;
use App\Support\Payments\PaymentSource;

/**
 * Asks the gateway again (payments.manage). The result is applied by the
 * payment engine exactly as for a webhook: a payment is credited only when
 * the gateway confirms the exact amount and currency. Staff can never mark a
 * payment paid by hand.
 */
class RecheckPayment
{
    public function __construct(private PaymentRules $rules, private PaymentService $payments) {}

    /** @throws GatewayException when the gateway cannot be asked (nothing changes) */
    public function handle(Payment $payment, SystemUser $actor): Payment
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsManage);

        return $this->payments->verifyAndFinalize($payment, PaymentSource::Admin, $actor);
    }
}
