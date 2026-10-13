<?php

use App\Models\KycSubmissionIdentity;
use App\Services\Kyc\KycSubmissionLedger;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycContactFingerprint;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: an identity number reaches no serialised form, no printed dump and no exception trace. A trace shows a string argument
 * in full when zend.exception_ignore_args is off and zend.exception_string_param_max_len is at least the length of the value (PHP's
 * default is 15), and getTrace() returns every argument as it was. The tests set both as a development server does, then check the
 * trace text and the arguments. Parameters that carry a number, or a free-text reason that may hold one, are marked #[\SensitiveParameter].
 * The tests call the ledger and the helpers directly, because a helper's own frame would carry the number. Synthetic numbers only.
 */

beforeEach(fn () => kycSeed());

/**
 * Runs $call with the key read sabotaged, so the keyed hashing raises while the number is in its frame, and returns the trace of the
 * exception as text, followed by every argument getTrace() returns, encoded as JSON. Null when nothing was raised. The call is a
 * closure, so no helper frame holds a number. Encryption still works, so only the hashing fails. The JSON is depth-limited because the
 * test framework's own frames pass large objects (the test case holds the whole application), which print_r would walk in full.
 */
function kycTraceOf(Closure $call): ?string
{
    $settings = ['zend.exception_ignore_args' => ini_get('zend.exception_ignore_args'),
        'zend.exception_string_param_max_len' => ini_get('zend.exception_string_param_max_len')];
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '15');

    $real = app('encrypter');
    app()->instance('encrypter', new class($real->getKey(), config('app.cipher')) extends Encrypter
    {
        public function getKey(): string
        {
            throw new RuntimeException('The key read is sabotaged for this test.');
        }
    });
    Crypt::clearResolvedInstance('encrypter');

    try {
        $call();
    } catch (Throwable $e) {
        return $e->getTraceAsString()."\n".json_encode($e->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR, 6);
    } finally {
        foreach ($settings as $name => $value) {
            ini_set($name, $value);
        }
        kycResetEncrypter();
    }

    return null;
}

it('keeps an identity number out of every serialised and printed form of a stored identity value', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    $identity = KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->sole();
    expect($identity->ciphertext)->toBe(KYC_TEST_BVN); // reading it decrypts the number, so any cache the model keeps would hold it

    ob_start();
    var_dump($identity);
    $varDump = ob_get_clean();

    $forms = [
        'json_encode' => json_encode($identity),
        'toArray' => json_encode($identity->toArray()),
        'serialize' => serialize($identity),
        'var_export' => var_export($identity, true),
        'print_r' => print_r($identity, true),
        'var_dump' => $varDump,
    ];

    expect(array_map(fn (string $form) => str_contains($form, KYC_TEST_BVN), $forms))
        ->toBe(array_fill_keys(array_keys($forms), false));
});

it('keeps an identity number out of the trace of an exception raised while the ledger hashes it', function () {
    kycEnable('bvn');
    $customer = kycCustomer();

    $trace = kycTraceOf(fn () => app(KycSubmissionLedger::class)->open($customer, KycSubmissionKind::Initial,
        ['bvn' => KYC_TEST_BVN], (string) Str::uuid()));

    expect($trace)->not->toBeNull()
        ->and(str_contains((string) $trace, 'KycHashKeys'))->toBeTrue()
        ->and(str_contains((string) $trace, KYC_TEST_BVN))->toBeFalse();
});

it('keeps an identity number out of the trace of a decision refused for holding one', function () {
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    $reviewer = kycStaff(SystemRole::Manager);

    $trace = kycTraceOf(fn () => app(KycSubmissionLedger::class)->decide($submission, $reviewer, KycStatus::Rejected,
        'The form repeats the BVN '.KYC_TEST_BVN.'.', (string) Str::uuid()));

    expect($trace)->not->toBeNull()
        ->and(str_contains((string) $trace, 'reasonHasIdentityNumber'))->toBeTrue()
        ->and(str_contains((string) $trace, KYC_TEST_BVN))->toBeFalse();
});

it('keeps a number or a phone out of the trace of an exception raised while it is hashed or checked', function (string $case) {
    // The secret is built here and never passed in: a dataset value would be an argument of this test's own frame, which a trace shows.
    [$call, $secret] = match ($case) {
        'the keyed lookup hashes' => [fn () => KycIdentityNumber::lookupHashes(KycRequirementType::Bvn, KYC_TEST_BVN), KYC_TEST_BVN],
        'a malformed number, when it is masked' => [fn () => KycIdentityNumber::mask('2222222222222'), '2222222222222'],
        'the phone fingerprint' => [fn () => KycContactFingerprint::forPhone('07000000002'), '07000000002'],
    };

    $trace = kycTraceOf($call);

    expect($trace)->not->toBeNull()
        ->and(str_contains((string) $trace, $secret))->toBeFalse();
})->with(['the keyed lookup hashes', 'a malformed number, when it is masked', 'the phone fingerprint']);
