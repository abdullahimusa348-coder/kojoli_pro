<?php

namespace App\Http\Controllers\User;

use App\Exceptions\Purchases\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Purchases\PurchaseCatalog;
use App\Services\Purchases\PurchaseService;
use App\Support\Phone\NigerianPhone;
use App\Support\Pricing\KoboAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Customer Buy Data / Buy Airtime (Phase 10). Choose -> confirm -> buy.
 * Only purchasable plans are offered (PurchaseCatalog); with no provider
 * adapter installed the pages say "Not available right now" and nothing can
 * be bought. The price shown comes from PriceResolver and is re-resolved by
 * PurchaseService when buying: client-sent prices, customer ids or statuses
 * are never used. Each confirmation carries a one-time token (the purchase
 * idempotency key), so repeated submissions cannot buy or debit twice.
 */
class BuyController extends Controller
{
    public function __construct(private PurchaseCatalog $catalog, private PurchaseService $purchases) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('user.buy.index', [
            'services' => collect(PurchaseCatalog::SERVICES)->map(fn ($label, $slug) => [
                'slug' => $slug, 'label' => $label, 'available' => $this->catalog->plans($user, $slug)->isNotEmpty(),
            ])->values(),
        ]);
    }

    public function create(Request $request, string $service): View
    {
        $plans = $this->catalog->plans($request->user(), $service);
        $networks = $plans->map(fn ($row) => $row['plan']->product->network)->filter()->unique(fn ($n) => $n->value)->values();
        $network = $networks->first(fn ($n) => $n->value === $request->query('network')) ?? $networks->first();

        return view('user.buy.create', [
            'service' => $service,
            'label' => PurchaseCatalog::SERVICES[$service],
            'networks' => $networks,
            'network' => $network,
            'plans' => $plans->filter(fn ($row) => $network === null || $row['plan']->product->network === $network)->values(),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
        ]);
    }

    public function confirm(Request $request, string $service): View|RedirectResponse
    {
        [$plan, $phone, $face] = $this->validated($request, $service);
        $quote = $this->catalog->quote($plan, $request->user(), $face);
        if (! $quote->available) {
            throw ValidationException::withMessages([$plan->isVariable() ? 'amount' : 'plan' => $quote->reason ?? 'This plan is not available right now.']);
        }

        return view('user.buy.confirm', [
            'service' => $service,
            'plan' => $plan,
            'phone' => $phone,
            'quote' => $quote,
            'token' => (string) Str::uuid(),
            'balanceKobo' => $request->user()->mainWallet()?->balance_kobo ?? 0,
        ]);
    }

    public function store(Request $request, string $service): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'integer'],
            'phone' => ['required', 'string', 'max:30'],
            'face_value_kobo' => ['nullable', 'integer', 'min:1'],
            'confirmed_amount_kobo' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'uuid'],
        ]);
        $plan = Plan::with('product.service')->find($data['plan']);
        if ($plan === null || $plan->product?->service?->slug !== $service) {
            return redirect()->route('buy.service', $service)->withErrors(['plan' => 'Choose an available plan.']);
        }

        try {
            $purchase = $this->purchases->purchase($request->user(), $plan, $data['phone'], $plan->isVariable() ? ($data['face_value_kobo'] ?? null) : null,
                $data['token'], (int) $data['confirmed_amount_kobo']);
        } catch (PurchaseException $e) {
            return redirect()->route('buy.service', [$service, 'network' => $plan->product->network?->value])->withErrors(['purchase' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase->reference);
    }

    /** @return array{0: Plan, 1: string, 2: ?int} the purchasable plan, canonical phone and face value (variable plans) */
    private function validated(Request $request, string $service): array
    {
        $data = $request->validate([
            'plan' => ['required', 'integer'],
            'phone' => ['required', 'string', 'max:30'],
            'amount' => ['nullable', 'string', 'max:20'],
        ], ['plan.required' => 'Choose a plan.', 'phone.required' => 'Enter the phone number to top up.']);

        $plan = $this->catalog->find($request->user(), $service, (int) $data['plan'])
            ?? throw ValidationException::withMessages(['plan' => 'This plan is not available right now.']);
        $phone = NigerianPhone::normalize($data['phone'])
            ?? throw ValidationException::withMessages(['phone' => 'Enter a valid Nigerian phone number, e.g. 08012345678 or +2348012345678.']);

        $face = null;
        if ($plan->isVariable()) {
            $face = KoboAmount::parse($data['amount'] ?? null)
                ?? throw ValidationException::withMessages(['amount' => 'Enter a valid amount in naira, e.g. 500 or 500.50.']);
        }

        return [$plan, $phone, $face];
    }
}
