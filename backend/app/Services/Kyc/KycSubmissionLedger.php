<?php

namespace App\Services\Kyc;

use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycProfile;
use App\Models\KycRequirement;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Kyc\KycApprovalGate;
use App\Support\Kyc\KycApprovalRefusal;
use App\Support\Kyc\KycAuditEventType;
use App\Support\Kyc\KycContactFingerprint;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycSelfReview;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * The one write path for KYC submissions and staff decisions (Phase 13 CP2a). It creates a submission with its requirement snapshot,
 * its identity values and its open slot in one transaction, and it records a decision with the open slot's release, the review and the
 * audit event in one transaction. Nothing else writes these rows.
 *
 * Each path takes its locks in a fixed order. Opening takes the customer's users row, then the profile, then the open slot, then reads
 * the requirement rows (shared), and only then writes. A decision takes the submission's row (shared), then the customer's users row,
 * the profile and the open slot, then the reviewer (shared) and, for an approval, the snapshot and identity rows the approval gate
 * reads (shared). Its review is written next, and the insert releases the open slot. Both paths queue on the customer's users row
 * before they touch the profile or the open slot. The database itself refuses a second open submission (the open slot is keyed by the
 * customer) and a second review of one submission (one review per submission). A request that loses a race is refused with a
 * KycException and writes nothing. A deadlock is retried, then refused as busy.
 *
 * Rules enforced here, because the database cannot express them: an active account; an initial submission only while the status is
 * not approved and nothing is open; a correction only after approval, for a BVN or NIN that is on; no submission when no requirement
 * applies; identity values only for the requirements asked, and only in canonical form; a snapshot of the requirements that applied
 * when the customer sent it; decisions only by an active staff member holding kyc.review, never on their own submission (matched on
 * contact details, a best-effort check); a rejection or a request for more information with a reason; an approval only while the phone
 * is unchanged since submission, and only when nothing in the snapshot needs a phone verification or a document that cannot be
 * provided yet.
 */
class KycSubmissionLedger
{
    use DetectsConcurrencyErrors;

    private const ATTEMPTS = 3;

