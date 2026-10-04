<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in customer's own purchases: history and result pages. Only the
 * customer's own records are queried; another customer's reference is a 404.
 * No provider, cost, margin or internal error details are shown. A pending
 * purchase's result page reloads itself for its first minutes (see
 * config/purchases.php); the server decides again on every load.
 */
class PurchaseController extends Controller
{
    private const COLUMNS = ['id', 'reference', 'user_id', 'service_name', 'product_name', 'plan_name', 'network', 'recipient', 'face_value_kobo',
        'amount_kobo', 'amount_type', 'status', 'created_at', 'completed_at'];

    public function index(Request $request): View
    {
        return view('user.purchases.index', [
            'purchases' => Purchase::where('user_id', $request->user()->id)->latest('id')->paginate(15, self::COLUMNS),
        ]);
    }

    public function show(Request $request, string $reference): View
    {
        $purchase = Purchase::where('user_id', $request->user()->id)->where('reference', $reference)->firstOrFail(self::COLUMNS);
        // Only while pending and made less than the window ago (at exactly the window it stops).
        $recent = $purchase->created_at->gt(now()->subMinutes((int) config('purchases.customer_refresh_window_minutes')));

        return view('user.purchases.show', [
            'purchase' => $purchase,
            'autoRefreshSeconds' => $purchase->status === PurchaseStatus::Pending && $recent ? (int) config('purchases.customer_refresh_seconds') : null,
        ]);
    }
}
