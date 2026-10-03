<?php

namespace App\Support\Wallet;

/**
 * Ledger entry types. Phase 8: admin adjustments and reversals; Phase 9:
 * funding from verified gateway payments; Phase 10: purchase debits and
 * refunds of definitely failed purchases. Later phases add commission (12)
 * and withdrawal (14).
 */
enum LedgerEntryType: string
{
    case AdjustmentCredit = 'adjustment_credit';
    case AdjustmentDebit = 'adjustment_debit';
    case Reversal = 'reversal';
    case Funding = 'funding';
    case PurchaseDebit = 'purchase_debit';
    case PurchaseRefund = 'purchase_refund';

    public function label(): string
    {
        return match ($this) {
            self::AdjustmentCredit => 'Adjustment (credit)',
            self::AdjustmentDebit => 'Adjustment (debit)',
            self::Reversal => 'Reversal',
            self::Funding => 'Wallet funding',
            self::PurchaseDebit => 'Purchase',
            self::PurchaseRefund => 'Purchase refund',
        };
    }
}
