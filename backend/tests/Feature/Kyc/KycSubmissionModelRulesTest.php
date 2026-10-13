<?php

use App\Models\KycAuditEvent;
use App\Models\KycDocument;
use App\Models\KycOpenSubmission;
use App\Models\KycRequirement;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use App\Support\Kyc\KycStatus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a model rules: each history model refuses a row its rules do not allow, with the reason, before anything is written.
 * The rules here are the ones the database cannot express. The refusal messages never contain an identity number, and the identity
 * test checks that directly.
 */

beforeEach(fn () => kycSeed());

/** A submission the ledger wrote for a customer, with the BVN and NIN requested. */
function kycRuleSubmission(): KycSubmission
{
    kycEnable('bvn');
    kycEnable('nin');

    return kycOpen(kycCustomer());
}

/** A submission written from its raw attributes, so the model's own rules see the values as they are. The enum casts refuse a bad value on assignment, before those rules run. */
function kycRawSubmission(array $overrides = []): KycSubmission
{
    $submission = (new KycSubmission)->setRawAttributes($overrides + [
        'reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => kycCustomer()->id, 'kind' => 'initial',
        'user_type' => 'subscriber', 'form_key' => (string) Str::uuid(), 'submitted_at' => now(),
    ]);
    $submission->save();

    return $submission;
}

/** The attributes of a document row for a submission, valid unless overridden. */
function kycDocumentAttributes(int $submissionId, array $overrides = []): array
{
    return $overrides + [
        'kyc_submission_id' => $submissionId,
        'requirement_key' => 'document',
        'storage_name' => strtoupper((string) Str::ulid()).'.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => now(),
    ];
}

/** An audit event about a submission, or about one of its documents, written through the model with the given metadata. */
function kycAuditEvent(string $event, KycSubmission $submission, array $metadata, ?KycDocument $document = null): KycAuditEvent
{
    $audit = (new KycAuditEvent)->forceFill(['event' => $event, 'user_id' => $submission->user_id, 'kyc_submission_id' => $submission->id,
        'kyc_document_id' => $document?->id, 'system_user_id' => null, 'metadata' => $metadata]);
    $audit->save();

    return $audit;
}

/** A document stored at the given size, on a submission that holds a document requirement. */
function kycStoredDocument(int $sizeBytes = 2048): KycDocument
{
    kycEnable('document');
    $submission = kycOpen(kycCustomer(), []);
    $document = (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['size_bytes' => $sizeBytes]));
    $document->save();

    return $document;
}

it('refuses a submission whose kind, customer type or form key is not allowed', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'a kind that is not allowed' => [fn () => kycRawSubmission(['kind' => 'refund']), 'A KYC submission is an initial submission or a correction.'],
    'a customer type that is not allowed' => [fn () => kycRawSubmission(['user_type' => 'admin']),
        'A KYC submission records the customer type it was sent under.'],
    'a form key that is not a UUID' => [fn () => kycRawSubmission(['form_key' => 'not-a-uuid']), 'A KYC submission keeps its one-time form key (a UUID).'],
]);

it('refuses a value the enum cast cannot read, before any row is built', function (Closure $assign) {
    expect($assign)->toThrow(ValueError::class, 'is not a valid backing value');
})->with([
    'a kind outside the two allowed' => [fn () => (new KycSubmission)->forceFill(['kind' => 'refund'])],
    'a customer type outside the allowed ones' => [fn () => (new KycSubmission)->forceFill(['user_type' => 'admin'])],
    'an audit event outside the trail' => [fn () => (new KycAuditEvent)->forceFill(['event' => 'submission.deleted'])],
]);

