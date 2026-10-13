<?php

namespace App\Support\Customer;

use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchases\PurchaseCatalog;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Support\Collection;

/**
 * Data for the customer dashboard home: account information, the real
 * main-wallet balance (₦0.00 until the first entry), a Buy shortcut while
 * something can be bought right now (never during maintenance mode), and the
 * customer's own latest purchases. Only the customer's own records are
 * queried, and only customer-facing purchase fields.
 */
class CustomerDashboard
{
    public const RECENT_PURCHASES = 5;

    private const PURCHASE_COLUMNS = ['id', 'reference', 'user_id', 'service_name', 'product_name', 'plan_name', 'network', 'recipient',
        'recipient_type', 'face_value_kobo', 'amount_kobo', 'amount_type', 'status', 'created_at'];

    /**
     * @return array{
     *     user: User,
     *     emailStatus: array{label: string, tone: string},
     *     showVerificationPrompt: bool,
     *     walletBalanceKobo: int,
     *     shortcuts: list<array{item: CustomerNav, description: string}>,
     *     recentPurchases: Collection<int, Purchase>,
     *     purchasesInProgress: int
     * }
     */
    public static function for(User $user): array
    {
        $required = User::emailVerificationRequired();
        $verified = $user->hasVerifiedEmail();
        // Newest first; the in-progress count (pending or under review) covers all of the customer's purchases.
        // NIN/BVN purchases (Phase 11 CP3) show only their masked number.
        $recent = Purchase::withMaskedRecipients(Purchase::where('user_id', $user->id)->latest('id')->limit(self::RECENT_PURCHASES)->get(self::PURCHASE_COLUMNS));

        return [
            'user' => $user,
            'emailStatus' => match (true) {
                $verified => ['label' => 'Verified', 'tone' => 'good'],
                $required => ['label' => 'Not verified', 'tone' => 'warn'],
                default => ['label' => 'Not required', 'tone' => 'neutral'],
            },
            // Only when verification is switched on and this email is not verified yet.
            'showVerificationPrompt' => $required && ! $verified,
            'walletBalanceKobo' => $user->mainWallet()?->balance_kobo ?? 0,
            'shortcuts' => [
                // The Buy menu item stays visible during maintenance (it explains why); this shortcut does not.
                ...(! MaintenanceMode::active() && app(PurchaseCatalog::class)->hasAnything($user)
                    ? [['item' => CustomerNav::Buy, 'description' => 'Buy data or airtime with your wallet balance.']] : []),
                ['item' => CustomerNav::Account, 'description' => 'View your details and update your name or email.'],
                ['item' => CustomerNav::Security, 'description' => 'Change your password and keep your account safe.'],
            ],
            'recentPurchases' => $recent,
            'purchasesInProgress' => $recent->isEmpty() ? 0 : Purchase::where('user_id', $user->id)
                ->whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Review->value])->count(),
        ];
    }
}
