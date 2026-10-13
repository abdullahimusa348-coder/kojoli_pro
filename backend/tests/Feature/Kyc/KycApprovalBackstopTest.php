<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycDocument;
use App\Models\KycOpenSubmission;
use App\Models\KycProfile;
use App\Models\KycReview;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycStatus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a approval backstop. Only a BVN or a NIN can be approved in this version. A phone verification and a provider document
 * cannot be completed (no OTP, no document review), so an approval that asks for anything else is refused: by the ledger first, with a
 * message that names the item, and again by the review model, because a review is permanent. A refusal writes nothing, document
 * metadata is never a review, and a requirement is judged by the snapshot taken when the submission was sent.
 */

beforeEach(fn () => kycSeed());

it('refuses an approval that the submission cannot back, and writes nothing', function (string $key, string $label) {
    kycEnable($key);
    $customer = kycCustomer();
    $submission = kycOpen($customer, []);

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, "{$label} cannot be completed in this version, so this submission cannot be approved yet.");

    expect(KycReview::count())->toBe(0)
        ->and(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($submission->id)
        ->and(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::PendingReview)
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(0);
})->with([
    'phone' => ['phone', 'Phone number'],
    'document' => ['document', 'Provider document'],
]);

it('keeps refusing the approval when a document row with valid metadata exists, because metadata is not a review', function () {
    kycEnable('document');
    $submission = kycOpen(kycCustomer(), []);
    (new KycDocument)->forceFill([
        'kyc_submission_id' => $submission->id, 'requirement_key' => 'document', 'storage_name' => strtoupper((string) Str::ulid()).'.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 2048, 'sha256' => str_repeat('c', 64), 'uploaded_at' => now(),
    ])->save();

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, 'Provider document cannot be completed in this version, so this submission cannot be approved yet.');
    expect(KycReview::count())->toBe(0);
});

it('refuses a model write of an approval for a submission that asked for a phone or document verification', function (string $key) {
    kycEnable($key);
    $submission = kycOpen(kycCustomer(), []);

    expect(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
        'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save())
        ->toThrow(LogicException::class, 'A KYC approval in this version needs a submission that asked only for a BVN or a NIN.');
    expect(KycReview::count())->toBe(0);
})->with(['phone', 'document']);

it('still records a model approval for a submission that asked only for a BVN and a NIN', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
        'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();

    expect(KycReview::count())->toBe(1);
});

it('keeps refusing the approval after the requirement is switched off, because the submission was sent under it', function () {
    kycEnable('phone');
    $submission = kycOpen(kycCustomer(), []);
    $requirement = kycRequirement('phone');
    app(SaveKycRequirement::class)->handle($requirement, $requirement->label, $requirement->description, false, [],
        'Switched off after the submission was sent', kycStaff(), SaveKycRequirement::fingerprint($requirement));

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, 'Phone number cannot be completed in this version, so this submission cannot be approved yet.');
    expect(KycReview::count())->toBe(0);
});

it('lets a submission be approved when the requirement was switched on only after it was sent, because it was never asked for', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    kycEnable('phone');

    expect(kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved)->decision)->toBe(KycStatus::Approved);
});