it('refuses a requirement snapshot that does not match its requirement, its applicability or its name', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'another requirement type' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id,
            'kyc_requirement_id' => KycRequirement::where('key', 'bvn')->value('id'), 'requirement_key' => 'bvn',
            'requirement_type' => 'nin', 'label' => 'BVN', 'phone_fingerprint' => null])->save();
    }, 'A KYC requirement snapshot keeps the key and type of its requirement.'],
    'a requirement that was switched off' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id,
            'kyc_requirement_id' => KycRequirement::where('key', 'document')->value('id'), 'requirement_key' => 'document',
            'requirement_type' => 'document', 'label' => 'Provider document', 'phone_fingerprint' => null])->save();
    }, 'A KYC requirement snapshot holds only the requirements that applied when the submission was sent.'],
    'a name the requirement did not have' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id,
            'kyc_requirement_id' => KycRequirement::where('key', 'bvn')->value('id'), 'requirement_key' => 'bvn',
            'requirement_type' => 'bvn', 'label' => 'Something else', 'phone_fingerprint' => null])->save();
    }, 'A KYC requirement snapshot keeps the name its requirement had when the submission was sent.'],
    'a phone requirement without its fingerprint' => [function () {
        kycEnable('phone');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id,
            'kyc_requirement_id' => KycRequirement::where('key', 'phone')->value('id'), 'requirement_key' => 'phone',
            'requirement_type' => 'phone', 'label' => 'Phone number', 'phone_fingerprint' => null])->save();
    }, 'A phone requirement keeps a fingerprint of the phone number it was sent with.'],
    'a fingerprint on a non-phone requirement' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionRequirement)->forceFill(['kyc_submission_id' => $submission->id,
            'kyc_requirement_id' => KycRequirement::where('key', 'bvn')->value('id'), 'requirement_key' => 'bvn',
            'requirement_type' => 'bvn', 'label' => 'BVN', 'phone_fingerprint' => str_repeat('a', 64)])->save();
    }, 'Only a phone requirement keeps a phone fingerprint.'],
]);

it('refuses an identity value that is not a BVN or NIN, is not asked for, or does not match its own number, mask or hash', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'a key that is not an identity' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => 'phone',
            'ciphertext' => KYC_TEST_BVN, 'masked_value' => '•••••••2222', 'lookup_hash' => str_repeat('0', 64)])->save();
    }, 'A KYC identity value is a BVN or a NIN.'],
    'a value its submission did not ask for' => [function () {
        kycEnable('bvn');
        $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);

        return (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => 'nin',
            'ciphertext' => KYC_TEST_NIN, 'masked_value' => '•••••••3333', 'lookup_hash' => str_repeat('0', 64)])->save();
    }, 'A KYC identity value belongs to a requirement its submission asked for.'],
    'a value that is not 11 digits' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => 'bvn',
            'ciphertext' => '1234', 'masked_value' => '•••••••1234', 'lookup_hash' => str_repeat('0', 64)])->save();
    }, 'A KYC identity value is exactly 11 digits.'],
    'a mask that does not match the number' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => 'bvn',
            'ciphertext' => KYC_TEST_BVN, 'masked_value' => '•••••••9999', 'lookup_hash' => str_repeat('0', 64)])->save();
    }, 'A KYC identity value keeps its mask.'],
    'a lookup hash that does not match the number' => [function () {
        $submission = kycRuleSubmission();

        return (new KycSubmissionIdentity)->forceFill(['kyc_submission_id' => $submission->id, 'requirement_key' => 'bvn',
            'ciphertext' => KYC_TEST_BVN, 'masked_value' => '•••••••2222', 'lookup_hash' => str_repeat('0', 64)])->save();
    }, 'A KYC identity value keeps its keyed lookup hash.'],
]);

