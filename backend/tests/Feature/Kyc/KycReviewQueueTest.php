<?php

use App\Actions\Admin\Kyc\DecideKycSubmission;
use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycProfile;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2b-1: the staff KYC review queue at /admin/kyc. kyc.view lists the open submissions and opens one; kyc.review records a
 * decision, through DecideKycSubmission and the ledger. These tests cover the access matrix on every route and in the action, the queue
 * and a submission's page (requirements, masked identity values, the phone check, the decision form), the customers.view rule for names
 * and emails, the three decisions and their refusals, form keys (missing, invalid, repeated, reused), the reason rules, the throttle, the
 * sidebar, and that no full identity value reaches a page or the session. Identity numbers here are synthetic.
 */

beforeEach(fn () => kycSeed());

dataset('kq access', [
    'super admin' => [SystemRole::SuperAdmin, 200, 200, 302],
    'manager' => [SystemRole::Manager, 200, 200, 302],
    'support' => [SystemRole::Support, 200, 200, 403],
    'finance' => [SystemRole::Finance, 403, 403, 403],
    'viewer' => [SystemRole::Viewer, 403, 403, 403],
]);

dataset('kq decisions', [
    'approved' => [KycStatus::Approved, KycStatus::Approved, '{ref} approved.'],
    'rejected' => [KycStatus::Rejected, KycStatus::Rejected, '{ref} rejected.'],
    'more information requested' => [KycStatus::MoreInfoRequested, KycStatus::MoreInfoRequested, 'More information requested for {ref}.'],
]);

dataset('kq bad form keys', [
    'missing' => [null],
    'not a UUID' => ['not-a-uuid'],
    'UUID-shaped, not a UUID' => ['zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz'],
]);

dataset('kq unconfirmed', [
    'no confirmation sent' => [null],
    'checkbox left unticked' => ['0'],
    'a value that is not an acceptance' => ['no'],
]);

dataset('kq non-text reasons', [
    'a list' => [['please', 'approve']],
    'a number' => [12345],
    'a nested list' => [[['nested']]],
]);

dataset('kq referers', [
    'a foreign Referer' => ['https://evil.example/fake-review'],
    'no Referer' => [null],
]);

/** BVN and NIN requirements switched on, and one submission sent by a new customer (run kycSeed first). */
function kqBvnNinSubmission(): KycSubmission
{
    kycEnable('bvn');
    kycEnable('nin');

    return kycOpen(kycCustomer());
}

/** The one-time form key in a submission page's decision form. */
function kqFormKey(string $html): string
{
    preg_match('/name="form_key" value="([^"]+)"/', $html, $match);

    return $match[1] ?? '';
}

/** A decision as the form sends it, ticked to confirm, with a fresh form key unless one is given. */
function kqDecision(KycStatus $decision, ?string $reason = null, ?string $formKey = null): array
{
    return ['decision' => $decision->value, 'decision_reason' => $reason, 'form_key' => $formKey ?? (string) Str::uuid(), 'confirm' => '1'];
}

/** The session, serialised, so a test can look for an identity number anywhere in it. */
function kqSession(): string
{
    return serialize(session()->all());
}

it('opens the queue and a submission with kyc.view, and records a decision only with kyc.review', function (SystemRole $role, int $queue, int $page, int $decide) {
    $submission = kqBvnNinSubmission();
    $this->actingAs(kycStaff($role), 'admin');

    $this->get(route('admin.kyc.index'))->assertStatus($queue);
    $this->get(route('admin.kyc.submissions.show', $submission))->assertStatus($page);
    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Rejected, 'The NIN photo is not clear enough.'))
        ->assertStatus($decide);

    expect(KycReview::count())->toBe($decide === 302 ? 1 : 0);
})->with('kq access');

it('lists only the open submissions, oldest first, with their requirements and no identity value', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $first = kycOpen(kycCustomer());
    $decided = kycOpen(kycCustomer());
    kycDecide($decided, kycStaff(SystemRole::Manager), KycStatus::Approved);
    $second = kycOpen(kycCustomer());

    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get(route('admin.kyc.index'))
        ->assertOk()
        ->assertSeeInOrder([$first->reference, $second->reference])
        ->assertSee('BVN')
        ->assertDontSee($decided->reference)
        ->assertDontSee(KYC_TEST_BVN)
        ->assertDontSee(KYC_TEST_NIN);
});

