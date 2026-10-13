<?php

namespace App\Support\Wallet;

/**
 * Customer transaction types. Phase 8: adjustments; Phase 9: wallet funding from verified payments; Phase 10: purchases (debit, and the
 * refund credit of a definitely failed purchase); Phase 12: referral commissions (the credit, and a staff reversal as a separate debit).
 */
enum TransactionType: string
{
    case Adjustment = 'adjustment';
    case Funding = 'funding';
    case Purchase = 'purchase';
    case Commission = 'commission';

    public function label(): string
    {
        return match ($this) {
            self::Adjustment => 'Adjustment',
            self::Funding => 'Wallet funding',
            self::Purchase => 'Purchase',
            self::Commission => 'Referral commission',
        };
    }
}