it('refuses a decision that is not a decision, a reason that breaks the rules, a decision by a disabled reviewer, or a second decision for one submission', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'a state that is not a decision' => [function () {
        $submission = kycRuleSubmission();

        return (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'pending_review', 'reason' => null,
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
    }, 'A KYC review is an approval, a rejection or a request for more information.'],
    'an approval with a reason over 500 characters' => [function () {
        $submission = kycRuleSubmission();

        return (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => str_repeat('a', 501),
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
    }, 'An approval keeps an optional reason of up to 500 characters.'],
    'a rejection with a short reason' => [function () {
        $submission = kycRuleSubmission();

        return (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected', 'reason' => 'too short',
            'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
    }, 'A rejection or a request for more information needs a reason of 10 to 500 characters with at least one letter.'],
    'a decision by a disabled staff member' => [function () {
        $submission = kycRuleSubmission();
        $staff = kycStaff(SystemRole::Manager);
        $staff->forceFill(['status' => UserStatus::Disabled])->save();

        return (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
            'reason' => 'The photo is blurred.', 'reviewed_by' => $staff->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
    }, 'A KYC review is decided by an active staff member.'],
    'a second decision for one submission' => [function () {
        $submission = kycRuleSubmission();
        $staff = kycStaff(SystemRole::Manager);
        kycDecide($submission, $staff, KycStatus::Approved);

        return (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved', 'reason' => null,
            'reviewed_by' => $staff->id, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
    }, 'A KYC submission has one decision, ever.'],
]);

it('refuses an open slot that names another customer, or a submission that is already decided', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'another customer' => [function () {
        $submission = kycRuleSubmission();

        return (new KycOpenSubmission)->forceFill(['user_id' => kycCustomer()->id, 'kyc_submission_id' => $submission->id])->save();
    }, 'An open KYC submission belongs to its customer.'],
    'a decided submission' => [function () {
        $submission = kycRuleSubmission();
        kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The photo is blurred.');

        return (new KycOpenSubmission)->forceFill(['user_id' => $submission->user_id, 'kyc_submission_id' => $submission->id])->save();
    }, 'A decided KYC submission is not open.'],
]);

it('refuses a document that does not answer a document requirement of its submission, or that is not a permitted file', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'a requirement that is not the document one' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['requirement_key' => 'bvn']))->save();
    }, 'A KYC document answers the provider document requirement.'],
    'a submission without a document requirement' => [function () {
        $submission = kycRuleSubmission();

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id))->save();
    }, 'A KYC document belongs to a document requirement its submission asked for.'],
    'a type that is not allowed' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['mime' => 'text/plain']))->save();
    }, 'A KYC document is a PDF, a JPEG or a PNG.'],
    'a stored name with another extension' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['storage_name' => strtoupper((string) Str::ulid()).'.jpg']))->save();
    }, 'A KYC document is stored under a server-generated name with its own extension.'],
    'an empty file' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['size_bytes' => 0]))->save();
    }, 'A KYC document is between 1 byte and 5 MiB.'],
    'a file over 5 MiB' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['size_bytes' => 5_242_881]))->save();
    }, 'A KYC document is between 1 byte and 5 MiB.'],
    'a checksum that is not lower-case hexadecimal' => [function () {
        kycEnable('document');
        $submission = kycOpen(kycCustomer(), []);

        return (new KycDocument)->forceFill(kycDocumentAttributes($submission->id, ['sha256' => str_repeat('D', 64)]))->save();
    }, 'A KYC document records the SHA-256 of its content.'],
]);

it('refuses an audit event that breaks its event rules', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'an event outside the trail' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->setRawAttributes(['event' => 'submission.deleted', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => null,
            'metadata' => json_encode(['kind' => 'initial', 'requirement_count' => 2])])->save();
    }, 'A KYC audit event is one of the events the trail records.'],
    'metadata outside the allowed keys' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->forceFill(['event' => 'submission.opened', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => null,
            'metadata' => ['kind' => 'initial', 'requirement_count' => 2, 'note' => 'extra']])->save();
    }, 'A KYC audit event holds exactly the metadata its kind allows.'],
    'a customer event that names a staff member' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->forceFill(['event' => 'submission.opened', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => kycStaff()->id,
            'metadata' => ['kind' => 'initial', 'requirement_count' => 2]])->save();
    }, 'A customer event names no staff member.'],
    'a staff action with no staff member' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->forceFill(['event' => 'review.decided', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => null, 'metadata' => ['decision' => 'approved']])->save();
    }, 'A staff action names the staff member who took it.'],
    'a document event with no document' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->forceFill(['event' => 'document.accessed', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'kyc_document_id' => null, 'system_user_id' => kycStaff(SystemRole::Manager)->id,
            'metadata' => ['action' => 'view']])->save();
    }, 'A KYC audit event names a document exactly when it concerns one.'],
    'a submission of another customer' => [function () {
        $submission = kycRuleSubmission();

        return (new KycAuditEvent)->forceFill(['event' => 'submission.opened', 'user_id' => kycCustomer()->id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => null,
            'metadata' => ['kind' => 'initial', 'requirement_count' => 2]])->save();
    }, 'A KYC audit event about a submission concerns its own customer.'],
]);

it('never puts an identity number in an audit refusal, even when the number is in the metadata', function () {
    $submission = kycRuleSubmission();
    $refusal = null;

    try {
        (new KycAuditEvent)->forceFill(['event' => 'submission.opened', 'user_id' => $submission->user_id,
            'kyc_submission_id' => $submission->id, 'system_user_id' => null,
            'metadata' => ['kind' => KYC_TEST_BVN, 'requirement_count' => 2]])->save();
    } catch (LogicException $e) {
        $refusal = $e->getMessage();
    }

    expect($refusal)->not->toBeNull();
    expect(str_contains((string) $refusal, KYC_TEST_BVN))->toBeFalse();
});