it('says so when nothing is waiting for a decision', function () {
    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get(route('admin.kyc.index'))
        ->assertOk()
        ->assertSee('No submissions are waiting for a decision.');
});

it('shows a submission\'s requirements with masked identity values, its phone check and the decision form, and never caches it', function () {
    kycEnable('phone');
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $response = $this->get($page)->assertOk()
        ->assertSee($submission->reference)
        ->assertSee('Phone number')
        ->assertSee('•••••••2222')
        ->assertSee('data-kyc-phone="matches"', false)
        ->assertSee('name="decision" value="approved"', false)
        ->assertSee('name="decision" value="rejected"', false)
        ->assertSee('name="decision" value="more_info_requested"', false)
        ->assertDontSee(KYC_TEST_BVN);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    DB::table('users')->where('id', $customer->id)->update(['phone' => '08099990000']);

    $this->get($page)->assertOk()
        ->assertSee('data-kyc-phone="changed"', false)
        ->assertDontSee('data-kyc-phone="matches"', false);
});

it('says so when no phone number was asked for', function () {
    $submission = kqBvnNinSubmission();

    $this->actingAs(kycStaff(SystemRole::Manager), 'admin')->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertSee('data-kyc-phone="not-requested"', false);
});

it('shows a customer\'s name and email only to staff with customers.view, and Customer #id to everyone else', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    $customer->forceFill(['name' => 'Ngozi Queue', 'email' => 'ngozi.queue@example.com'])->save();
    $submission = kycOpen($customer);
    $withoutCustomers = kycRoleStaff(['admin.access', 'kyc.view', 'kyc.review']);

    $this->actingAs($withoutCustomers, 'admin')->get(route('admin.kyc.index'))
        ->assertOk()
        ->assertSee('Customer #'.$customer->id)
        ->assertDontSee('Ngozi Queue')
        ->assertDontSee('ngozi.queue@example.com');
    $this->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertSee('Customer #'.$customer->id)
        ->assertDontSee('Ngozi Queue')
        ->assertDontSee('ngozi.queue@example.com');

    $this->actingAs(kycStaff(SystemRole::Manager), 'admin')->get(route('admin.kyc.index'))
        ->assertOk()
        ->assertSee('Ngozi Queue')
        ->assertSee('ngozi.queue@example.com');
});

it('lets kyc.view staff open a submission, but shows no decision form to them', function () {
    $submission = kqBvnNinSubmission();

    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertSee($submission->reference)
        ->assertSee('Only staff with permission to decide KYC submissions can record a decision here.')
        ->assertDontSee('name="form_key"', false)
        ->assertDontSee('name="decision"', false);
});

it('records each decision through the ledger, sets the customer status, frees the open slot and writes one audit event', function (KycStatus $decision, KycStatus $status, string $message) {
    $submission = kqBvnNinSubmission();
    $staff = kycStaff(SystemRole::Manager);
    $this->actingAs($staff, 'admin');
    $html = $this->get(route('admin.kyc.submissions.show', $submission))->assertOk()->getContent();

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision($decision, 'Please check the NIN slip again', kqFormKey($html)))
        ->assertRedirect(route('admin.kyc.index'))
        ->assertSessionHas('status', str_replace('{ref}', $submission->reference, $message));

    $review = KycReview::sole();
    expect([$review->kyc_submission_id, $review->decision, $review->reviewed_by])->toBe([$submission->id, $decision, $staff->id])
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe($status)
        ->and(KycOpenSubmission::whereKey($submission->user_id)->exists())->toBeFalse()
        ->and(KycAuditEvent::where('event', 'review.decided')->sole()->system_user_id)->toBe($staff->id);
})->with('kq decisions');

