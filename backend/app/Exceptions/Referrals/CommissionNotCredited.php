<?php

namespace App\Exceptions\Referrals;

use App\Support\Referrals\CommissionFailureReason;
use RuntimeException;

/** A payable referral commission that could not be credited, with its fixed reason code (Phase 12). */
class CommissionNotCredited extends RuntimeException
{
    public function __construct(public readonly CommissionFailureReason $reason)
    {
        parent::__construct($reason->label());
    }
}
