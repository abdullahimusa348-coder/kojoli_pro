<?php

use App\Models\KycSubmissionIdentity;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\RecipientType;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: how a KYC identity number is kept. The number is encrypted at rest, shown only as a mask, and found again only
 * through keyed hashes that no column reveals. These tests use synthetic numbers, and they check the outputs of the code, never the
 * text of a failure message. The Phase 11 comparison is here on purpose: a KYC hash must never equal a purchase hash.
 */

beforeEach(fn () => kycSeed());

it('reads a number only when it is exactly 11 digits, ignoring spaces', function (?string $input, ?string $expected) {
    expect(KycIdentityNumber::normalize($input))->toBe($expected);
})->with([
    'plain' => ['22222222222', '22222222222'],
    'spaced' => ['2222 2222 222', '22222222222'],
    'short' => ['1234', null],
    'long' => ['222222222222', null],
    'letters' => ['2222222222A', null],
    'empty' => ['', null],
    'missing' => [null, null],
]);

it('masks a number to seven dots and its last four digits', function () {
    expect(KycIdentityNumber::mask(KYC_TEST_BVN))->toBe('•••••••2222');
});

it('keys each hash with a secret that only the app key yields, and by type, so a NIN and a BVN with the same digits never match', function () {
    $bvn = KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN);

    expect(preg_match('/\A[0-9a-f]{64}\z/', $bvn))->toBe(1);
    expect(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN) === $bvn)->toBeTrue();
    expect(KycIdentityNumber::lookupHash(KycRequirementType::Nin, KYC_TEST_BVN) === $bvn)->toBeFalse();
    expect(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, '44444444444') === $bvn)->toBeFalse();
    expect($bvn === hash('sha256', 'bvn:'.KYC_TEST_BVN))->toBeFalse();
    expect(str_contains($bvn, KYC_TEST_BVN))->toBeFalse();
});

it('keeps KYC hashes apart from the Phase 11 purchase hashes for the same number', function () {
    $kyc = KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN);

    expect($kyc === IdentityHasher::lookupHash(RecipientType::Bvn, KYC_TEST_BVN))->toBeFalse();
});

it('keeps a hash made under a previous app key findable after the key is rotated', function () {
    $before = KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN);
    $previous = app('encrypter')->getKey();
    app()->instance('encrypter', (new Encrypter(random_bytes(32), 'AES-256-CBC'))->previousKeys([$previous]));

    expect(in_array($before, KycIdentityNumber::lookupHashes(KycRequirementType::Bvn, KYC_TEST_BVN), true))->toBeTrue();
    expect(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN) === $before)->toBeFalse();
});

it('finds the same number across customers under the same type, and never across types or other numbers', function () {
    kycEnable('bvn');
    kycEnable('nin');
    kycOpen(kycCustomer());
    kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN, 'nin' => '44444444444']);
    kycOpen(kycCustomer(), ['bvn' => '55555555555', 'nin' => KYC_TEST_BVN]);

    expect(KycSubmissionIdentity::matching(KycRequirementType::Bvn, KYC_TEST_BVN)->count())->toBe(2);
    expect(KycSubmissionIdentity::matching(KycRequirementType::Nin, KYC_TEST_BVN)->count())->toBe(1);
    expect(KycSubmissionIdentity::matching(KycRequirementType::Bvn, '66666666666')->count())->toBe(0);
});

it('stores the same number encrypted differently each time, so equal ciphertext never shows equal numbers', function () {
    kycEnable('bvn');
    kycEnable('nin');
    kycOpen(kycCustomer());
    kycOpen(kycCustomer());

    $ciphertexts = DB::table('kyc_submission_identities')->where('requirement_key', 'bvn')->pluck('ciphertext')->all();
    $hashes = DB::table('kyc_submission_identities')->where('requirement_key', 'bvn')->pluck('lookup_hash')->unique()->all();

    expect(count(array_unique($ciphertexts)))->toBe(2);
    expect($hashes)->toHaveCount(1);
});

it('hides the ciphertext and the lookup hash from arrays, JSON and debug output, and shows only the mask', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $identity = KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->where('requirement_key', 'bvn')->sole();
    $row = (array) DB::table('kyc_submission_identities')->where('id', $identity->id)->first();

    ob_start();
    var_dump($identity);
    $dump = ob_get_clean();

    expect(array_key_exists('ciphertext', $identity->toArray()))->toBeFalse();
    expect(array_key_exists('lookup_hash', $identity->toArray()))->toBeFalse();
    expect(str_contains($identity->toJson(), (string) $row['ciphertext']))->toBeFalse();
    expect(str_contains($identity->toJson(), (string) $row['lookup_hash']))->toBeFalse();
    expect(str_contains($dump, (string) $row['ciphertext']))->toBeFalse();
    expect(str_contains($dump, (string) $row['lookup_hash']))->toBeFalse();
    expect(str_contains($dump, '•••••••2222'))->toBeTrue();
});
