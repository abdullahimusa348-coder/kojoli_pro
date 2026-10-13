<?php

use App\Exceptions\Kyc\KycException;
use App\Models\KycReview;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycSelfReview;
use App\Support\Kyc\KycStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a self-review by contact details. A reviewer who holds the customer's email, or the customer's phone number once both
 * are normalized the way the app stores phone numbers, cannot decide that customer's submission. Blank contact details never match.
 * The ledger and the review model both apply the rule. Synthetic details only.
 */

beforeEach(fn () => kycSeed());

/** A customer and a staff member whose contact details are written directly, so blank or unnormalized values can be tested. */
function kycContactPair(array $customer, array $staff): array
{
    $user = kycCustomer();
    DB::table('users')->where('id', $user->id)->update($customer);
    $reviewer = kycStaff(SystemRole::Manager);
    DB::table('system_users')->where('id', $reviewer->id)->update($staff);

    return [User::findOrFail($user->id), SystemUser::findOrFail($reviewer->id)];
}

it('does not treat two blank emails as one person', function () {
    [$customer, $reviewer] = kycContactPair(['email' => '', 'phone' => null], ['email' => '', 'phone' => null]);

    expect(KycSelfReview::sameContact($customer, $reviewer))->toBeFalse();
});

it('does not treat a blank email as a match for a real one', function () {
    [$customer, $reviewer] = kycContactPair(['email' => '   ', 'phone' => null], ['email' => 'staff@example.test', 'phone' => null]);

    expect(KycSelfReview::sameContact($customer, $reviewer))->toBeFalse();
});

it('matches the same email in another letter case and with surrounding spaces', function () {
    [$customer, $reviewer] = kycContactPair(['email' => 'Customer@Example.test', 'phone' => null],
        ['email' => '  customer@example.test ', 'phone' => null]);

    expect(KycSelfReview::sameContact($customer, $reviewer))->toBeTrue();
});

it('matches a phone number written in another form, as the app normalizes phone numbers', function () {
    [$customer, $reviewer] = kycContactPair(['email' => 'a@example.test', 'phone' => '08012345678'],
        ['email' => 'b@example.test', 'phone' => '+234 801 234 5678']);

    expect(KycSelfReview::sameContact($customer, $reviewer))->toBeTrue();
});

it('treats distinct contacts as distinct people', function () {
    [$customer, $reviewer] = kycContactPair(['email' => 'a@example.test', 'phone' => '08012345678'],
        ['email' => 'b@example.test', 'phone' => '08087654321']);

    expect(KycSelfReview::sameContact($customer, $reviewer))->toBeFalse();
});

it('refuses a decision by a reviewer who holds the customer\'s phone number in another form', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    $reviewer = kycStaff(SystemRole::Manager);
    DB::table('system_users')->where('id', $reviewer->id)->update(['phone' => '+234'.substr((string) $customer->phone, 1)]);

    expect(fn () => kycDecide($submission, SystemUser::findOrFail($reviewer->id), KycStatus::Rejected, 'The document is not the one requested.'))
        ->toThrow(KycException::class, 'This staff account cannot decide this submission.');
    expect(KycReview::count())->toBe(0);
});

it('refuses a direct review written by a reviewer who holds the customer\'s email', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    $reviewer = kycStaff(SystemRole::Manager);
    DB::table('system_users')->where('id', $reviewer->id)->update(['email' => $customer->email]);

    expect(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => 'The document is not the one requested.', 'reviewed_by' => $reviewer->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save())
        ->toThrow(LogicException::class, 'A KYC review cannot be decided by the staff member who holds the same contact details as the customer.');
    expect(KycReview::count())->toBe(0);
});

it('refuses a direct review written by a staff member without the review permission', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $submission = kycOpen(kycCustomer());
    $viewer = kycRoleStaff(['kyc.view']);

    expect(fn () => (new KycReview)->forceFill(['kyc_submission_id' => $submission->id, 'decision' => 'rejected',
        'reason' => 'The document is not the one requested.', 'reviewed_by' => $viewer->id, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()])->save())
        ->toThrow(LogicException::class, 'A KYC review is decided by a staff member who may review KYC submissions.');
    expect(KycReview::count())->toBe(0);
});
