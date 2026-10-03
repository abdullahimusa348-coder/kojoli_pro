<?php

namespace App\Support\Purchases;

/** What triggered a purchase status change (kept in purchase_status_changes.source). */
enum PurchaseSource: string
{
    case Customer = 'customer';
    case Execution = 'execution';
    case Reconcile = 'reconcile';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Execution => 'Provider call',
            self::Reconcile => 'Provider re-check',
            self::Admin => 'Staff re-check',
        };
    }
}
