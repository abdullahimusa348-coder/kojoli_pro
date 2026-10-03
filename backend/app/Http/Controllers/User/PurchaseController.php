<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in customer's own purchases: history and result pages. Only the
 * customer's own records are queried; another customer's reference is a 404.
 * No provider, cost, margin or internal error details are shown.
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

        return view('user.purchases.show', ['purchase' => $purchase]);
    }
}
