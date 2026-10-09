<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseResult;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The signed-in customer's own purchases: history and result pages. Only the
 * customer's own records are queried; another customer's reference is a 404.
 * No provider, cost, margin or internal error details are shown. A pending
 * purchase's result page reloads itself for its first minutes (see
 * config/purchases.php); the server decides again on every load.
 * NIN/BVN purchases (Phase 11 CP3) show only the masked number; Exam PIN
 * purchases (CP4) have no recipient and show a dash. The result of a
 * successful NIN/BVN or Exam PIN purchase (for Exam PIN, such as the PIN and
 * its serial) is decrypted here, for its owner's own result page only (never
 * in lists, never for staff), and every result page of these purchases is
 * sent no-store, private.
 */
class PurchaseController extends Controller
{
    private const COLUMNS = ['id', 'reference', 'user_id', 'service_name', 'product_name', 'plan_name', 'network', 'recipient', 'recipient_type',
        'face_value_kobo', 'amount_kobo', 'amount_type', 'status', 'created_at', 'completed_at'];

    public function index(Request $request): View
    {
        $purchases = Purchase::where('user_id', $request->user()->id)->latest('id')->paginate(15, self::COLUMNS);
        Purchase::withMaskedRecipients($purchases->getCollection());

        return view('user.purchases.index', ['purchases' => $purchases]);
    }

    public function show(Request $request, string $reference): View|Response
    {
        $purchase = Purchase::where('user_id', $request->user()->id)->where('reference', $reference)->firstOrFail(self::COLUMNS);
        // Only while pending and made less than the window ago (at exactly the window it stops).
        $recent = $purchase->created_at->gt(now()->subMinutes((int) config('purchases.customer_refresh_window_minutes')));
        $data = [
            'purchase' => $purchase,
            'autoRefreshSeconds' => $purchase->status === PurchaseStatus::Pending && $recent ? (int) config('purchases.customer_refresh_seconds') : null,
            'showResult' => false,
            'resultFields' => null,
        ];
        if (! $purchase->recipient_type->requiresResult()) {
            return view('user.purchases.show', $data); // phone purchases: exactly as before
        }

        Purchase::withMaskedRecipients($purchase->newCollection([$purchase]));
        if ($purchase->status === PurchaseStatus::Successful) {
            // The owner's own result (this purchase was found by its owner above); null when it cannot be read.
            $data['showResult'] = true;
            $data['resultFields'] = PurchaseResult::where('purchase_id', $purchase->id)->first()?->fields();
        }

        return response()->view('user.purchases.show', $data)->header('Cache-Control', 'no-store, private');
    }
}
