<?php

use App\Actions\Admin\Kyc\SaveKycRequirement;
use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycProfile;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycRequirementType;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a: the submission and decision ledger on SQLite. A submission is opened with its requirement snapshot, its identity
 * values and its open slot in one transaction, and a decision closes the slot and writes the review and the audit event in one
 * transaction. These tests cover the rules the ledger enforces that the database cannot express, and that a refused request writes
 * nothing. Identity numbers here are synthetic.
 */

beforeEach(fn () => kycSeed());

it('opens an initial submission with a snapshot of the requirements that applied, its identity values and one open slot', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();

    $submission = kycOpen($customer);

    expect($submission->reference)->toMatch('/\AKYC-[0-9A-Z]{26}\z/');
    expect($submission->kind)->toBe(KycSubmissionKind::Initial);
    expect($submission->user_type)->toBe(UserType::Subscriber);
    expect(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->pluck('requirement_key')->sort()->values()->all())->toBe(['bvn', 'nin']);
    expect(KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->count())->toBe(2);
    expect(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($submission->id);
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::PendingReview);
});

it('keeps each identity value encrypted, masked and keyed, with no plain number in any column', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $identity = KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->where('requirement_key', 'bvn')->sole();
    $raw = (array) DB::table('kyc_submission_identities')->where('id', $identity->id)->first();

    expect($raw['masked_value'])->toBe('•••••••2222');
    expect($raw['lookup_hash'])->toBe(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN));
    expect(collect($raw)->contains(fn ($value) => is_string($value) && str_contains($value, KYC_TEST_BVN)))->toBeFalse();
    expect(hash_equals(KYC_TEST_BVN, (string) $identity->ciphertext))->toBeTrue();
});

it('refuses a second open submission for the same customer, and writes nothing for it', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    kycOpen($customer);
    $before = [KycSubmission::count(), KycSubmissionIdentity::count(), KycOpenSubmission::count()];

    expect(fn () => kycOpen($customer))->toThrow(KycException::class, 'A verification submission is already waiting for review.');
    expect([KycSubmission::count(), KycSubmissionIdentity::count(), KycOpenSubmission::count()])->toBe($before);
});

it('returns the submission a repeated form made, and creates nothing new for it', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $key = (string) Str::uuid();

    $first = kycOpen($customer, formKey: $key);
    $again = kycOpen($customer, formKey: $key);

    expect($again->id)->toBe($first->id);
    expect(KycSubmission::count())->toBe(1);
    expect(KycAuditEvent::count())->toBe(1);
});

it('refuses a form key that another customer already used', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $key = (string) Str::uuid();
    kycOpen(kycCustomer(), formKey: $key);

    expect(fn () => kycOpen(kycCustomer(), formKey: $key))->toThrow(KycException::class, 'This form was already used for another request.');
});

it('lets a rejected or more-information customer resubmit, and refuses an initial submission once approved', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);

    kycDecide(kycOpen($customer), $staff, KycStatus::Rejected, 'The NIN photo is not clear enough.');
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::Rejected);

    kycDecide(kycOpen($customer), $staff, KycStatus::MoreInfoRequested, 'Send a clearer copy of the NIN slip.');
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::MoreInfoRequested);

    kycDecide(kycOpen($customer), $staff, KycStatus::Approved);
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::Approved);

    expect(fn () => kycOpen($customer))->toThrow(KycException::class, 'Your verification is already approved.');
});

it('refuses a submission when no requirement applies to the customer, and writes nothing', function () {
    expect(fn () => kycOpen(kycCustomer(), []))->toThrow(KycException::class, 'No verification is required for your account.');
    expect(KycSubmission::count())->toBe(0);
    expect(KycProfile::count())->toBe(0);
});

it('snapshots only the requirements switched on for the customer type, and asks only for those identity values', function () {
    kycEnable('bvn', ['vendor']);
    kycEnable('nin', ['subscriber']);
    $vendor = kycCustomer(UserType::Vendor);

    expect(fn () => kycOpen($vendor, ['bvn' => KYC_TEST_BVN, 'nin' => KYC_TEST_NIN]))
        ->toThrow(KycException::class, 'NIN is not requested for this submission.');
    expect(KycSubmission::count())->toBe(0);

    $submission = kycOpen($vendor, ['bvn' => KYC_TEST_BVN]);
    expect(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->pluck('requirement_key')->all())->toBe(['bvn']);
});

