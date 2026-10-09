<?php

namespace App\Http\Controllers\User;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\GatewayRegistry;
use App\Services\Payments\PaymentService;
use App\Support\Payments\PaymentLimits;
use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use App\Support\Pricing\KoboAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Customer wallet funding through an active, configured payment gateway.
 * The customer is sent to the gateway's own checkout (adapter-declared
 * https host only). Coming back proves nothing: the status page asks the
 * gateway server-side, and the wallet is credited only when the gateway
 * confirms the exact amount. Customers only ever see their own payments.
 */
class FundWalletController extends Controller
{
    public function __construct(private GatewayRegistry $registry, private PaymentService $payments) {}

    public function create(Request $request): View
    {
        return view('user.fund', [
            'gateways' => $this->registry->usableForFunding(),
            'minKobo' => PaymentLimits::minFundingKobo(),
            'maxKobo' => PaymentLimits::maxFundingKobo(),
            'token' => (string) Str::uuid(),
            'payments' => Payment::where('user_id', $request->user()->id)->latest('id')
                ->paginate(10, ['id', 'reference', 'amount_kobo', 'status', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gateways = $this->registry->usableForFunding();
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'gateway' => ['required', 'integer', Rule::in($gateways->pluck('id')->all())],
            'idempotency_key' => ['required', 'uuid'],
        ], ['gateway.in' => 'This payment method is not available right now.']);

        $amount = KoboAmount::parse($data['amount']);
        if ($amount === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid naira amount, e.g. 1000 or 1000.50.']);
        }

        try {
            $payment = $this->payments->create($request->user(), $amount, $gateways->firstWhere('id', (int) $data['gateway']), $data['idempotency_key']);
        } catch (PaymentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $payment = $this->payments->initialize($payment, route('wallet.fund.show', $payment->reference));
        if ($payment->status === PaymentStatus::Pending && $payment->checkout_url !== null) {
            return redirect()->away($payment->checkout_url);
        }

        return redirect()->route('wallet.fund.show', $payment->reference);
    }

    public function show(Request $request, string $reference): View
    {
        $payment = Payment::where('user_id', $request->user()->id)->where('reference', $reference)->firstOrFail();

        $checkFailed = false;
        if ($payment->status === PaymentStatus::Pending) {
            try {
                $payment = $this->payments->verifyAndFinalize($payment, PaymentSource::Return);
            } catch (GatewayException) {
                $checkFailed = true; // still pending; reconciliation keeps checking
            }
        }

        return view('user.fund-status', ['payment' => $payment, 'checkFailed' => $checkFailed]);
    }
}
