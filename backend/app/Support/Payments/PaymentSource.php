<?php

namespace App\Support\Payments;

/** What triggered a payment status change (kept in payment_status_changes.source). */
enum PaymentSource: string
{
    case Customer = 'customer';
    case Webhook = 'webhook';
    case Return = 'return';
    case Reconcile = 'reconcile';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Webhook => 'Gateway webhook',
            self::Return => 'Customer return',
            self::Reconcile => 'Reconciliation',
            self::Admin => 'Staff',
        };
    }
}
