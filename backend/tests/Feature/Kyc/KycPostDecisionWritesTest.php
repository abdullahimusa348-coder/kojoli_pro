<?php

use App\Models\KycDocument;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: a submission's requirements, identity values and documents are written when it is sent. Once it has a decision, the
 * models refuse any new one, so the values an approval accepted cannot change. Before a decision, and for a later correction, the same
 * writes work as designed. Synthetic identity numbers only.
 */

beforeEach(fn () => kycSeed());

/** The attributes of a document row for a submission, with a valid metadata record. */
function kycPostDecisionDocument(int $submissionId): array
{
    return ['kyc_submission_id' => $submissionId, 'requirement_key' => 'document', 'storage_name' => strtoupper((string) Str::ulid()).'.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 2048, 'sha256' => str_repeat('d', 64), 'uploaded_at' => now()];
}

/** The attributes of an identity row for a submission, for a number the submission did not send. */
function kycPostDecisionIdentity(int $submissionId, string $number): array
{
    return ['kyc_submission_id' => $submissionId, 'requirement_key' => 'bvn', 'ciphertext' => $number,
        'masked_value' => KycIdentityNumber::mask($number), 'lookup_hash' => KycIdentityNumber::lookupHash(KycRequirementType::Bvn, $number)];
}

it('writes a document row before the decision, as designed', function () {
    kycEnable('document');
    $submission = kycOpen(kycCustomer(), []);

    (new KycDocument)->forceFill(kycPostDecisionDocument($submission->id))->save();

    expect(KycDocument::where('kyc_submission_id', $submission->id)->count())->toBe(1);
});

it('refuses a new requirement snapshot after a decision', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');
    kycEnable('document');
    $document = kycRequirement('document');

    expect(fn () => (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id, 'kyc_requirement_id' => $document->id,
        'requirement_key' => 'document', 'requirement_type' => 'document', 'label' => $document->label, 'phone_fingerprint' => null])->save())
        ->toThrow(LogicException::class, 'A KYC submission takes no new requirement once it has a decision.');
    expect(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->count())->toBe(1);
});

it('refuses a new identity value after a decision', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    expect(fn () => (new KycSubmissionIdentity)->forceFill(kycPostDecisionIdentity($submission->id, '44444444444'))->save())
        ->toThrow(LogicException::class, 'A KYC submission takes no new identity value once it has a decision.');
    expect(KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->count())->toBe(2);
});

it('refuses a new document after a decision', function () {
    kycEnable('document');
    $submission = kycOpen(kycCustomer(), []);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');

    expect(fn () => (new KycDocument)->forceFill(kycPostDecisionDocument($submission->id))->save())
        ->toThrow(LogicException::class, 'A KYC submission takes no new document once it has a decision.');
    expect(KycDocument::count())->toBe(0);
});

it('keeps the values an approval accepted exactly as they were, when a later write is refused', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);
    $rows = fn () => DB::table('kyc_submission_identities')->where('kyc_submission_id', $submission->id)->orderBy('id')
        ->get()->map(fn ($row) => (array) $row)->all();
    $before = $rows();

    expect(fn () => (new KycSubmissionIdentity)->forceFill(kycPostDecisionIdentity($submission->id, '44444444444'))->save())
        ->toThrow(LogicException::class);

    expect($rows())->toBe($before)
        ->and(KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn)->ciphertext)->toBe(KYC_TEST_BVN);
});

it('still writes a correction after an earlier submission is decided', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $initial = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    kycDecide($initial, kycStaff(SystemRole::Manager), KycStatus::Approved);

    $correction = kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction);

    expect(KycSubmissionIdentity::where('kyc_submission_id', $correction->id)->count())->toBe(1)
        ->and(KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn)->ciphertext)->toBe(KYC_TEST_BVN);
});