it('refuses a requirement_count that differs from the requirement rows its submission holds', function (Closure $write, string $message) {
    expect($write)->toThrow(LogicException::class, $message);
})->with([
    'one row fewer' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(), ['kind' => 'initial', 'requirement_count' => 1]),
        "A KYC audit event's requirement_count is the number of requirement rows its submission holds."],
    'one row more' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(), ['kind' => 'initial', 'requirement_count' => 3]),
        "A KYC audit event's requirement_count is the number of requirement rows its submission holds."],
    'a submission with no requirement rows' => [fn () => kycAuditEvent('submission.opened', kycRawSubmission(), ['kind' => 'initial', 'requirement_count' => 0]),
        'A KYC audit event about a submission needs a requirement row to check its count against.'],
]);

it('refuses a requirement_count that is a string, a negative number or a fraction', function (Closure $write) {
    expect($write)->toThrow(LogicException::class, "A KYC audit event's requirement_count is a whole number.");
})->with([
    'a string' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(), ['kind' => 'initial', 'requirement_count' => '2'])],
    'a negative number' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(), ['kind' => 'initial', 'requirement_count' => -1])],
    'a fraction' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(), ['kind' => 'initial', 'requirement_count' => 2.5])],
]);

it('refuses a size_bytes that differs from the stored size of its document', function (Closure $write) {
    expect($write)->toThrow(LogicException::class, "A KYC audit event's size_bytes is the stored size of its document.");
})->with([
    'one byte more' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => 2049], $document);
    }],
    'one byte fewer' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => 2047], $document);
    }],
]);

it('refuses a size_bytes that is a string, a negative number or a fraction', function (Closure $write) {
    expect($write)->toThrow(LogicException::class, "A KYC audit event's size_bytes is a whole number.");
})->with([
    'a string' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => '2048'], $document);
    }],
    'a negative number' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => -1], $document);
    }],
    'a fraction' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => 2048.5], $document);
    }],
]);

it('names no identity number when it refuses a count or size that holds one', function (Closure $write, string $message) {
    $refusal = null;

    try {
        $write();
    } catch (LogicException $e) {
        $refusal = $e->getMessage();
    }

    expect($refusal)->toBe($message);
    expect(str_contains((string) $refusal, KYC_TEST_BVN))->toBeFalse();
})->with([
    'the BVN as requirement_count' => [fn () => kycAuditEvent('submission.opened', kycRuleSubmission(),
        ['kind' => 'initial', 'requirement_count' => (int) KYC_TEST_BVN]),
        "A KYC audit event's requirement_count is the number of requirement rows its submission holds."],
    'the BVN as size_bytes' => [function () {
        $document = kycStoredDocument();

        return kycAuditEvent('document.registered', $document->submission,
            ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => (int) KYC_TEST_BVN], $document);
    }, "A KYC audit event's size_bytes is the stored size of its document."],
]);

it('accepts a requirement_count that equals the requirement rows its submission holds', function () {
    $submission = kycRuleSubmission();
    $opened = KycAuditEvent::where('event', 'submission.opened')->where('kyc_submission_id', $submission->id)->sole();

    expect($opened->metadata)->toBe(['kind' => 'initial', 'requirement_count' => 2])
        ->and(KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->count())->toBe(2);
});

it('accepts a size_bytes that equals the stored size of its document', function () {
    $document = kycStoredDocument();
    $size = kycAuditEvent('document.registered', $document->submission,
        ['requirement_key' => 'document', 'mime' => 'application/pdf', 'size_bytes' => 2048], $document);

    expect($size->exists)->toBeTrue()
        ->and($size->metadata['size_bytes'])->toBe($document->size_bytes);
});

it('refuses to change or delete an existing submission or decision', function () {
    $submission = kycRuleSubmission();
    $review = kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    expect(fn () => $submission->forceFill(['reference' => 'KYC-'.strtoupper((string) Str::ulid())])->save())->toThrow(LogicException::class);
    expect(fn () => $submission->delete())->toThrow(LogicException::class);
    expect(fn () => $review->forceFill(['reason' => 'Changed after the fact'])->save())->toThrow(LogicException::class);
    expect(fn () => $review->delete())->toThrow(LogicException::class);
    expect(KycAuditEvent::query()->count())->toBeGreaterThan(0);
    expect(User::query()->whereKey($submission->user_id)->exists())->toBeTrue();
});
