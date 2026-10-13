<?php

namespace App\Actions\Admin\Purchases;

use App\Models\Purchase;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Services\Purchases\PurchaseService;
use App\Support\Enums\SystemPermission;
use App\Support\Purchases\PurchaseSource;

/**
 * Staff re-check of a pending or review purchase (purchases.manage). Runs the
 * same logic as scheduled reconciliation: only a definite provider outcome
 * settles the purchase. Staff can never mark a purchase successful, or fail
 * and refund it, by hand. Every staff re-check is recorded in the history,
 * also when it found no definite outcome.
 */
class RecheckPurchase
{
    public function __construct(private PurchaseRules $rules, private PurchaseService $purchases) {}

    public function handle(Purchase $purchase, SystemUser $actor): Purchase
    {
        $this->rules->authorize($actor, SystemPermission::PurchasesManage);

        $before = $purchase->fresh()->status;
        $after = $this->purchases->recheck($purchase, PurchaseSource::Admin, $actor);

        if ($after->status === $before && ! $after->isFinal()) {
            // No definite outcome: still record who re-checked, in the append-only history.
            (new PurchaseStatusChange)->forceFill(['purchase_id' => $after->id, 'old_status' => $before, 'new_status' => $before,
                'source' => PurchaseSource::Admin, 'changed_by' => $actor->id, 'note' => 'Re-checked by staff: no definite provider outcome yet.'])->save();
        }

        return $after;
    }
}