it('requires each applicable identity value, refuses a malformed one without naming it, and rejects keys that are not identities', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();

    expect(fn () => kycOpen($customer, ['bvn' => KYC_TEST_BVN]))->toThrow(KycException::class, 'Enter your NIN.');
    expect(fn () => kycOpen($customer, ['bvn' => '1234', 'nin' => KYC_TEST_NIN]))->toThrow(KycException::class, 'Enter a valid 11-digit BVN.');
    expect(fn () => kycOpen($customer, ['bvn' => KYC_TEST_BVN, 'nin' => KYC_TEST_NIN, 'phone' => '08012345678']))
        ->toThrow(InvalidArgumentException::class, 'Only a BVN or a NIN is kept as a KYC identity value.');
    expect(KycSubmission::count())->toBe(0);
});

it('accepts a value with spaces, and masks and hashes its canonical digits', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer(), ['bvn' => '2222 2222 222', 'nin' => KYC_TEST_NIN]);

    $bvn = KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->where('requirement_key', 'bvn')->sole();
    expect($bvn->masked_value)->toBe('•••••••2222');
    expect($bvn->lookup_hash)->toBe(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, KYC_TEST_BVN));
});

it('refuses a disabled customer account, and creates no submission', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = User::factory()->disabled()->create();

    expect(fn () => kycOpen($customer))->toThrow(KycException::class, 'This account is not active');
    expect(KycSubmission::count())->toBe(0);
});

it('keeps the requirement name and the customer type a submission was sent with, even after they change', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer(UserType::Subscriber);
    $submission = kycOpen($customer);
    $staff = kycStaff();

    app(SaveKycRequirement::class)->handle(kycRequirement('bvn'), 'Bank verification number', null, true, ['subscriber'],
        'Renamed for the next release', $staff, SaveKycRequirement::fingerprint(kycRequirement('bvn')));
    app(SaveKycRequirement::class)->handle(kycRequirement('nin'), 'NIN', null, false, [],
        'Switched off for the next release', $staff, SaveKycRequirement::fingerprint(kycRequirement('nin')));
    $customer->forceFill(['user_type' => UserType::Vendor])->save();

    expect(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->where('requirement_key', 'bvn')->sole()->label)->toBe('BVN');
    expect(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->where('requirement_key', 'nin')->exists())->toBeTrue();
    expect($submission->fresh()->user_type)->toBe(UserType::Subscriber);
});

it('sends a correction only after approval, and leaves the approved status in place', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    $initial = kycOpen($customer);

    expect(fn () => kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction))
        ->toThrow(KycException::class, 'A verification submission is already waiting for review.');

    kycDecide($initial, $staff, KycStatus::Rejected, 'The document is not the one requested.');
    expect(fn () => kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction))
        ->toThrow(KycException::class, 'A correction can be sent only after your verification is approved');

    $resubmitted = kycOpen($customer);
    kycDecide($resubmitted, $staff, KycStatus::Approved);
    $correction = kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction);

    expect($correction->kind)->toBe(KycSubmissionKind::Correction);
    expect(KycSubmissionRequirement::where('kyc_submission_id', $correction->id)->pluck('requirement_key')->all())->toBe(['bvn']);
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::Approved);
});

it('refuses a correction for an identity that is switched off, or with no value', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    kycDecide(kycOpen($customer), $staff, KycStatus::Approved);

    app(SaveKycRequirement::class)->handle(kycRequirement('nin'), 'NIN', null, false, [], 'Switched off for the test',
        $staff, SaveKycRequirement::fingerprint(kycRequirement('nin')));

    expect(fn () => kycOpen($customer, ['nin' => KYC_TEST_NIN], KycSubmissionKind::Correction))
        ->toThrow(KycException::class, 'A correction can be sent only after your verification is approved');
    expect(fn () => kycOpen($customer, [], KycSubmissionKind::Correction))
        ->toThrow(KycException::class, 'A correction can be sent only after your verification is approved');
});

