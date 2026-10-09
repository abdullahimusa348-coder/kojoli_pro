<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Kyc\KycRequirementRequest;
use App\Models\KycRequirement;
use App\Models\KycRequirementChange;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * KYC requirements (Phase 13 CP1): the four requirements, whether each is on and
 * which customer types it applies to, and the permanent change history. The page
 * needs kyc.view; changing a requirement needs kyc.requirements (checked by the
 * routes, and again in the action). No requirement is created or deleted here,
 * and no requirement is enforced in this version.
 */
class KycRequirementController extends Controller
{
    public function index(): View
    {
        return view('admin.kyc.requirements.index', [
            'requirements' => KycRequirement::with('updatedBy')->orderBy('position')->get(),
            'history' => KycRequirementChange::with(['requirement', 'changedBy'])->latest('id')->paginate(25, ['*'], 'history')->withQueryString(),
            'canEdit' => auth('admin')->user()->can(SystemPermission::KycRequirements->value),
        ]);
    }

    public function edit(KycRequirement $requirement): View
    {
        return view('admin.kyc.requirements.edit', [
            'requirement' => $requirement,
            'fingerprint' => SaveKycRequirement::fingerprint($requirement),
            'customerTypes' => UserType::cases(),
        ]);
    }

    public function update(KycRequirementRequest $request, KycRequirement $requirement, SaveKycRequirement $save): RedirectResponse
    {
        $changed = $save->handle($requirement, $request->label(), $request->description(), $request->enabled(), $request->customerTypes(),
            $request->validated('reason'), $request->user('admin'), $request->validated('fingerprint'));

        return redirect()->route('admin.kyc.requirements')->with('status', $changed
            ? "{$request->label()} saved."
            : "No changes to save for {$request->label()}.");
    }
}
