<?php

use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycRequirement;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\User;
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
 * Phase 13 CP2a approval gate, application side. An approval is recorded only when the submission asked for at least one requirement,
 * every requirement is a BVN or a NIN, the phone is unchanged when it was asked for, and each BVN or NIN has an identity value that
 * matches its requirement. A refusal writes nothing: no review, no decision audit event, and the open slot and the profile stay as they
 * were. A rejection or a request for more information is never gated. Synthetic numbers only.
 */

beforeEach(fn () => kycSeed());

/** The throwable a call raises, or null, so a test can read a refusal's message. */
function kycApprovalRefusal(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * A submission written the way the ledger writes one, holding the requirement snapshot and identity values the test asks for, with its
 * open slot and a profile that is pending review. The model guards accept each row, so only the approval gate is under test.
 */
function kycApprovalSubmission(User $customer, array $requirementKeys, array $identityKeys = []): KycSubmission
{
    $submission = (new KycSubmission)->forceFill(['reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => $customer->id,
        'kind' => 'initial', 'user_type' => $customer->user_type->value, 'form_key' => (string) Str::uuid(), 'submitted_at' => now()]);
    $submission->save();

    foreach ($requirementKeys as $key) {
        $requirement = kycRequirement($key);
        (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id, 'kyc_requirement_id' => $requirement->id,
            'requirement_key' => $key, 'requirement_type' => $key, 'label' => $requirement->label, 'phone_fingerprint' => null])->save();
    }
    foreach ($identityKeys as $key) {
        $type = KycRequirementType::from($key);
        $number = $type === KycRequirementType::Bvn ? KYC_TEST_BVN : KYC_TEST_NIN;
        (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => $key, 'ciphertext' => $number,
            'masked_value' => KycIdentityNumber::mask($number), 'lookup_hash' => KycIdentityNumber::lookupHash($type, $number)])->save();
    }

    (new KycOpenSubmission)->forceFill(['user_id' => $customer->id, 'kyc_submission_id' => $submission->id])->save();
    DB::table('kyc_profiles')->insert(['user_id' => $customer->id, 'status' => KycStatus::PendingReview->value,
        'created_at' => now(), 'updated_at' => now()]);

    return $submission;
}

/** An identity value written around the model, with a mask or lookup hash that does not belong to its number (the model would refuse it). */
function kycRawIdentity(KycSubmission $submission, string $key, string $mask, string $hashNumber): void
{
    DB::table('kyc_submission_identities')->insert(['kyc_submission_id' => $submission->id, 'requirement_key' => $key,
        'ciphertext' => Crypt::encryptString(KYC_TEST_BVN), 'masked_value' => $mask,
        'lookup_hash' => KycIdentityNumber::lookupHash(KycRequirementType::from($key), $hashNumber), 'created_at' => now()]);
}

/** A refused approval wrote nothing: no review, no decision audit event, the slot still open, the profile still pending review. */
function kycAssertNothingRecorded(User $customer, KycSubmission $submission): void
{
    expect(KycReview::count())->toBe(0)
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(0)
        ->and(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($submission->id)
        ->and(DB::table('kyc_profiles')->where('user_id', $customer->id)->value('status'))->toBe(KycStatus::PendingReview->value);
}

it('refuses an approval for a submission that asked for no requirement, and records nothing', function () {
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, []);

    $refusal = kycApprovalRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe('This submission has no requirement to approve, so it cannot be approved.');
    kycAssertNothingRecorded($customer, $submission);
});

it('refuses an approval when a BVN or NIN requirement has no identity record, and records nothing', function (array $requirements, array $identities, string $label) {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, $requirements, $identities);

    $refusal = kycApprovalRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe("The {$label} on this submission is missing, so it cannot be approved.");
    kycAssertNothingRecorded($customer, $submission);
})->with([
    'BVN with no identity value' => [['bvn'], [], KycRequirementType::Bvn->label()],
    'NIN with no identity value, the BVN present' => [['bvn', 'nin'], ['bvn'], KycRequirementType::Nin->label()],
]);

it('refuses an approval when an identity record does not match its requirement, and records nothing', function (string $case) {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn']);
    $mask = KycIdentityNumber::mask(KYC_TEST_BVN);
    match ($case) {
        'lookup hash of another number' => kycRawIdentity($submission, 'bvn', $mask, KYC_TEST_NIN),
        'mask of another number' => kycRawIdentity($submission, 'bvn', '•••••••9999', KYC_TEST_BVN),
    };

    $refusal = kycApprovalRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe('The '.KycRequirementType::Bvn->label().' on this submission does not match its requirement, so it cannot be approved.');
    kycAssertNothingRecorded($customer, $submission);
})->with(['lookup hash of another number', 'mask of another number']);

