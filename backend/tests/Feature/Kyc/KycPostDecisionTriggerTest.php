<?php

use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: the database refuses the writes the models refuse after a decision, when they arrive as raw SQL that never reaches a
 * model. Each row is shaped as the models would write it, so each refusal comes from the trigger. Recording a review through raw SQL
 * also releases the submission's open slot in the same statement. Synthetic identity numbers only.
 */

beforeEach(fn () => kycSeed());

it('refuses a requirement, identity value or document written with raw SQL after a decision, and writes no row', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');
    kycEnable('nin');
    kycEnable('document');
    $now = now();
    $nin = kycRequirement('nin');
    $counts = fn () => [DB::table('kyc_submission_requirements')->count(), DB::table('kyc_submission_identities')->count(),
        DB::table('kyc_documents')->count()];
    $before = $counts();

    expect(fn () => DB::table('kyc_submission_requirements')->insert(['kyc_submission_id' => $submission->id,
        'kyc_requirement_id' => $nin->id, 'requirement_key' => 'nin', 'requirement_type' => 'nin', 'label' => $nin->label,
        'phone_fingerprint' => null, 'created_at' => $now]))
        ->toThrow(QueryException::class, 'A KYC submission takes no new requirement once it has a decision.');
    expect(fn () => DB::table('kyc_submission_identities')->insert(['kyc_submission_id' => $submission->id, 'requirement_key' => 'nin',
        'ciphertext' => Crypt::encryptString(KYC_TEST_NIN), 'masked_value' => KycIdentityNumber::mask(KYC_TEST_NIN),
        'lookup_hash' => KycIdentityNumber::lookupHash(KycRequirementType::Nin, KYC_TEST_NIN), 'created_at' => $now]))
        ->toThrow(QueryException::class, 'A KYC submission takes no new identity value once it has a decision.');
    expect(fn () => DB::table('kyc_documents')->insert(['kyc_submission_id' => $submission->id, 'requirement_key' => 'document',
        'storage_name' => strtoupper((string) Str::ulid()).'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 2048,
        'sha256' => str_repeat('d', 64), 'uploaded_at' => $now, 'created_at' => $now]))
        ->toThrow(QueryException::class, 'A KYC submission takes no new document once it has a decision.');

    expect($counts())->toBe($before);
});

it('accepts a document row written with raw SQL before a decision', function () {
    kycEnable('document');
    $submission = kycOpen(kycCustomer(), []);

    DB::table('kyc_documents')->insert(['kyc_submission_id' => $submission->id, 'requirement_key' => 'document',
        'storage_name' => strtoupper((string) Str::ulid()).'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 2048,
        'sha256' => str_repeat('d', 64), 'uploaded_at' => now(), 'created_at' => now()]);

    expect(DB::table('kyc_documents')->where('kyc_submission_id', $submission->id)->count())->toBe(1);
});

it('releases the open slot in the same statement when a review is written with raw SQL', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    expect(DB::table('kyc_open_submissions')->where('kyc_submission_id', $submission->id)->count())->toBe(1);

    DB::table('kyc_reviews')->insert(['kyc_submission_id' => $submission->id, 'decision' => KycStatus::Rejected->value,
        'reason' => 'The document is not the one requested.', 'reviewed_by' => kycStaff(SystemRole::Manager)->id,
        'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now()]);

    expect(DB::table('kyc_open_submissions')->count())->toBe(0)
        ->and(DB::table('kyc_reviews')->where('kyc_submission_id', $submission->id)->count())->toBe(1);
});

it('refuses an open slot written with raw SQL for a submission that has a decision', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');

    expect(fn () => DB::table('kyc_open_submissions')->insert(['user_id' => $customer->id,
        'kyc_submission_id' => $submission->id, 'created_at' => now()]))
        ->toThrow(QueryException::class, 'A decided KYC submission cannot hold the open slot.');
    expect(DB::table('kyc_open_submissions')->count())->toBe(0);
});
