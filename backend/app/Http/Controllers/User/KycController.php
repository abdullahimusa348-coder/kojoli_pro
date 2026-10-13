<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\KycCustomerOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in customer's own verification status, the requirements that apply to them and their own submissions (Phase 13
 * CP2b-2a). Read only: there is no form and no route that accepts a write, because sending a submission waits for its consent and
 * submission forms to be approved. Only the customer's own records are read, so another customer's reference is a 404. Both pages
 * are sent no-store, private.
 */
class KycController extends Controller
{
    public function index(Request $request, KycCustomerOverview $overview): Response
    {
        /** @var User $customer */
        $customer = $request->user();

        return response()->view('user.kyc.index', [
            'status' => $overview->status($customer),
            'requirements' => $overview->requirements($customer),
            'submissions' => $overview->submissions($customer),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, KycCustomerOverview $overview, string $reference): Response
    {
        /** @var User $customer */
        $customer = $request->user();
        $submission = $overview->submission($customer, $reference) ?? abort(404);

        return response()->view('user.kyc.show', ['submission' => $overview->detail($submission)])
            ->header('Cache-Control', 'no-store, private');
    }
}
