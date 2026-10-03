<?php

namespace App\Actions\Admin\Purchases;

use App\Models\Purchase;
use App\Models\SystemUser;
use App\Services\Purchases\PurchaseService;
use App\Support\Enums\SystemPermission;
use App\Support\Purchases\PurchaseSource;

/**
 * Staff re-check of a pending or review purchase (purchases.manage). Runs the
 * same logic as scheduled reconciliation: only a definite provider outcome
 * settles the purchase. Staff can never mark a purchase successful, or fail
 * and refund it, by hand.
 */
class RecheckPurchase
{
    public function __construct(private PurchaseRules $rules, private PurchaseService $purchases) {}

    public function handle(Purchase $purchase, SystemUser $actor): Purchase
    {
        $this->rules->authorize($actor, SystemPermission::PurchasesManage);

        return $this->purchases->recheck($purchase, PurchaseSource::Admin, $actor);
    }
}
