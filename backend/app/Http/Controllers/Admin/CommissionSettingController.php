<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Referrals\SaveCommissionSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Referrals\CommissionSettingRequest;
use App\Models\CommissionSetting;
use App\Models\CommissionSettingChange;
use App\Models\Service;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\PricingLimits;
use App\Support\Referrals\QualifyingServices;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Rates & caps tab of the Referral & Commission area (referrals.view): the
 * commission rate and cap of each qualifying service and their permanent
 * change history. Changes need referrals.manage and are possible only for the
 * qualifying services (checked by the route, here and in the action). No
 * setting is created until staff save one; no delete.
 */
class CommissionSettingController extends Controller
{
    public function index(): View
    {
        $services = Service::whereIn('slug', QualifyingServices::SLUGS)->get()
            ->sortBy(fn (Service $service) => array_search($service->slug, QualifyingServices::SLUGS, true))->values();

        return view('admin.referrals.rates.index', [
            'services' => $services,
            'settings' => CommissionSetting::with('updatedBy')->whereIn('service_id', $services->pluck('id'))->get()->keyBy('service_id'),
            'history' => CommissionSettingChange::with(['service', 'changedBy'])->latest('id')->paginate(25, ['*'], 'history')->withQueryString(),
            'maxAmountKobo' => PricingLimits::maxAmountKobo(),
        ]);
    }

    public function edit(Service $service): View
    {
        abort_unless(QualifyingServices::includes($service->slug), 404);

        return view('admin.referrals.rates.edit', [
            'service' => $service,
            'setting' => CommissionSetting::where('service_id', $service->id)->first(),
            'fingerprint' => SaveCommissionSetting::fingerprint($service),
            'maxAmountKobo' => PricingLimits::maxAmountKobo(),
        ]);
    }

    public function update(CommissionSettingRequest $request, Service $service, SaveCommissionSetting $save): RedirectResponse
    {
        abort_unless(QualifyingServices::includes($service->slug), 404);

        $changed = $save->handle($service, $request->rateBps(), $request->capKobo(), $request->validated('reason'),
            $request->user('admin'), $request->validated('fingerprint'));

        return redirect()->route('admin.referrals.rates')->with('status', $changed
            ? "{$service->name} saved: rate ".BasisPoints::label($request->rateBps()).', cap '.Money::format($request->capKobo()).'.'
            : "No changes to save for {$service->name}.");
    }
}
