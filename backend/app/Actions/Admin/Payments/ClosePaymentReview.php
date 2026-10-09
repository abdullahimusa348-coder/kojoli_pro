<?php

namespace App\Actions\Admin\Payments;

use App\Models\Payment;
use App\Models\SystemUser;
use App\Services\Payments\PaymentService;
use App\Support\Enums\SystemPermission;

/** Closes a payment in review as failed, without any credit (payments.manage). The note is kept in the payment history. */
class ClosePaymentReview
{
    public function __construct(private PaymentRules $rules, private PaymentService $payments) {}

    public function handle(Payment $payment, string $note, SystemUser $actor): Payment
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsManage);

        return $this->payments->closeReview($payment, $note, $actor);
    }
}