it('approves with no reason, which the ledger stores as none', function () {
    $submission = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Approved))
        ->assertRedirect(route('admin.kyc.index'));

    expect(KycReview::sole()->reason)->toBeNull()
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe(KycStatus::Approved);
});

it('refuses a rejection whose reason is too short, names the rule, and records nothing', function () {
    $submission = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Rejected, 'No.'))
        ->assertRedirect(route('admin.kyc.submissions.show', $submission))
        ->assertSessionHasErrors(['refusal' => 'Enter a reason of 10 to 500 characters that includes at least one letter.']);

    expect(KycReview::count())->toBe(0)
        ->and(KycOpenSubmission::whereKey($submission->user_id)->exists())->toBeTrue()
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe(KycStatus::PendingReview);
});

it('refuses an approval for a submission that asks for a phone number, names the item, and records nothing', function () {
    kycEnable('phone');
    kycEnable('bvn');
    $submission = kycOpen(kycCustomer(), ['bvn' => KYC_TEST_BVN]);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Approved))
        ->assertRedirect(route('admin.kyc.submissions.show', $submission))
        ->assertSessionHasErrors(['refusal' => 'Phone number cannot be completed in this version, so this submission cannot be approved yet.']);

    expect(KycReview::count())->toBe(0);
});

it('refuses an approval once the phone number has changed, and records nothing', function () {
    kycEnable('phone');
    kycEnable('bvn');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN]);
    DB::table('users')->where('id', $customer->id)->update(['phone' => '08099990000']);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Approved))
        ->assertRedirect(route('admin.kyc.submissions.show', $submission))
        ->assertSessionHasErrors(['refusal' => 'This submission changed while you were reviewing it. Reload the page and try again.']);

    expect(KycReview::count())->toBe(0);
});

it('refuses a reason that holds an identity number, never repeats it, and keeps it out of the session and the page', function () {
    $submission = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Rejected, 'The BVN '.KYC_TEST_BVN.' does not match'))
        ->assertRedirect(route('admin.kyc.submissions.show', $submission))
        ->assertSessionHasErrors(['refusal' => 'A reason cannot contain an 11-digit number, such as a BVN, NIN or phone number. Remove the number and try again.']);

    // The refusal is in the session (so the check below looks at a real session), and the number is not.
    expect(kqSession())->toContain('11-digit number')
        ->and(kqSession())->not->toContain(KYC_TEST_BVN)
        ->and(KycReview::count())->toBe(0);
    $this->get(route('admin.kyc.submissions.show', $submission))->assertOk()->assertDontSee(KYC_TEST_BVN);
});

it('never flashes a typed reason back, even when the form fails validation', function () {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), ['decision' => 'bogus', 'decision_reason' => 'Card BVN '.KYC_TEST_BVN.' attached', 'form_key' => (string) Str::uuid(), 'confirm' => '1'])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['decision' => 'Choose one of the decisions shown.']);

    // The validation error is in the session (so the check below looks at a real session), and the typed number is not.
    expect(kqSession())->toContain('Choose one of the decisions shown.')
        ->and(kqSession())->not->toContain(KYC_TEST_BVN)
        ->and(KycReview::count())->toBe(0);
});

it('refuses a status that is not a decision, and records nothing', function () {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), ['decision' => KycStatus::PendingReview->value, 'form_key' => (string) Str::uuid(), 'confirm' => '1'])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['decision' => 'Choose one of the decisions shown.']);

    expect(KycReview::count())->toBe(0);
});

it('refuses a decision whose form key is missing or not a UUID, and records nothing', function (?string $formKey) {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $data = ['decision' => KycStatus::Approved->value, 'confirm' => '1'];
    if ($formKey !== null) {
        $data['form_key'] = $formKey;
    }
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), $data)
        ->assertRedirect($page)
        ->assertSessionHasErrors(['form_key' => 'This form is not valid. Reload the page and try again.']);

    expect(KycReview::count())->toBe(0);
})->with('kq bad form keys');

