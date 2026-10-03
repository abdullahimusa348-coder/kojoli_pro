<?php

namespace App\Services\Purchases;

use App\Models\Plan;
use App\Models\User;
use App\Services\Pricing\PriceQuote;
use App\Services\Pricing\PriceResolver;
use App\Services\Providers\ProviderAdapterRegistry;
use Illuminate\Support\Collection;

/**
 * Read-only view of what a customer can buy right now (Phase 10: Data and
 * Airtime). A plan is purchasable only when its service, product and plan
 * are active, the customer's type has an active price within the safety
 * ceiling (PriceResolver), and at least one executable provider route exists
 * (installed adapter). With no provider adapter installed nothing is
 * purchasable. Prices shown come from PriceResolver, the same resolver
 * PurchaseService uses when it creates the purchase.
 */
class PurchaseCatalog
{
    /** Customer-facing services built in this step, by catalog slug. */
    public const SERVICES = ['data' => 'Data', 'airtime' => 'Airtime'];

    public function __construct(private PriceResolver $prices, private ProviderAdapterRegistry $registry) {}

    /**
     * Purchasable plans of the service for this customer, with their quote
     * (fixed price, or for variable plans a probe at the minimum amount).
     *
     * @return Collection<int, array{plan: Plan, quote: PriceQuote}>
     */
    public function plans(User $user, string $serviceSlug): Collection
    {
        // Memoized per HTTP request (the navigation asks on every page).
        $key = 'purchase-catalog:'.$user->id.'|'.$serviceSlug;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        if (! array_key_exists($serviceSlug, self::SERVICES) || $this->registry->adapters() === []) {
            request()->attributes->set($key, collect());

            return collect();
        }

        $plans = Plan::query()
            ->with(['product.service.category', 'prices'])
            ->whereHas('product.service', fn ($q) => $q->where('slug', $serviceSlug))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan) => $plan->isAvailable())
            ->map(fn (Plan $plan) => ['plan' => $plan, 'quote' => $this->quote($plan, $user, $plan->isVariable() ? $plan->min_amount_kobo : null)])
            ->filter(fn (array $row) => $row['quote']->available && $this->registry->executableFor($row['plan']) !== [])
            ->values();
        request()->attributes->set($key, $plans);

        return $plans;
    }

    public function find(User $user, string $serviceSlug, int $planId): ?Plan
    {
        return $this->plans($user, $serviceSlug)->first(fn (array $row) => $row['plan']->id === $planId)['plan'] ?? null;
    }

    /** The customer's price for the plan (and face value for variable plans), straight from PriceResolver. */
    public function quote(Plan $plan, User $user, ?int $faceValueKobo = null): PriceQuote
    {
        return $this->prices->quoteFor($plan, $user, $faceValueKobo);
    }

    public function hasAnything(User $user): bool
    {
        foreach (array_keys(self::SERVICES) as $slug) {
            if ($this->plans($user, $slug)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }
}
