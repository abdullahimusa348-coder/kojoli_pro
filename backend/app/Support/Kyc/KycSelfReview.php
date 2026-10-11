<?php

namespace App\Support\Kyc;

use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Whether a staff reviewer is the same person as a customer, as far as the app can tell (Phase 13 CP2a). A reviewer who holds the
 * customer's email, or the customer's phone number once both are normalized the way the app stores phone numbers, cannot decide that
 * customer's submission. Blank contact details never match: two empty fields are not one person, and the customer and staff tables
 * are separate, so both can hold a blank email. This is a best-effort check, not proof of identity. The ledger and the review model
 * both call it, so a direct model write cannot skip it.
 */
final class KycSelfReview
{
    public static function sameContact(User $customer, SystemUser $reviewer): bool
    {
        $customerEmail = Str::lower(trim((string) $customer->email));
        if ($customerEmail !== '' && $customerEmail === Str::lower(trim((string) $reviewer->email))) {
            return true;
        }

        $customerPhone = User::normalizePhone((string) $customer->phone);

        return $customerPhone !== '' && $customerPhone === User::normalizePhone((string) $reviewer->phone);
    }
}
