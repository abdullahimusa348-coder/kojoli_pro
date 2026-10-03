<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Pricing\SavePlanPrices;
use App\Actions\Admin\Pricing\SetPlanPriceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\PlanPricesRequest;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\Pricing\PriceComparison;
use App\Services\Pricing\PriceResolver;
use App\Support\Enums\UserType;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer selling prices of one plan (Services area). Viewing needs
 * services.view + pricing.view; changes also need pricing.update.
 * No cost/provider prices, purchasing or customer-facing pages.
 */
class PlanPriceController extends Controller
{
    public function show(Request $request, Plan $plan, PriceResolver $resolver): View
    {
        $plan->load('product.service.category', 'prices.updatedBy');

        $preview = $request->validate([
            'preview_type' => ['nullable', Rule::enum(UserType::class)],
            'preview_amount' => ['nullable', 'string', 'max:25'],
        ]);
        $quote = null;
        $previewError = null;
        if (isset($preview['preview_type'])) {
            $face = null;
            if ($plan->isVariable()) {
                $face = isset($preview['preview_amount']) ? KoboAmount::parse($preview['preview_amount']) : null;
                if (isset($preview['preview_amount']) && $face === null) {
                    $previewError = 'Enter an amount in naira, e.g. 1,000.';
                }
            }
            if ($previewError === null) {
                $quote = $resolver->quote($plan, UserType::from($preview['preview_type']), $face);
            }
        }

        return view('admin.services.pricing.show', [
            'plan' => $plan,
            'prices' => $plan->prices->keyBy(fn (PlanPrice $p) => $p->user_type->value),
            'types' => UserType::cases(),
            'warnings' => PriceComparison::warnings($plan->prices),
            'history' => $plan->priceChanges()->with('changedBy')->latest('id')->limit(25)->get(),
            'preview' => $preview,
            'quote' => $quote,
            'previewError' => $previewError,
        ]);
    }

    public function edit(Plan $plan): View
    {
        $plan->load('product.service', 'prices');

        return view('admin.services.pricing.edit', [
            'plan' => $plan,
            'prices' => $plan->prices->keyBy(fn (PlanPrice $p) => $p->user_type->value),
            'types' => UserType::cases(),
            'warnings' => PriceComparison::warnings($plan->prices),
            'fingerprint' => SavePlanPrices::fingerprint($plan),
            'maxAmountKobo' => PricingLimits::maxAmountKobo(),
        ]);
    }

    public function update(PlanPricesRequest $request, Plan $plan, SavePlanPrices $save): RedirectResponse
    {
        $changed = $save->handle($plan, $request->priceValues(), $request->user('admin'), $request->validated('fingerprint'));

        return redirect()->route('admin.services.plans.prices', $plan)
            ->with('status', $changed === 0 ? 'No price changes to save.' : "Prices saved ({$changed} ".($changed === 1 ? 'change' : 'changes').').');
    }

    public function updateStatus(Request $request, Plan $plan, string $userType, SetPlanPriceStatus $set): RedirectResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $type = UserType::from($userType);
        $price = $plan->prices()->where('user_type', $type->value)->firstOrFail();

        $set->handle($price, $active, $request->user('admin'));

        return back()->with('status', $active
            ? "{$type->label()} price enabled."
            : "{$type->label()} price disabled. This plan is unavailable to {$type->label()} customers until it is enabled again.");
    }
}
