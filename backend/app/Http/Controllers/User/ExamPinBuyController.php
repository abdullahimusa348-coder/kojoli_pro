<?php

namespace App\Http\Controllers\User;

use App\Exceptions\Purchases\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Purchases\PurchaseService;
use App\Support\MaintenanceMode;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

/**
 * Customer Buy Exam PIN (Phase 11 CP4): choose a plan -> confirm -> buy,
 * through the same PurchaseService as every other purchase. One plan is one
 * PIN: there is no quantity and nothing to enter (no phone, NIN, BVN, email
 * or candidate details). The purchase has no recipient (RecipientType::None)
 * and the provider is sent none.
 * - Only fixed-price plans the customer can buy now are offered
 *   (PurchaseCatalog); with no provider adapter installed nothing is, and
 *   PurchaseService refuses anything that is not purchasable.
 * - The confirmation page carries an encrypted payload bound to the customer,
 *   the service, the plan, the confirmed amount, a one-time token (the
 *   purchase idempotency key, so a repeated submission returns the same
 *   purchase) and its issue time. It can be paid for exactly 10 minutes
 *   (VALID_SECONDS). A payload that is missing, does not decrypt, was altered,
 *   belongs to another customer or service, or has expired fails closed:
 *   nothing is bought. The confirmation response is sent no-store, private.
 * - What the provider delivers (such as the PIN and its serial) is shown only
 *   on the owner's own result page (customer PurchaseController), never here.
 */
class ExamPinBuyController extends Controller
{
    private const SERVICE = 'exam-pin';

    /** A confirmation can be paid for exactly 10 minutes after the confirmation page was shown. */
    private const VALID_SECONDS = 600;

    public function __construct(private PurchaseCatalog $catalog, private PurchaseService $purchases) {}

    public function create(Request $request): View
    {
        $maintenance = MaintenanceMode::active();

        return view('user.buy.exam-pin.create', [
            'maintenance' => $maintenance,
            'label' => PurchaseCatalog::SERVICES[self::SERVICE],
            'plans' => $maintenance ? collect() : $this->catalog->plans($request->user(), self::SERVICE),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
        ]);
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        if (MaintenanceMode::active()) {
            return redirect()->route('buy.exam-pin')->withErrors(['purchase' => MaintenanceMode::MESSAGE]);
        }
        $data = $request->validate(['plan' => ['required', 'integer']], ['plan.required' => 'Choose a plan.']);

        $plan = $this->catalog->find($request->user(), self::SERVICE, (int) $data['plan'])
            ?? throw ValidationException::withMessages(['plan' => 'This plan is not available right now.']);
        $quote = $this->catalog->quote($plan, $request->user());
        if (! $quote->available) {
            throw ValidationException::withMessages(['plan' => $quote->reason ?? 'This plan is not available right now.']);
        }

        return response()->view('user.buy.exam-pin.confirm', [
            'label' => PurchaseCatalog::SERVICES[self::SERVICE],
            'plan' => $plan->loadMissing('product.service'),
            'amountKobo' => $quote->amountKobo,
            'confirmation' => $this->seal($request->user(), $plan->id, $quote->amountKobo, (string) Str::uuid(), now()->getTimestamp()),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): RedirectResponse
    {
        $confirmed = $this->open($request->input('confirmation'), $request->user());
        if ($confirmed !== null && now()->getTimestamp() - $confirmed['issued_at'] >= self::VALID_SECONDS) {
            return redirect()->route('buy.exam-pin')->withErrors(['purchase' => 'This confirmation has expired. Please start again.']);
        }
        $plan = $confirmed === null ? null : Plan::with('product.service')->find($confirmed['plan']);
        if ($confirmed === null || $plan === null || $plan->product?->service?->slug !== self::SERVICE) {
            return redirect()->route('buy.exam-pin')->withErrors(['purchase' => 'This confirmation is no longer valid. Please start again.']);
        }

        try {
            // No recipient of any kind: the engine refuses anything else for Exam PIN.
            $purchase = $this->purchases->purchase($request->user(), $plan, '', null, $confirmed['token'], $confirmed['amount']);
        } catch (PurchaseException $e) {
            return redirect()->route('buy.exam-pin')->withErrors(['purchase' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase->reference);
    }

    /** The confirmed purchase travels only inside this encrypted payload, bound to the customer, service, plan, amount, token and issue time. */
    private function seal(User $user, int $planId, int $amountKobo, string $token, int $issuedAt): string
    {
        return Crypt::encryptString(json_encode([
            'customer' => $user->id, 'service' => self::SERVICE, 'plan' => $planId, 'amount' => $amountKobo, 'token' => $token,
            'issued_at' => $issuedAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The confirmed purchase, or null when the payload is missing, does not
     * decrypt, was altered, or belongs to another customer or service (fail
     * closed). Its issue time is returned for the expiry check; one in the
     * future fails.
     *
     * @return array{plan: int, amount: int, token: string, issued_at: int}|null
     */
    private function open(mixed $sealed, User $user): ?array
    {
        if (! is_string($sealed) || $sealed === '' || strlen($sealed) > 4096) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($sealed), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        if (! is_array($data) || ($data['customer'] ?? null) !== $user->id || ($data['service'] ?? null) !== self::SERVICE
            || ! is_int($data['plan'] ?? null) || ! is_int($data['amount'] ?? null) || $data['amount'] < 1
            || ! is_string($data['token'] ?? null) || ! Str::isUuid($data['token'])
            || ! is_int($data['issued_at'] ?? null) || $data['issued_at'] > now()->getTimestamp()) {
            return null;
        }

        return ['plan' => $data['plan'], 'amount' => $data['amount'], 'token' => $data['token'], 'issued_at' => $data['issued_at']];
    }
}
