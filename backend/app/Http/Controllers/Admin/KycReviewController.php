<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Kyc\DecideKycSubmission;
use App\Exceptions\Kyc\KycException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Kyc\KycDecisionRequest;
use App\Models\KycOpenSubmission;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Support\Enums\SystemPermission;
use App\Support\Kyc\KycContactFingerprint;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The staff KYC review queue (Phase 13 CP2b-1): the open submissions, oldest first, and one submission with its requirements, its masked
 * identity values, whether its phone number still matches, and its decision form. kyc.view opens both pages. kyc.review is needed to
 * decide, and a decision goes through DecideKycSubmission, which hands it to KycSubmissionLedger::decide(). A customer's name and email
 * show only to staff with customers.view; everyone else sees Customer #<id>. A refusal comes back with its own message, and the decision
 * reason is never flashed, so nothing typed into a decision reaches the session.
 */
class KycReviewController extends Controller
{
    public function index(Request $request): View
    {
        $submissions = KycSubmission::query()
            ->whereIn('id', KycOpenSubmission::query()->select('kyc_submission_id'))
            ->with(['user', 'requirements'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.kyc.review.index', [
            'submissions' => $submissions,
            'phoneMatches' => $submissions->getCollection()->mapWithKeys(fn (KycSubmission $submission) => [$submission->id => $this->phoneMatch($submission)]),
            'canViewCustomers' => $request->user('admin')->can(SystemPermission::CustomersView->value),
        ]);
    }

    public function show(Request $request, KycSubmission $submission): Response
    {
        $staff = $request->user('admin');
        $submission->load(['user', 'requirements', 'identities', 'review']);
        $canDecide = $staff->can(SystemPermission::KycReview->value);

        // The page carries a one-time form key, so it is never stored by the browser or a proxy (as the commission page is).
        return response()->view('admin.kyc.review.show', [
            'submission' => $submission,
            'open' => KycOpenSubmission::whereKey($submission->user_id)->where('kyc_submission_id', $submission->id)->exists(),
            // The masks are prepared here, so the view prints display text and never reads an identity record itself.
            'identityMasks' => $submission->identities->mapWithKeys(fn (KycSubmissionIdentity $identity) => [$identity->requirement_key => $identity->masked_value]),
            'phoneMatch' => $this->phoneMatch($submission),
            'formKey' => $canDecide ? (string) Str::uuid() : null,
            'decisions' => KycDecisionRequest::DECISIONS,
            'canDecide' => $canDecide,
            'canViewCustomers' => $staff->can(SystemPermission::CustomersView->value),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function decide(KycDecisionRequest $request, KycSubmission $submission, DecideKycSubmission $decide): RedirectResponse
    {
        try {
            $review = $decide->handle($submission, $request->user('admin'), $request->decision(), $request->reason(), $request->formKey());
        } catch (KycException $refusal) {
            return redirect()->route('admin.kyc.submissions.show', $submission)->withErrors(['refusal' => $refusal->getMessage()]);
        }

        return redirect()->route('admin.kyc.index')->with('status', $this->recorded($review->decision, $submission->reference));
    }

    /** 'matches' or 'changed' for the phone number the submission was sent with, or null when no phone number was asked for. */
    private function phoneMatch(KycSubmission $submission): ?string
    {
        $phone = $submission->requirements->first(fn (KycSubmissionRequirement $row) => $row->requirement_type === KycRequirementType::Phone);
        if ($phone === null) {
            return null;
        }

        return KycContactFingerprint::matches((string) $phone->phone_fingerprint, (string) $submission->user->phone) ? 'matches' : 'changed';
    }

    private function recorded(KycStatus $decision, string $reference): string
    {
        return match ($decision) {
            KycStatus::Approved => "{$reference} approved.",
            KycStatus::Rejected => "{$reference} rejected.",
            default => "More information requested for {$reference}.",
        };
    }
}
