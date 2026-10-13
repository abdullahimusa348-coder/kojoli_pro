<?php

namespace App\Services\Kyc;

use App\Models\KycProfile;
use App\Models\KycRequirement;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\User;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a customer may see of their own KYC (Phase 13 CP2b-2a): the requirements that apply to them, their status and their own
 * submissions. Read only: nothing here writes, and every lookup is scoped to the customer, so another customer's record is never found.
 * Only the columns a customer page shows are loaded. No ciphertext, lookup hash, form key, phone fingerprint or review reason is read,
 * so no page built from this can show a full number or a reason. Which requirements apply is decided by the same rule the ledger uses.
 */
final class KycCustomerOverview
{
    /** The requirements that apply to the customer now: switched on, for the customer's type, in the usual order. */
    public function requirements(User $customer): Collection
    {
        return KycRequirement::query()->orderBy('position')->orderBy('id')->get()
            ->filter(fn (KycRequirement $requirement) => $requirement->isRequiredFor($customer->user_type))
            ->values();
    }

    /** The customer's status. A customer with no status row has not started. */
    public function status(User $customer): KycStatus
    {
        return KycProfile::query()->where('user_id', $customer->id)->first(['status'])?->status ?? KycStatus::NotStarted;
    }

    /** The customer's own submissions, newest first, ten to a page, each as a summary. */
    public function submissions(User $customer): LengthAwarePaginator
    {
        $page = $this->withDisplayColumns(KycSubmission::query()->where('user_id', $customer->id)->latest('id'))->paginate(10);

        return $page->through(fn (KycSubmission $submission) => $this->summary($submission));
    }

    /** One of the customer's own submissions by its reference, or null. Another customer's reference finds nothing. */
    public function submission(User $customer, string $reference): ?KycSubmission
    {
        return $this->withDisplayColumns(KycSubmission::query()->where('user_id', $customer->id)->where('reference', $reference))
            ->first();
    }

    /** A summary: the reference, when it was sent, and its status (the decision on it, or pending review while it waits). */
    public function summary(KycSubmission $submission): array
    {
        return [
            'reference' => $submission->reference,
            'submitted_at' => $submission->submitted_at,
            'status' => $submission->review?->decision ?? KycStatus::PendingReview,
        ];
    }

    /** A submission's page: its summary, the requirements it asked for, and each number it carried, as a mask only. */
    public function detail(KycSubmission $submission): array
    {
        return $this->summary($submission) + [
            'requirements' => $submission->requirements->map(fn (KycSubmissionRequirement $row) => $row->label)->values()->all(),
            'identities' => $submission->identities->map(fn (KycSubmissionIdentity $identity) => [
                'label' => KycRequirementType::from($identity->requirement_key)->label(),
                'mask' => $identity->masked_value,
            ])->values()->all(),
        ];
    }

    /** Loads only the columns a customer page shows, with each submission's requirements, masks and review decision. */
    private function withDisplayColumns(Builder $query): Builder
    {
        return $query->select(['id', 'reference', 'submitted_at'])->with([
            'requirements' => fn ($row) => $row->select(['id', 'kyc_submission_id', 'label'])->orderBy('id'),
            'identities' => fn ($row) => $row->select(['id', 'kyc_submission_id', 'requirement_key', 'masked_value'])->orderBy('id'),
            'review' => fn ($row) => $row->select(['id', 'kyc_submission_id', 'decision']),
        ]);
    }
}
