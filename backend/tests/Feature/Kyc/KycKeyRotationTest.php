<?php

use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycReview;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycContactFingerprint;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a key rotation. KYC values are encrypted under the app key, and their duplicate checks and phone fingerprints are keyed
 * from it. A rotation keeps them working only while every key that wrote them stays in APP_PREVIOUS_KEYS, which these tests prove, approval
 * included. Each
 * test swaps in a fresh random key and restores the app key the suite started with. No key is printed, stored or compared in a message:
 * a failure shows only booleans and synthetic numbers.
 */

beforeEach(fn () => kycSeed());

/** A fresh random app key in the form the configuration uses, so no test depends on the key in .env. */
function kycFreshAppKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/** Switches the app key to $key, keeping $previousKeys as the keys that still decrypt and match. */
function kycRotateAppKey(string $key, array $previousKeys): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    kycResetEncrypter();
}

/** The raw key bytes of an app key in the configuration form, so a test can build its own encrypter. */
function kycRawKey(string $appKey): string
{
    return str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
}

/** A customer with an open submission that holds a BVN and a phone fingerprint, written under the app key the suite is running with. */
function kycRotationRecord(): array
{
    kycEnable('bvn');
    kycEnable('phone');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);

    return [
        'identity' => KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->sole()->id,
        'fingerprint' => KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->where('requirement_key', 'phone')
            ->value('phone_fingerprint'),
        'phone' => (string) $customer->phone,
    ];
}

it('keeps an identity value, its duplicate check and a phone fingerprint readable after the app key rotates, while the old key stays listed', function () {
    $record = kycRotationRecord();
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), [$originalKey]);

        expect(KycSubmissionIdentity::find($record['identity'])->ciphertext)->toBe(KYC_TEST_BVN);
        expect(KycSubmissionIdentity::matching(KycRequirementType::Bvn, KYC_TEST_BVN)->count())->toBe(1);
        expect(KycContactFingerprint::matches($record['fingerprint'], $record['phone']))->toBeTrue();
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('writes a new identity value under the new key, which the old key cannot read', function () {
    kycEnable('bvn');
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), [$originalKey]);
        $submission = kycOpen(kycCustomer(), ['bvn' => '44444444444']);
        $row = DB::table('kyc_submission_identities')->where('kyc_submission_id', $submission->id)->first();
        $cipher = config('app.cipher');

        expect((new Encrypter(kycRawKey(config('app.key')), $cipher))->decryptString($row->ciphertext))->toBe('44444444444');
        expect($row->lookup_hash)->toBe(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, '44444444444'));
        expect(fn () => (new Encrypter(kycRawKey($originalKey), $cipher))->decryptString($row->ciphertext))
            ->toThrow(DecryptException::class);
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('makes the old values unreadable and unmatched when the old key is dropped from the previous keys', function () {
    $record = kycRotationRecord();
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), []);

        expect(fn () => KycSubmissionIdentity::find($record['identity'])->ciphertext)->toThrow(DecryptException::class);
        expect(KycSubmissionIdentity::matching(KycRequirementType::Bvn, KYC_TEST_BVN)->count())->toBe(0);
        expect(KycContactFingerprint::matches($record['fingerprint'], $record['phone']))->toBeFalse();
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('keeps values written under the first key readable through two rotations, when each earlier key stays listed', function () {
    $record = kycRotationRecord();
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        $second = kycFreshAppKey();
        kycRotateAppKey($second, [$originalKey]);
        kycRotateAppKey(kycFreshAppKey(), [$second, $originalKey]);

        expect(KycSubmissionIdentity::find($record['identity'])->ciphertext)->toBe(KYC_TEST_BVN);
        expect(KycSubmissionIdentity::matching(KycRequirementType::Bvn, KYC_TEST_BVN)->count())->toBe(1);
        expect(KycContactFingerprint::matches($record['fingerprint'], $record['phone']))->toBeTrue();
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('approves a submission whose identity value was written under a previous key, while that key stays listed', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), [$originalKey]);

        expect(kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved)->decision)->toBe(KycStatus::Approved)
            ->and(KycReview::where('kyc_submission_id', $submission->id)->count())->toBe(1);
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('records a model approval of such a submission while that key stays listed', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), [$originalKey]);

        (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();

        expect(KycReview::where('kyc_submission_id', $submission->id)->count())->toBe(1);
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('refuses an approval once the key that wrote its identity value is no longer listed, and records nothing', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), []);

        expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
            ->toThrow(KycException::class, 'The '.KycRequirementType::Bvn->label().' on this submission does not match its requirement, so it cannot be approved.');
        expect(KycReview::count())->toBe(0);
        expect(KycAuditEvent::where('event', 'review.decided')->count())->toBe(0);
        expect(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($submission->id);
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});

it('refuses a model approval once the key that wrote its identity value is no longer listed, and records no review', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    [$originalKey, $originalPrevious] = [config('app.key'), config('app.previous_keys')];

    try {
        kycRotateAppKey(kycFreshAppKey(), []);

        expect(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save())
            ->toThrow(LogicException::class, 'A KYC approval needs a BVN or NIN identity value that matches its requirement.');
        expect(KycReview::count())->toBe(0);
    } finally {
        kycRotateAppKey($originalKey, $originalPrevious);
    }
});