it('keeps the value an approval accepted, and makes an approved correction the value now accepted', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    $initial = kycOpen($customer);
    kycDecide($initial, $staff, KycStatus::Approved);

    $original = KycSubmissionIdentity::where('kyc_submission_id', $initial->id)->where('requirement_key', 'bvn')->sole();
    $before = (array) DB::table('kyc_submission_identities')->where('id', $original->id)->first();
    expect(KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn)?->id)->toBe($original->id);

    $correction = kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction);
    expect(KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn)?->id)->toBe($original->id);

    kycDecide($correction, $staff, KycStatus::Approved);
    $accepted = KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn);
    expect($accepted?->kyc_submission_id)->toBe($correction->id);
    expect($accepted?->lookup_hash)->toBe(KycIdentityNumber::lookupHash(KycRequirementType::Bvn, '44444444444'));
    expect((array) DB::table('kyc_submission_identities')->where('id', $original->id)->first())->toEqual($before);
});

it('keeps the approved value when a correction is rejected', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    $initial = kycOpen($customer);
    kycDecide($initial, $staff, KycStatus::Approved);

    $correction = kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction);
    kycDecide($correction, $staff, KycStatus::Rejected, 'The new number does not match the record provided.');

    expect(KycSubmissionIdentity::acceptedFor($customer, KycRequirementType::Bvn)?->kyc_submission_id)->toBe($initial->id);
    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::Approved);
});

it('needs a reason of 10 to 500 characters with a letter for a rejection', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, $reason))
        ->toThrow(KycException::class, 'Enter a reason of 10 to 500 characters that includes at least one letter.');
    expect(KycReview::count())->toBe(0);
    expect(KycOpenSubmission::count())->toBe(1);
})->with([
    'too short' => ['too short'],
    'no letter' => ['1234567890'],
    'too long' => [str_repeat('a', 501)],
    'blank' => ['          '],
]);

it('records a decision with its reviewer, its time and the reason the customer will see', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $staff = kycStaff(SystemRole::Manager);
    $submission = kycOpen(kycCustomer());

    $review = kycDecide($submission, $staff, KycStatus::Rejected, '  The BVN does not match the photo.  ');

    expect($review->decision)->toBe(KycStatus::Rejected);
    expect($review->reason)->toBe('The BVN does not match the photo.');
    expect($review->reviewed_by)->toBe($staff->id);
    expect($review->decided_at)->not->toBeNull();
    expect(KycOpenSubmission::count())->toBe(0);
});

it('refuses a decision by staff who cannot review KYC, or whose account is disabled, and changes nothing', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $support = kycStaff(SystemRole::Support);
    $disabled = kycStaff(SystemRole::Manager);
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();

    expect(fn () => kycDecide($submission, $support, KycStatus::Rejected, 'The photo is blurred.'))
        ->toThrow(KycException::class, 'This staff account cannot decide KYC submissions.');
    expect(fn () => kycDecide($submission, $disabled, KycStatus::Rejected, 'The photo is blurred.'))
        ->toThrow(KycException::class, 'This staff account cannot decide KYC submissions.');
    expect(KycReview::count())->toBe(0);
    expect(KycOpenSubmission::count())->toBe(1);
});

it('refuses a decision by a staff account that shares the customer email or phone', function (string $field) {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    $twin = SystemUser::factory()->create([$field => $field === 'email' ? $customer->email : $customer->phone]);
    $twin->assignRole(SystemRole::Manager->value);

    expect(fn () => kycDecide($submission, $twin, KycStatus::Rejected, 'The photo is blurred.'))
        ->toThrow(KycException::class, 'This staff account cannot decide this submission.');
    expect(KycReview::count())->toBe(0);
})->with(['email', 'phone']);

it('refuses to approve a submission that asked for a phone verification or a document, and still records a rejection', function (string $key, string $label) {
    kycEnable($key);
    $customer = kycCustomer();
    $submission = kycOpen($customer, []);
    $staff = kycStaff(SystemRole::Manager);

    expect(fn () => kycDecide($submission, $staff, KycStatus::Approved))
        ->toThrow(KycException::class, "{$label} cannot be completed in this version, so this submission cannot be approved yet.");
    expect(KycReview::count())->toBe(0);

    kycDecide($submission, $staff, KycStatus::Rejected, 'The required item is not in this version yet.');
    expect(KycReview::count())->toBe(1);
})->with([
    'phone' => ['phone', 'Phone number'],
    'document' => ['document', 'Provider document'],
]);

