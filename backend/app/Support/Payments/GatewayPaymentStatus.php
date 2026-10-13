<?php

namespace App\Support\Payments;

/** A gateway's answer, normalized by its adapter. Unknown means the gateway gave no usable answer. */
enum GatewayPaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