it('refuses an approval that also holds a document requirement, whatever its BVN and NIN look like', function () {
    kycEnable('bvn');
    kycEnable('document');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn', 'document'], ['bvn']);

    $refusal = kycApprovalRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe(KycRequirementType::Document->label().' cannot be completed in this version, so this submission cannot be approved yet.');
    kycAssertNothingRecorded($customer, $submission);
});

it('records an approval when every requirement is a BVN or a NIN and each has a matching identity record', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn', 'nin'], ['bvn', 'nin']);

    $review = kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    expect($review->decision)->toBe(KycStatus::Approved)
        ->and(KycReview::count())->toBe(1)
        ->and(KycOpenSubmission::whereKey($customer->id)->exists())->toBeFalse()
        ->and(DB::table('kyc_profiles')->where('user_id', $customer->id)->value('status'))->toBe(KycStatus::Approved->value);
});

it('still records a rejection or a request for more information for a submission the approval gate would refuse', function (KycStatus $decision) {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn']);

    $review = kycDecide($submission, kycStaff(SystemRole::Manager), $decision, 'Please send a clearer copy of the request.');

    expect($review->decision)->toBe($decision)
        ->and(KycReview::count())->toBe(1)
        ->and(KycOpenSubmission::whereKey($customer->id)->exists())->toBeFalse();
})->with([
    'rejection, identity value missing' => [KycStatus::Rejected],
    'more information requested, identity value missing' => [KycStatus::MoreInfoRequested],
]);

it('refuses a direct model approval that the approval gate would refuse, with the model message and no review', function (string $case, string $message) {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = match ($case) {
        'no requirement' => kycApprovalSubmission($customer, []),
        'identity value missing' => kycApprovalSubmission($customer, ['bvn']),
    };

    $refusal = kycApprovalRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => null, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe($message)
        ->and(KycReview::count())->toBe(0);
})->with([
    'no requirement' => ['no requirement', 'A KYC approval needs a submission that asked for at least one requirement.'],
    'identity value missing' => ['identity value missing', 'A KYC approval needs a BVN or NIN identity value that matches its requirement.'],
]);

// An identity value written for another submission, here one belonging to another customer, must not satisfy this submission's
// requirement: the gate, the model and the database each look only at the identity values of the submission being approved.
it('refuses an approval whose BVN identity belongs to another submission, and writes nothing', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn']);
    kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, 'The BVN on this submission is missing, so it cannot be approved.');
    kycAssertNothingRecorded($customer, $submission);
});

it('refuses a direct model approval whose BVN identity belongs to another submission, with the model message and no review', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn']);
    kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);

    $refusal = kycApprovalRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => null, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('A KYC approval needs a BVN or NIN identity value that matches its requirement.');
    kycAssertNothingRecorded($customer, $submission);
});

it('refuses a raw approval whose BVN identity belongs to another submission, with the trigger message and no review', function () {
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycApprovalSubmission($customer, ['bvn']);
    kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);

    expect(fn () => DB::table('kyc_reviews')->insert(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
        'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now()]))
        ->toThrow(QueryException::class, 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.');
    kycAssertNothingRecorded($customer, $submission);
});

// Each identity value is matched to its requirement by key, as the database trigger matches it. Two requirements of one type can both
// be on: the ledger keeps one value per type, so the second requirement has no value of its own and must not be approved on the first's.
it('matches each identity value to its own requirement by key, so a second requirement of the same type needs a value of its own', function () {
    kycEnable('bvn');
    kycEnable('nin');
    (new KycRequirement)->forceFill(['key' => 'bvn-second', 'type' => 'bvn', 'label' => 'Second BVN check', 'description' => null,
        'is_enabled' => true, 'user_types' => ['subscriber'], 'purposes' => [], 'position' => 90])->save();
    $customer = kycCustomer();
    $submission = kycOpen($customer);

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, 'The BVN on this submission is missing, so it cannot be approved.');
    kycAssertNothingRecorded($customer, $submission);
});

it('refuses a direct model approval whose phone changed after it was sent, with the phone message and no review', function () {
    kycEnable('phone');
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    DB::table('users')->where('id', $customer->id)->update(['phone' => '08099990000']);

    $refusal = kycApprovalRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => null, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('A KYC approval needs the phone number the submission was sent with.');
    kycAssertNothingRecorded($customer, $submission);
});