it('refuses to approve a submission whose phone changed after it was sent', function () {
    kycEnable('phone');
    $customer = kycCustomer();
    $submission = kycOpen($customer, []);
    $customer->forceFill(['phone' => '08011112222'])->save();

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved))
        ->toThrow(KycException::class, 'This submission changed while you were reviewing it.');
    expect(KycReview::count())->toBe(0);
});

it('records one decision per submission, and refuses a second decision made on another form', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $first = kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'A second look finds a problem.'))
        ->toThrow(KycException::class, 'This submission has already been decided.');
    expect(KycReview::count())->toBe(1);
    expect(KycReview::sole()->id)->toBe($first->id);
});

it('returns the decision a repeated form made, and records nothing new', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $staff = kycStaff(SystemRole::Manager);
    $key = (string) Str::uuid();

    $first = kycDecide($submission, $staff, KycStatus::Approved, null, $key);
    $again = kycDecide($submission, $staff, KycStatus::Approved, null, $key);

    expect($again->id)->toBe($first->id);
    expect(KycReview::count())->toBe(1);
    expect(KycAuditEvent::where('event', 'review.decided')->count())->toBe(1);
});

it('moves the status for an initial decision, and leaves an approved status alone for a correction decision', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    kycDecide(kycOpen($customer), $staff, KycStatus::Approved);
    $correction = kycOpen($customer, ['bvn' => '44444444444'], KycSubmissionKind::Correction);

    kycDecide($correction, $staff, KycStatus::MoreInfoRequested, 'Send a clearer copy of the BVN slip.');

    expect(KycProfile::where('user_id', $customer->id)->sole()->status)->toBe(KycStatus::Approved);
});

it('writes an audit event for each submission and each decision, with allowlisted metadata and no identity number', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $staff = kycStaff(SystemRole::Manager);
    $submission = kycOpen($customer);
    kycDecide($submission, $staff, KycStatus::Approved);

    $events = KycAuditEvent::orderBy('id')->get();
    expect($events->map(fn (KycAuditEvent $event) => $event->event->value)->all())->toBe(['submission.opened', 'review.decided']);
    expect($events[0]->system_user_id)->toBeNull();
    expect($events[1]->system_user_id)->toBe($staff->id);
    expect($events[0]->metadata)->toBe(['kind' => 'initial', 'requirement_count' => 2]);
    expect($events[1]->metadata)->toBe(['decision' => 'approved']);
    $stored = json_encode(DB::table('kyc_audit_events')->pluck('metadata')->all());
    expect(str_contains((string) $stored, KYC_TEST_BVN) || str_contains((string) $stored, KYC_TEST_NIN))->toBeFalse();
});

it('keeps each customer open slot and submissions to their own', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $first = kycCustomer();
    $second = kycCustomer();
    $firstSubmission = kycOpen($first);
    $secondSubmission = kycOpen($second, ['bvn' => '44444444444', 'nin' => '55555555555']);

    expect(KycOpenSubmission::whereKey($first->id)->value('kyc_submission_id'))->toBe($firstSubmission->id);
    expect(KycOpenSubmission::whereKey($second->id)->value('kyc_submission_id'))->toBe($secondSubmission->id);
    expect(KycSubmission::where('user_id', $first->id)->pluck('id')->all())->toBe([$firstSubmission->id]);
});

it('frees the open slot through the release trigger alone, so the application issues no DELETE on the slot', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);

    DB::enableQueryLog();
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');
    $statements = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($statements->filter(fn (string $sql) => str_starts_with(strtolower(ltrim($sql)), 'delete') && str_contains($sql, 'kyc_open_submissions'))->all())->toBe([])
        ->and(KycOpenSubmission::whereKey($customer->id)->exists())->toBeFalse();
});

it('refuses a decision whose release leaves the open slot in place, and records nothing', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Dropping a trigger commits the test transaction on MariaDB, so this test runs on SQLite only.');
    }
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    DB::statement('DROP TRIGGER kyc_reviews_release_open_slot');

    expect(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.'))
        ->toThrow(LogicException::class, 'the release trigger did not');
    expect(KycReview::count())->toBe(0);
    expect(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($submission->id);
    expect(KycAuditEvent::where('event', 'review.decided')->count())->toBe(0);
});
