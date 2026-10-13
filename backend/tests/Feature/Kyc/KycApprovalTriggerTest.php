<?php

use App\Models\KycReview;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: the database refuses an approval the submission cannot back, when the approval arrives as raw SQL past the models. It
 * applies the structural rules the application applies: at least one requirement, every requirement a BVN or a NIN, and an identity value
 * for each of them. A rejection or a request for more information is never refused by the database. The database cannot check the keyed
 * values (an identity's mask and lookup hash are keyed with the app key), so a raw approval whose hash does not match its number is
 * accepted there; the application refuses it. Rows here are written with the query builder, so no model guard runs. Synthetic numbers only.
 */

beforeEach(fn () => kycSeed());

/** The message the database raises for a refused approval. */
function kycApprovalTriggerMessage(): string
{
    return 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.';
}

/** A submission row written with the query builder, so no model guard runs. Returns its id. */
function kycRawSubmissionId(User $customer): int
{
    return DB::table('kyc_submissions')->insertGetId(['reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => $customer->id,
        'kind' => 'initial', 'user_type' => 'subscriber', 'form_key' => (string) Str::uuid(), 'submitted_at' => now(), 'created_at' => now()]);
}

/** A requirement snapshot row for a submission, written with the query builder. */
function kycRawSnapshot(int $submissionId, string $key): void
{
    $requirement = kycRequirement($key);
    DB::table('kyc_submission_requirements')->insert(['kyc_submission_id' => $submissionId, 'kyc_requirement_id' => $requirement->id,
        'requirement_key' => $key, 'requirement_type' => $key, 'label' => $requirement->label,
        'phone_fingerprint' => $key === 'phone' ? str_repeat('a', 64) : null, 'created_at' => now()]);
}

/** A BVN or NIN identity value for a submission, with its mask and lookup hash computed for its number, written with the query builder. */
function kycRawIdentityRow(int $submissionId, string $key): void
{
    $type = KycRequirementType::from($key);
    $number = $type === KycRequirementType::Bvn ? KYC_TEST_BVN : KYC_TEST_NIN;
    DB::table('kyc_submission_identities')->insert(['kyc_submission_id' => $submissionId, 'requirement_key' => $key,
        'ciphertext' => Crypt::encryptString($number), 'masked_value' => KycIdentityNumber::mask($number),
        'lookup_hash' => KycIdentityNumber::lookupHash($type, $number), 'created_at' => now()]);
}

/** A review row written with the query builder. Returns the exception the database raises, or null. */
function kycRawReview(int $submissionId, string $decision): ?Throwable
{
    try {
        DB::table('kyc_reviews')->insert(['kyc_submission_id' => $submissionId, 'decision' => $decision,
            'reason' => $decision === 'approved' ? null : 'The document is not the one requested.',
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now()]);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

it('refuses a raw approval the submission cannot back, and writes no review', function (array $requirements, array $identities) {
    kycEnable('bvn');
    kycEnable('nin');
    kycEnable('phone');
    kycEnable('document');
    $submission = kycRawSubmissionId(kycCustomer());
    foreach ($requirements as $key) {
        kycRawSnapshot($submission, $key);
    }
    foreach ($identities as $key) {
        kycRawIdentityRow($submission, $key);
    }

    $refusal = kycRawReview($submission, 'approved');

    expect($refusal)->toBeInstanceOf(QueryException::class)
        ->and(str_contains($refusal->getMessage(), kycApprovalTriggerMessage()))->toBeTrue()
        ->and(DB::table('kyc_reviews')->count())->toBe(0);
})->with([
    'no requirement at all' => [[], []],
    'BVN with no identity value' => [['bvn'], []],
    'NIN with no identity value, BVN present' => [['bvn', 'nin'], ['bvn']],
    'phone verification, which cannot be completed' => [['phone'], []],
    'provider document, which cannot be completed' => [['document'], []],
    'BVN with its identity value and a document' => [['bvn', 'document'], ['bvn']],
]);

it('accepts a raw approval when every requirement is a BVN or a NIN and each has an identity value', function (array $requirements) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycRawSubmissionId(kycCustomer());
    foreach ($requirements as $key) {
        kycRawSnapshot($submission, $key);
        kycRawIdentityRow($submission, $key);
    }

    expect(kycRawReview($submission, 'approved'))->toBeNull()
        ->and(DB::table('kyc_reviews')->where('kyc_submission_id', $submission)->value('decision'))->toBe('approved');
})->with([
    'BVN' => [['bvn']],
    'NIN' => [['nin']],
    'BVN and NIN' => [['bvn', 'nin']],
]);

it('does not refuse a raw rejection or request for more information, whatever the submission asked for', function (string $decision, array $requirements) {
    kycEnable('bvn');
    kycEnable('phone');
    $submission = kycRawSubmissionId(kycCustomer());
    foreach ($requirements as $key) {
        kycRawSnapshot($submission, $key);
    }

    expect(kycRawReview($submission, $decision))->toBeNull()
        ->and(DB::table('kyc_reviews')->where('kyc_submission_id', $submission)->value('decision'))->toBe($decision);
})->with([
    'rejected, no requirement' => ['rejected', []],
    'rejected, phone verification' => ['rejected', ['phone']],
    'more information requested, no identity value' => ['more_info_requested', ['bvn']],
]);

it('documents the limit: a raw approval whose lookup hash does not match its number is accepted, because only the application checks keyed values', function () {
    kycEnable('bvn');
    $submission = kycRawSubmissionId(kycCustomer());
    kycRawSnapshot($submission, 'bvn');
    DB::table('kyc_submission_identities')->insert(['kyc_submission_id' => $submission, 'requirement_key' => 'bvn',
        'ciphertext' => Crypt::encryptString(KYC_TEST_BVN), 'masked_value' => KycIdentityNumber::mask(KYC_TEST_BVN),
        'lookup_hash' => KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_NIN), 'created_at' => now()]);

    expect(kycRawReview($submission, 'approved'))->toBeNull()
        ->and(KycReview::count())->toBe(1);
});
