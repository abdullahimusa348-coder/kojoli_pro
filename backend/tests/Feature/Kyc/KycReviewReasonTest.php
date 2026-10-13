<?php

use App\Exceptions\Kyc\KycException;
use App\Models\KycAuditEvent;
use App\Models\KycReview;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycStatus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a decision reasons. A reason is shown to the customer and kept permanently, so it may not hold a BVN or NIN written
 * as eleven digits, with or without separators. The refusal names the rule and never repeats the input. Approval and rejection each
 * have their own length message. A reason is trimmed before any rule is checked, as the ledger trims it. Synthetic numbers only.
 */

beforeEach(fn () => kycSeed());

/** The throwable a call raises, or null. Reads a refusal's message without repeating the input. */
function kycReasonRefusal(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('refuses a rejection whose reason holds an identity-like run, keeping neither a review nor an audit event', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, $reason));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe('A reason cannot contain an 11-digit number, such as a BVN, NIN or phone number. Remove the number and try again.')
        ->and(str_contains($refusal->getMessage(), '2222'))->toBeFalse()
        ->and(KycReview::count())->toBe(0)
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(0);
})->with([
    'eleven digits' => ['The number 22222222222 is wrong.'],
    'digits with spaces' => ['The number 2222 2222 222 is wrong.'],
    'digits with hyphens' => ['The number 2222-2222-222 is wrong.'],
    'a phone number, which is also eleven digits' => ['Call 08012345678 to confirm the request.'],
    'digits of another script' => ['The number ٢٢٢٢٢٢٢٢٢٢٢ is wrong.'],
]);

it('refuses an approval whose reason holds an identity-like run', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved, '2222 2222 222'));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe('A reason cannot contain an 11-digit number, such as a BVN, NIN or phone number. Remove the number and try again.')
        ->and(str_contains($refusal->getMessage(), '2222'))->toBeFalse()
        ->and(KycReview::count())->toBe(0);
});

it('accepts ordinary reasons, including short numbers, dates and a ten-digit reference', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    expect(kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, $reason)->decision)->toBe(KycStatus::Rejected);
})->with([
    'plain sentence' => ['The document is not the one requested.'],
    'short number' => ['Please send a clear copy of the front page, 2 pages in total.'],
    'date' => ['The request dated 2026-10-09 does not match our records.'],
    'ten digits' => ['The reference 1234567890 does not match our records.'],
]);

it('refuses a direct model write whose reason holds an identity-like run, leaving no review', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => $reason, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('A KYC review reason cannot contain an 11-digit number such as a BVN, NIN or phone number.')
        ->and(KycReview::count())->toBe(0);
})->with([
    'eleven digits' => ['22222222222'],
    'digits with spaces' => ['2222 2222 222'],
]);

it('gives the approval message for a reason over 500 characters, and the rejection message for a short or long rejection reason', function (string $decision, string $reason, string $message) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::from($decision), $reason));

    expect($refusal)->toBeInstanceOf(KycException::class)
        ->and($refusal->getMessage())->toBe($message)
        ->and(KycReview::count())->toBe(0);
})->with([
    'approval over 500' => ['approved', str_repeat('a', 501), 'An approval reason is up to 500 characters.'],
    'rejection under 10' => ['rejected', str_repeat('a', 9), 'Enter a reason of 10 to 500 characters that includes at least one letter.'],
    'rejection over 500' => ['rejected', str_repeat('a', 501), 'Enter a reason of 10 to 500 characters that includes at least one letter.'],
]);

it('accepts an approval reason of one character and of exactly 500', function (int $length) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    expect(kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved, str_repeat('a', $length))->decision)
        ->toBe(KycStatus::Approved);
})->with([1, 500]);

it('counts a rejection reason after trimming, so padding cannot make a short reason long enough', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => $reason, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('A rejection or a request for more information needs a reason of 10 to 500 characters with at least one letter.')
        ->and(KycReview::count())->toBe(0);
})->with([
    'spaces on both ends' => ['   too short   '],
    'leading spaces only' => ['        short'],
    'trailing spaces only' => ['short        '],
    'tabs and line breaks' => ["\t\n too short \r\n"],
]);

it('stores a rejection reason trimmed, when the trimmed text meets the rules', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => "  \tThe photo is blurred.\n", 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save();

    expect(KycReview::sole()->reason)->toBe('The photo is blurred.');
});

it('stores a blank approval reason as no reason, as the ledger does', function (string $reason) {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => $reason, 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save();

    expect(KycReview::sole()->reason)->toBeNull();
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'tabs and line breaks' => ["\t\n"],
]);

it('counts an approval reason after trimming, so padding does not count toward its 500 characters', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $text = str_repeat('a', 500);

    (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => "  {$text}  ", 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save();

    expect(KycReview::sole()->reason)->toBe($text);
});

it('refuses an approval reason of 501 characters once trimmed, however much padding surrounds it', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'approved',
        'reason' => '  '.str_repeat('a', 501).'  ', 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('An approval keeps an optional reason of up to 500 characters.')
        ->and(KycReview::count())->toBe(0);
});

it('refuses a padded identity-like reason without repeating the digits', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $refusal = kycReasonRefusal(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => "   22222222222\t", 'reviewed_by' => kycStaff(SystemRole::Manager)->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save());

    expect($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toBe('A KYC review reason cannot contain an 11-digit number such as a BVN, NIN or phone number.')
        ->and(str_contains($refusal->getMessage(), '2222'))->toBeFalse()
        ->and(KycReview::count())->toBe(0);
});

it('stores a padded reason trimmed through the ledger, as the review model does', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());

    $review = kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, "  \tThe photo is blurred.\n");

    expect($review->reason)->toBe('The photo is blurred.');
});