it('treats a repeated form as the decision it made, and refuses the same form key on another submission', function () {
    $first = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    $key = (string) Str::uuid();

    $this->post(route('admin.kyc.submissions.decide', $first), kqDecision(KycStatus::Approved, null, $key))
        ->assertRedirect(route('admin.kyc.index'))
        ->assertSessionHas('status', "{$first->reference} approved.");
    $this->post(route('admin.kyc.submissions.decide', $first), kqDecision(KycStatus::Approved, null, $key))
        ->assertRedirect(route('admin.kyc.index'))
        ->assertSessionHas('status', "{$first->reference} approved.");

    expect(KycReview::count())->toBe(1)
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(1);

    $second = kqBvnNinSubmission();
    $this->post(route('admin.kyc.submissions.decide', $second), kqDecision(KycStatus::Approved, null, $key))
        ->assertRedirect(route('admin.kyc.submissions.show', $second))
        ->assertSessionHasErrors(['refusal' => 'This form was already used for another request. Reload the page and try again.']);

    expect(KycReview::count())->toBe(1);
});

it('refuses a second decision made with a new form key, and says the submission is already decided', function () {
    $submission = kqBvnNinSubmission();
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $submission), kqDecision(KycStatus::Rejected, 'Changed my mind about this one'))
        ->assertRedirect(route('admin.kyc.submissions.show', $submission))
        ->assertSessionHasErrors(['refusal' => 'This submission has already been decided. Reload the page to see its status.']);

    expect(KycReview::count())->toBe(1)
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe(KycStatus::Approved);
});

it('shows a decided submission as no longer waiting, with no decision form', function () {
    $submission = kqBvnNinSubmission();
    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Approved);

    $this->actingAs(kycStaff(SystemRole::Manager), 'admin')->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertSee('This submission is no longer waiting for review.')
        ->assertSee('Decision: Approved.')
        ->assertDontSee('name="form_key"', false);
});

it('refuses a direct decision made through the action by staff without kyc.review, and writes nothing', function (SystemRole $role) {
    $submission = kqBvnNinSubmission();

    expect(fn () => app(DecideKycSubmission::class)->handle($submission, kycStaff($role), KycStatus::Approved, null, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    expect(KycReview::count())->toBe(0);
})->with([SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

it('refuses a direct decision from disabled staff, even with kyc.review, and writes nothing', function () {
    $submission = kqBvnNinSubmission();
    $staff = kycStaff(SystemRole::Manager);
    $staff->forceFill(['status' => 'disabled'])->save();

    expect(fn () => app(DecideKycSubmission::class)->handle($submission, $staff, KycStatus::Approved, null, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    expect(KycReview::count())->toBe(0);
});

it('throttles decisions to 30 a minute', function () {
    $submission = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->post(route('admin.kyc.submissions.decide', $submission), ['decision' => 'bogus'])->assertRedirect();
    }

    $this->post(route('admin.kyc.submissions.decide', $submission), ['decision' => 'bogus'])->assertStatus(429);
    expect(KycReview::count())->toBe(0);
});

it('shows the review queue in the sidebar beside the requirements item, only to staff who may open it', function () {
    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get('/admin')->assertOk()
        ->assertSee('data-nav="kyc-review"', false)
        ->assertSee('href="'.route('admin.kyc.index').'"', false)
        ->assertSee('data-nav="kyc"', false);

    $this->actingAs(kycStaff(SystemRole::Finance), 'admin')->get('/admin')->assertOk()
        ->assertDontSee('data-nav="kyc-review"', false);
});

it('refuses a decision that is not confirmed, names the rule, and records nothing', function (?string $confirm) {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $data = kqDecision(KycStatus::Approved, 'Please check the NIN slip again');
    if ($confirm === null) {
        unset($data['confirm']);
    } else {
        $data['confirm'] = $confirm;
    }
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), $data)
        ->assertRedirect($page)
        ->assertSessionHasErrors(['confirm' => 'Confirm this decision before recording it.']);

    expect(KycReview::count())->toBe(0)
        ->and(KycOpenSubmission::whereKey($submission->user_id)->exists())->toBeTrue()
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe(KycStatus::PendingReview);
})->with('kq unconfirmed');

it('puts a confirmation checkbox on the decision form, and not on the page of staff who cannot decide', function () {
    $submission = kqBvnNinSubmission();

    $this->actingAs(kycStaff(SystemRole::Manager), 'admin')->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertSee('name="confirm" value="1"', false)
        ->assertSee('I confirm: record this decision. It cannot be changed.');

    $this->actingAs(kycStaff(SystemRole::Support), 'admin')->get(route('admin.kyc.submissions.show', $submission))
        ->assertOk()
        ->assertDontSee('name="confirm"', false);
});

it('records a decision the form sends with its confirmation ticked', function () {
    $submission = kqBvnNinSubmission();
    $staff = kycStaff(SystemRole::Manager);
    $html = $this->actingAs($staff, 'admin')->get(route('admin.kyc.submissions.show', $submission))->assertOk()->getContent();

    $this->post(route('admin.kyc.submissions.decide', $submission), [
        'decision' => KycStatus::Rejected->value,
        'decision_reason' => 'The NIN photo is not clear enough.',
        'form_key' => kqFormKey($html),
        'confirm' => '1',
    ])
        ->assertRedirect(route('admin.kyc.index'))
        ->assertSessionHas('status', "{$submission->reference} rejected.");

    expect(KycReview::sole()->decision)->toBe(KycStatus::Rejected);
});

it('refuses a decision reason that is not text, and records nothing', function (mixed $reason) {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), [
            'decision' => KycStatus::Approved->value,
            'decision_reason' => $reason,
            'form_key' => (string) Str::uuid(),
            'confirm' => '1',
        ])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['decision_reason' => 'Enter the reason as text.']);

    expect(KycReview::count())->toBe(0)
        ->and(KycProfile::where('user_id', $submission->user_id)->sole()->status)->toBe(KycStatus::PendingReview);
})->with('kq non-text reasons');

