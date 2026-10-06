<?php

namespace App\Http\Controllers\User;

use App\Exceptions\Purchases\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Purchases\PurchaseService;
use App\Support\MaintenanceMode;
use App\Support\Purchases\RecipientType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

/**
 * Customer Buy NIN / Buy BVN (Phase 11 CP3): choose a plan and enter the
 * number -> confirm -> buy, through the same PurchaseService as every other
 * purchase. A NIN or BVN purchase is a digital service bought from the
 * wallet, not KYC: nothing here touches the customer's own account.
 * - The number is only ever POSTed: never in a URL, never flashed back as old
 *   input (dontFlash), never refilled into a form, never in an error message.
 * - The confirmation page shows it in full once, with the approved consent
 *   sentence, and carries it only inside an encrypted payload bound to the
 *   customer, the service, the plan, the confirmed amount and a one-time
 *   token (the purchase idempotency key, so a repeated submission returns the
 *   same purchase). A payload that does not decrypt or does not match fails
 *   closed: nothing is bought.
 * - The payload holds its issue time and can be paid for exactly 10 minutes
 *   (VALID_SECONDS); after that it is refused before anything is bought.
 * - The confirmation response is sent no-store, private.
 * - Only fixed-price plans the customer can buy now are offered
 *   (PurchaseCatalog); with no provider adapter installed nothing is, and
 *   PurchaseService refuses anything that is not purchasable.
 */
class IdentityBuyController extends Controller
{
    /** A confirmation can be paid for exactly 10 minutes after the confirmation page was shown. */
    private const VALID_SECONDS = 600;

    public function __construct(private PurchaseCatalog $catalog, private PurchaseService $purchases) {}

    public function create(Request $request, string $service): View
    {
        $type = $this->type($service);
        $maintenance = MaintenanceMode::active();

        return view('user.buy.identity.create', [
            'maintenance' => $maintenance,
            'service' => $service,
            'type' => $type,
            'plans' => $maintenance || $this->consent($service) === null ? collect() : $this->catalog->plans($request->user(), $service),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
        ]);
    }

