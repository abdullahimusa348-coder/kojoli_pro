<?php

namespace App\Support\Wallet;

/**
 * Ledger entry types. Phase 8: admin adjustments and reversals; Phase 9:
 * funding from verified gateway payments; Phase 10: purchase debits and
 * refunds of definitely failed purchases; Phase 12: referral commission
 * credits and commission reversals (a separate debit; the original credit is
 * never reversed or changed). A later phase adds withdrawal (14).
 */
enum LedgerEntryType: string
{
    case AdjustmentCredit = 'adjustment_credit';
    case AdjustmentDebit = 'adjustment_debit';
    case Reversal = 'reversal';
    case Funding = 'funding';
    case PurchaseDebit = 'purchase_debit';
    case PurchaseRefund = 'purchase_refund';
    case CommissionCredit = 'commission_credit';
    case CommissionReversal = 'commission_reversal';

    public function label(): string
    {
        return match ($this) {
            self::AdjustmentCredit => 'Adjustment (credit)',
            self::AdjustmentDebit => 'Adjustment (debit)',
            self::Reversal => 'Reversal',
            self::Funding => 'Wallet funding',
            self::PurchaseDebit => 'Purchase',
            self::PurchaseRefund => 'Purchase refund',
            self::CommissionCredit => 'Referral commission',
            self::CommissionReversal => 'Commission reversal',
        };
    }
}