it('treats a blank reason as no reason: an approval records none, and a rejection is refused by the ledger rule', function () {
    $approved = kqBvnNinSubmission();
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->post(route('admin.kyc.submissions.decide', $approved), kqDecision(KycStatus::Approved, '   '))
        ->assertRedirect(route('admin.kyc.index'));
    expect(KycReview::sole()->reason)->toBeNull();

    $rejected = kqBvnNinSubmission();
    $this->post(route('admin.kyc.submissions.decide', $rejected), kqDecision(KycStatus::Rejected, '   '))
        ->assertRedirect(route('admin.kyc.submissions.show', $rejected))
        ->assertSessionHasErrors(['refusal' => 'Enter a reason of 10 to 500 characters that includes at least one letter.']);

    expect(KycReview::count())->toBe(1);
});

it('sends a validation failure back to the submission page, whatever Referer the request carries', function (?string $referer) {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');
    if ($referer !== null) {
        $this->withHeader('Referer', $referer);
    }

    $this->post(route('admin.kyc.submissions.decide', $submission), ['decision' => 'bogus', 'form_key' => (string) Str::uuid(), 'confirm' => '1'])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['decision' => 'Choose one of the decisions shown.']);

    $this->get($page)->assertOk()->assertSee('Choose one of the decisions shown.');
    expect(KycReview::count())->toBe(0);
})->with('kq referers');

it('never flashes a typed reason when the confirmation is missing', function () {
    $submission = kqBvnNinSubmission();
    $page = route('admin.kyc.submissions.show', $submission);
    $this->actingAs(kycStaff(SystemRole::Manager), 'admin');

    $this->from($page)
        ->post(route('admin.kyc.submissions.decide', $submission), [
            'decision' => KycStatus::Rejected->value,
            'decision_reason' => 'Card BVN '.KYC_TEST_BVN.' attached, not clear',
            'form_key' => (string) Str::uuid(),
        ])
        ->assertRedirect($page)
        ->assertSessionHasErrors(['confirm' => 'Confirm this decision before recording it.']);

    // The confirmation error is in the session (so the check below looks at a real session), and the typed number is not.
    expect(kqSession())->toContain('Confirm this decision before recording it.')
        ->and(kqSession())->not->toContain(KYC_TEST_BVN)
        ->and(KycReview::count())->toBe(0);
});