    public function confirm(Request $request, string $service): Response|RedirectResponse
    {
        $type = $this->type($service);
        if (MaintenanceMode::active()) {
            return redirect()->route("buy.{$service}")->withErrors(['purchase' => MaintenanceMode::MESSAGE]);
        }
        $data = $request->validate([
            'plan' => ['required', 'integer'],
            'identity_number' => ['required', 'string', 'max:30'],
        ], [
            'plan.required' => 'Choose a plan.',
            'identity_number.required' => "Enter the {$type->label()}.",
            'identity_number.string' => $type->invalidMessage(),
            'identity_number.max' => $type->invalidMessage(),
        ]);

        $plan = $this->consent($service) === null ? null : $this->catalog->find($request->user(), $service, (int) $data['plan']);
        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'This plan is not available right now.']);
        }
        $number = $type->normalize($data['identity_number'])
            ?? throw ValidationException::withMessages(['identity_number' => $type->invalidMessage()]);
        $quote = $this->catalog->quote($plan, $request->user());
        if (! $quote->available) {
            throw ValidationException::withMessages(['plan' => $quote->reason ?? 'This plan is not available right now.']);
        }

        return $this->confirmation($request, $service, $type, $plan, $quote->amountKobo, (string) Str::uuid(), now()->getTimestamp(), $number);
    }

    public function store(Request $request, string $service): Response|RedirectResponse
    {
        $type = $this->type($service);
        $confirmed = $this->open($request->input('confirmation'), $request->user(), $service, $type);
        if ($confirmed !== null && now()->getTimestamp() - $confirmed['issued_at'] >= self::VALID_SECONDS) {
            return redirect()->route("buy.{$service}")->withErrors(['purchase' => 'This confirmation has expired. Please start again.']);
        }
        $plan = $confirmed === null ? null : Plan::with('product.service')->find($confirmed['plan']);
        if ($confirmed === null || $plan === null || $plan->product?->service?->slug !== $service || $this->consent($service) === null) {
            return redirect()->route("buy.{$service}")->withErrors(['purchase' => 'This confirmation is no longer valid. Please start again.']);
        }
        if (! $request->boolean('consent')) {
            // Shown again with its original issue time: the 10 minutes run from the first confirmation page.
            return $this->confirmation($request, $service, $type, $plan, $confirmed['amount'], $confirmed['token'], $confirmed['issued_at'],
                $confirmed['number'], 'Tick the box to give your consent before you pay.');
        }

        try {
            $purchase = $this->purchases->purchase($request->user(), $plan, $confirmed['number'], null, $confirmed['token'], $confirmed['amount'],
                consented: true);
        } catch (PurchaseException $e) {
            return redirect()->route("buy.{$service}")->withErrors(['purchase' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase->reference);
    }

    /** The confirmation page (the only page that shows the full number), never cached. */
    private function confirmation(Request $request, string $service, RecipientType $type, Plan $plan, int $amountKobo, string $token, int $issuedAt,
        #[\SensitiveParameter] string $number, ?string $consentError = null): Response
    {
        $errors = new ViewErrorBag;
        if ($consentError !== null) {
            $errors->put('default', new MessageBag(['consent' => $consentError]));
        }

        return response()->view('user.buy.identity.confirm', [
            'service' => $service,
            'type' => $type,
            'plan' => $plan->loadMissing('product.service'),
            'amountKobo' => $amountKobo,
            'number' => $number,
            'consent' => $this->consent($service),
            'confirmation' => $this->seal($request->user(), $service, $plan->id, $amountKobo, $token, $issuedAt, $number),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
            'errors' => $errors,
        ], $consentError === null ? 200 : 422)->header('Cache-Control', 'no-store, private');
    }

    /** The number travels to the purchase only inside this encrypted payload, bound to the customer, service, plan, amount, token and issue time. */
    private function seal(User $user, string $service, int $planId, int $amountKobo, string $token, int $issuedAt,
        #[\SensitiveParameter] string $number): string
    {
        return Crypt::encryptString(json_encode([
            'customer' => $user->id, 'service' => $service, 'plan' => $planId, 'amount' => $amountKobo, 'token' => $token,
            'issued_at' => $issuedAt, 'number' => $number,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The confirmed purchase, or null when the payload does not decrypt, was
     * altered, or belongs to another customer or service (fail closed). Its
     * issue time is returned for the expiry check; one in the future fails.
     *
     * @return array{plan: int, amount: int, token: string, issued_at: int, number: string}|null
     */
    private function open(mixed $sealed, User $user, string $service, RecipientType $type): ?array
    {
        if (! is_string($sealed) || $sealed === '' || strlen($sealed) > 4096) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($sealed), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }
        if (! is_array($data) || ($data['customer'] ?? null) !== $user->id || ($data['service'] ?? null) !== $service
            || ! is_int($data['plan'] ?? null) || ! is_int($data['amount'] ?? null) || $data['amount'] < 1
            || ! is_string($data['token'] ?? null) || ! Str::isUuid($data['token'])
            || ! is_int($data['issued_at'] ?? null) || $data['issued_at'] > now()->getTimestamp()
            || ! is_string($data['number'] ?? null) || ! $type->isCanonical($data['number'])) {
            return null;
        }

        return ['plan' => $data['plan'], 'amount' => $data['amount'], 'token' => $data['token'], 'issued_at' => $data['issued_at'],
            'number' => $data['number']];
    }

    private function type(string $service): RecipientType
    {
        $type = RecipientType::forServiceSlug($service);
        abort_unless($type !== null && $type->isIdentity() && in_array($service, PurchaseCatalog::IDENTITY_SERVICES, true), 404);

        return $type;
    }

    /** The approved consent sentence for the service; without one the service cannot be bought. */
    private function consent(string $service): ?string
    {
        $sentence = config("purchases.identity_consent.{$service}");

        return is_string($sentence) && trim($sentence) !== '' ? $sentence : null;
    }
}