    /**
     * Opens a submission for a customer. A repeated form (the same form key) returns the submission it made before.
     *
     * @param  array<string, mixed>  $identityNumbers  the BVN and/or NIN, keyed by requirement key ('bvn', 'nin')
     */
    public function open(User $customer, KycSubmissionKind $kind, #[\SensitiveParameter] array $identityNumbers, string $formKey): KycSubmission
    {
        $formKey = $this->formKey($formKey);
        $numbers = $this->canonicalNumbers($identityNumbers);

        try {
            return DB::transaction(fn () => $this->openLocked($customer->id, $kind, $numbers, $formKey), self::ATTEMPTS);
        } catch (QueryException $e) {
            if ($this->causedByConcurrencyError($e)) {
                throw KycException::busy();
            }

            throw $e;
        }
    }

    /**
     * Records a staff decision on an open submission. A repeated form returns the decision it made before.
     */
    public function decide(KycSubmission $submission, SystemUser $reviewer, KycStatus $decision, #[\SensitiveParameter] ?string $reason, string $formKey): KycReview
    {
        if (! $decision->isDecision()) {
            throw new InvalidArgumentException('A KYC decision is an approval, a rejection or a request for more information.');
        }
        $formKey = $this->formKey($formKey);
        $reason = $this->reason($decision, $reason);

        try {
            return DB::transaction(fn () => $this->decideLocked($submission->id, $reviewer->id, $decision, $reason, $formKey), self::ATTEMPTS);
        } catch (QueryException $e) {
            if ($this->causedByConcurrencyError($e)) {
                throw KycException::busy();
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, string>  $numbers  canonical values by requirement key
     */
    private function openLocked(int $customerId, KycSubmissionKind $kind, #[\SensitiveParameter] array $numbers, string $formKey): KycSubmission
    {
        $customer = User::whereKey($customerId)->lockForUpdate()->firstOrFail();

        $repeat = KycSubmission::where('form_key', $formKey)->first();
        if ($repeat !== null) {
            if ((int) $repeat->user_id !== $customer->id) {
                throw KycException::formNotCurrent();
            }

            return $repeat;
        }
        if (! $customer->isActive()) {
            throw KycException::customerInactive();
        }

        DB::table('kyc_profiles')->insertOrIgnore([
            'user_id' => $customer->id,
            'status' => KycStatus::NotStarted->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $profile = KycProfile::where('user_id', $customer->id)->lockForUpdate()->firstOrFail();

        if (KycOpenSubmission::whereKey($customer->id)->lockForUpdate()->first() !== null) {
            throw KycException::alreadyOpen();
        }

        if ($kind === KycSubmissionKind::Initial) {
            if ($profile->status === KycStatus::Approved) {
                throw KycException::alreadyApproved();
            }
            if ($profile->status === KycStatus::PendingReview) {
                throw KycException::alreadyOpen();
            }

            $requirements = $this->applicable($customer);
            if ($requirements === []) {
                throw KycException::notRequired();
            }

            $identityKeys = [];
            foreach ($requirements as $requirement) {
                $type = $requirement->requirementType();
                if ($type === KycRequirementType::Phone && ($customer->phone === null || $customer->phone === '')) {
                    throw KycException::phoneMissing();
                }
                if (in_array($type, [KycRequirementType::Bvn, KycRequirementType::Nin], true)) {
                    $identityKeys[] = $type->value;
                    if (! array_key_exists($type->value, $numbers)) {
                        throw KycException::identityMissing($type);
                    }
                }
            }
            foreach (array_keys($numbers) as $key) {
                if (! in_array($key, $identityKeys, true)) {
                    throw KycException::identityNotRequested(KycRequirementType::from($key));
                }
            }
        } else {
            if ($profile->status !== KycStatus::Approved || $numbers === []) {
                throw KycException::correctionNotAllowed();
            }

            $requirements = KycRequirement::query()->whereIn('key', array_keys($numbers))->sharedLock()->get()
                ->filter(fn (KycRequirement $requirement) => $requirement->isRequiredFor($customer->user_type))
                ->values()->all();
            if (count($requirements) !== count($numbers)) {
                throw KycException::correctionNotAllowed();
            }
        }

        $now = now();
        $submission = (new KycSubmission)->forceFill([
            'reference' => 'KYC-'.strtoupper((string) Str::ulid()),
            'user_id' => $customer->id,
            'kind' => $kind->value,
            'user_type' => $customer->user_type->value,
            'form_key' => $formKey,
            'submitted_at' => $now,
        ]);
        $submission->save();

        foreach ($requirements as $requirement) {
            $type = $requirement->requirementType();
            (new KycSubmissionRequirement)->forceFill([
                'kyc_submission_id' => $submission->id,
                'kyc_requirement_id' => $requirement->id,
                'requirement_key' => $requirement->key,
                'requirement_type' => $type->value,
                'label' => $requirement->label,
                'phone_fingerprint' => $type === KycRequirementType::Phone ? KycContactFingerprint::forPhone((string) $customer->phone) : null,
            ])->save();
        }

        foreach ($numbers as $key => $canonical) {
            $type = KycRequirementType::from($key);
            (new KycSubmissionIdentity)->forceFill([
                'kyc_submission_id' => $submission->id,
                'requirement_key' => $key,
                'ciphertext' => $canonical,
                'masked_value' => KycIdentityNumber::mask($canonical),
                'lookup_hash' => KycIdentityNumber::lookupHash($type, $canonical),
            ])->save();
        }

        try {
            (new KycOpenSubmission)->forceFill(['user_id' => $customer->id, 'kyc_submission_id' => $submission->id])->save();
        } catch (UniqueConstraintViolationException) {
            throw KycException::alreadyOpen();
        }

        // A correction leaves the customer's status alone: the approval it corrects still stands until the correction is decided.
        if ($kind === KycSubmissionKind::Initial) {
            $profile->forceFill(['status' => KycStatus::PendingReview->value, 'status_changed_at' => $now])->save();
        }

        $this->audit(KycAuditEventType::SubmissionOpened, $customer, $submission, null,
            ['kind' => $kind->value, 'requirement_count' => count($requirements)]);

        return $submission;
    }

    private function decideLocked(int $submissionId, int $reviewerId, KycStatus $decision, #[\SensitiveParameter] ?string $reason, string $formKey): KycReview
    {
        // A locking read, not a plain one: a plain read would fix this transaction's snapshot before the customer lock, so a repeated
        // form that waited for a concurrent decision would not see that decision's review.
        $submission = KycSubmission::whereKey($submissionId)->sharedLock()->firstOrFail();
        $customer = User::whereKey($submission->user_id)->lockForUpdate()->firstOrFail();

        $repeat = KycReview::where('form_key', $formKey)->first();
        if ($repeat !== null) {
            if ((int) $repeat->kyc_submission_id !== $submission->id) {
                throw KycException::formNotCurrent();
            }

            return $repeat;
        }

        $profile = KycProfile::where('user_id', $customer->id)->lockForUpdate()->firstOrFail();
        $slot = KycOpenSubmission::whereKey($customer->id)->lockForUpdate()->first();
        if ($slot === null || (int) $slot->kyc_submission_id !== $submission->id) {
            throw KycException::alreadyDecided();
        }

        $reviewer = SystemUser::whereKey($reviewerId)->sharedLock()->first();
        if ($reviewer === null || ! $reviewer->isActive() || ! $reviewer->can(SystemPermission::KycReview->value)) {
            throw KycException::reviewerNotAllowed();
        }
        if (KycSelfReview::sameContact($customer, $reviewer)) {
            throw KycException::selfReview();
        }

        if ($decision === KycStatus::Approved) {
            $this->assertApprovable($submission, $customer);
        }

        try {
            $review = (new KycReview)->forceFill([
                'kyc_submission_id' => $submission->id,
                'decision' => $decision->value,
                'reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'decided_at' => now(),
                'form_key' => $formKey,
            ]);
            $review->save();
        } catch (UniqueConstraintViolationException) {
            throw KycException::alreadyDecided();
        }

        // The release trigger removed the open slot in the same statement as the review. Confirm that it did: if the slot is still there,
        // the decision is rolled back rather than leaving the customer holding a slot for a decided submission. The ledger never deletes
        // the slot itself. Production grants should give the application no DELETE on the slot table, so only the trigger can free it.
        if (KycOpenSubmission::whereKey($customer->id)->exists()) {
            throw new LogicException('A decision must release its open slot, and the release trigger did not. Nothing was recorded.');
        }

        // A correction's decision leaves the status alone: an approved status stays approved; the correction's own outcome is its review.
        if ($submission->kind === KycSubmissionKind::Initial) {
            $profile->forceFill(['status' => $decision->value, 'status_changed_at' => $review->decided_at])->save();
        }

        $this->audit(KycAuditEventType::ReviewDecided, $customer, $submission, $reviewer, ['decision' => $decision->value]);

        return $review;
    }

    /**
     * Before an approval, every rule of the approval gate must hold (see KycApprovalGate): the submission asked for something, the phone
     * is unchanged, every requirement is a BVN or a NIN, and each has its identity value. A refusal names the item and writes nothing.
     */
    private function assertApprovable(KycSubmission $submission, User $customer): void
    {
        $refusal = KycApprovalGate::refusal($submission, $customer);
        if ($refusal === null) {
            return;
        }

        throw match ($refusal->rule) {
            KycApprovalRefusal::NO_REQUIREMENTS => KycException::noRequirementToApprove(),
            KycApprovalRefusal::STALE_PHONE => KycException::stale(),
            KycApprovalRefusal::UNSUPPORTED => KycException::notApprovable($refusal->type),
            KycApprovalRefusal::IDENTITY_MISSING => KycException::identityRecordMissing($refusal->type),
            default => KycException::identityRecordMismatch($refusal->type),
        };
    }

    /** The requirements that apply to a customer now: switched on, for the customer's type, in the usual order. */
    private function applicable(User $customer): array
    {
        return KycRequirement::query()->orderBy('position')->orderBy('id')->sharedLock()->get()
            ->filter(fn (KycRequirement $requirement) => $requirement->isRequiredFor($customer->user_type))
            ->values()->all();
    }

    /**
     * The reason the customer will see: optional for an approval (up to 500 characters), required for a rejection or a request for more
     * information (10 to 500 characters with a letter). A BVN or NIN is never accepted in it, and no refusal repeats the input.
     */
    private function reason(KycStatus $decision, #[\SensitiveParameter] ?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && KycIdentityNumber::containsIdentityNumber($reason)) {
            throw KycException::reasonHasIdentityNumber();
        }

        if ($decision === KycStatus::Approved) {
            if ($reason === null || $reason === '') {
                return null;
            }
            if (mb_strlen($reason) > 500) {
                throw KycException::approvalReasonTooLong();
            }

            return $reason;
        }

        if ($reason === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || preg_match('/\p{L}/u', $reason) !== 1) {
            throw KycException::reasonRequired();
        }

        return $reason;
    }

    private function formKey(string $formKey): string
    {
        if (strlen($formKey) !== 36 || ! Str::isUuid($formKey)) {
            throw new InvalidArgumentException('A KYC form key is a UUID.');
        }

        return $formKey;
    }

    /**
     * @param  array<string, mixed>  $identityNumbers
     * @return array<string, string>
     */
    private function canonicalNumbers(#[\SensitiveParameter] array $identityNumbers): array
    {
        $numbers = [];
        foreach ($identityNumbers as $key => $number) {
            $type = KycRequirementType::tryFrom((string) $key);
            if (! in_array($type, [KycRequirementType::Bvn, KycRequirementType::Nin], true)) {
                throw new InvalidArgumentException('Only a BVN or a NIN is kept as a KYC identity value.');
            }
            $canonical = KycIdentityNumber::normalize(is_string($number) ? $number : null);
            if ($canonical === null) {
                throw KycException::invalidIdentity($type);
            }
            $numbers[$type->value] = $canonical;
        }

        return $numbers;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(KycAuditEventType $event, User $customer, KycSubmission $submission, ?SystemUser $actor, array $metadata): void
    {
        (new KycAuditEvent)->forceFill([
            'event' => $event->value,
            'user_id' => $customer->id,
            'kyc_submission_id' => $submission->id,
            'kyc_document_id' => null,
            'system_user_id' => $actor?->id,
            'metadata' => $metadata,
        ])->save();
    }
}
