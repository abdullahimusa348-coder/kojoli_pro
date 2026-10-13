<?php

namespace App\Actions\Admin\Kyc;

use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\SystemUser;
use App\Services\Kyc\KycSubmissionLedger;
use App\Support\Enums\SystemPermission;
use App\Support\Kyc\KycStatus;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Records a staff decision from the KYC review queue (Phase 13 CP2b-1). The route checks kyc.review, and this action checks it again
 * (active staff holding kyc.view and kyc.review). The decision itself is recorded only by KycSubmissionLedger::decide(), which checks the
 * reviewer again, the self-review rule, the approval gate and the form key, and refuses with a KycException that names the item it
 * concerns and writes nothing. Nothing else records a decision.
 */
class DecideKycSubmission
{
    public function __construct(private KycSubmissionLedger $ledger) {}

    public function handle(KycSubmission $submission, SystemUser $reviewer, KycStatus $decision, ?string $reason, string $formKey): KycReview
    {
        if (! $reviewer->isActive() || ! $reviewer->can(SystemPermission::KycView->value) || ! $reviewer->can(SystemPermission::KycReview->value)) {
            throw new AuthorizationException('You are not allowed to decide KYC submissions.');
        }

        return $this->ledger->decide($submission, $reviewer, $decision, $reason, $formKey);
    }
}
