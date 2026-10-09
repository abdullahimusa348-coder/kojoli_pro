<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Payments\ClosePaymentReview;
use App\Actions\Admin\Payments\RecheckPayment;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Payments\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Wallet-funding payments (payments.view). Recheck and closing a review need
 * payments.manage. There is no "mark paid" and no refund: a payment is only
 * credited after the gateway confirms it server-side.
 */
class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'gateway' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $payments = Payment::with('user', 'gateway')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('gateway_reference', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['gateway'] ?? null, fn ($query, $gateway) => $query->where('payment_gateway_id', $gateway))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'filters' => $filters,
            'statuses' => PaymentStatus::cases(),
            'gateways' => PaymentGateway::orderBy('priority')->get(['id', 'name']),
            'reviewCount' => Payment::where('status', PaymentStatus::Review->value)->count(),
        ]);
    }

    public function show(Payment $payment): View
    {
        $payment->load('user', 'gateway', 'walletTransaction', 'statusChanges.changedBy', 'webhooks');

        return view('admin.payments.show', ['payment' => $payment]);
    }

    public function recheck(Request $request, Payment $payment, RecheckPayment $recheck): RedirectResponse
    {
        $before = $payment->status;
        try {
            $after = $recheck->handle($payment, $request->user('admin'));
        } catch (GatewayException $e) {
            return back()->withErrors(['recheck' => $e->getMessage().' The payment was not changed.']);
        }

        return back()->with('status', $after->status === $before
            ? 'Checked with the gateway: no change ('.$after->status->label().').'
            : 'Checked with the gateway: the payment is now '.$after->status->label().'.');
    }

    public function closeReview(Request $request, Payment $payment, ClosePaymentReview $close): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:250']])['note'];
        try {
            $close->handle($payment, $note, $request->user('admin'));
        } catch (PaymentException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', 'Review closed: the payment is marked failed and nothing was credited.');
    }
}
