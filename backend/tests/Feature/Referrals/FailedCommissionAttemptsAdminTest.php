<?php

use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\BusinessTime;
use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 remediation 2 (F2): the admin Failed Commission Attempts tab of the Referral & Commission area (referrals.view).
 * Exactly the four approved fields per attempt: the purchase reference, the referrer's internal customer ID, the reason code
 * and the time. No amount, no name, no email, no phone and no NIN, BVN, PIN or provider value. Read-only: GET only, no action
 * controls, and nothing on the page can change, retry or remove an attempt. Search by purchase reference, 25 a page.
 */

beforeEach(function () {
    cmxDrivers();
});

/** A customer of $referrer's with a name, an email and a phone that the page must never show. */
function fcaBuyer(User $referrer, string $name, string $email, string $phone): User
{
    $buyer = User::factory()->ofType(UserType::Subscriber)->create(['name' => $name, 'email' => $email, 'phone' => $phone]);
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->walletFor($buyer), 1_000_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
    (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id])->save();

    return $buyer;
}

/**
 * A successful $slug purchase by $buyer through PurchaseService. No commission setting exists for the service, so nothing
 * is due and the commission step records nothing. The NIN, BVN and Exam PIN results carry $resultValues, so a test can look
 * for them on the page.
 */
function fcaPurchase(User $buyer, string $slug, int $priceKobo, string $recipient = '', array $resultValues = []): Purchase
{
    $plan = cmxPlan($slug, $priceKobo);
    FakeProvider::$purchaseScript = ['succeeded'];
    FakeProvider::$resultScript = in_array($slug, ['nin', 'bvn', 'exam-pin'], true) ? [FakeProvider::fixtureFields(2, $resultValues)] : [];

    return puxService()->purchase($buyer, $plan, $recipient, null, (string) Str::uuid(), null, in_array($slug, ['nin', 'bvn'], true));
}

/** The failed attempt the commission step records for $purchase, naming $referrer. */
function fcaRecord(Purchase $purchase, User $referrer, CommissionFailureReason $reason): FailedCommissionAttempt
{
    $attempt = (new FailedCommissionAttempt)->forceFill(['purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'reason_code' => $reason]);
    $attempt->save();

    return $attempt;
}

/** How many failed attempt lines the page shows. */
function fcaRows(string $html): int
{
    return substr_count($html, 'data-failed-attempt');
}

it('lists a failed attempt with its purchase reference, internal customer ID, reason code and time, under the tab', function () {
    $referrer = cmxReferrer();
    $attempt = fcaRecord(fcaPurchase(cmxReferred($referrer), 'airtime', 50_000, '08012345678'), $referrer, CommissionFailureReason::WalletRefused);
    $attempt = $attempt->fresh();

    $html = $this->actingAs(cmxStaff(), 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();

    expect(fcaRows($html))->toBe(1)
        ->and($html)->toContain('data-failed-purchase>'.$attempt->purchase->reference.'</a>')
        ->and($html)->toContain('data-failed-referrer>Customer #'.$referrer->id.'</p>')
        ->and($html)->toContain('data-failed-reason>wallet_refused</p>')
        ->and($html)->toContain(CommissionFailureReason::WalletRefused->label())
        ->and($html)->toContain('data-failed-time>'.$attempt->created_at->copy()->setTimezone(BusinessTime::timezone())->format('j M Y, H:i').'</p>')
        ->and($html)->toContain('data-referrals-tab="failed"');
});

it('shows no amount, no name, no email and no phone', function () {
    $referrer = cmxReferrer();
    $referrer->forceFill(['name' => 'Amara Referrer', 'email' => 'amara.referrer@example.test'])->save();
    $buyer = fcaBuyer($referrer, 'Zainab Buyer', 'zainab.buyer@example.test', '08031234567');
    fcaRecord(fcaPurchase($buyer, 'airtime', 123_456, '08031234567'), $referrer, CommissionFailureReason::UnexpectedError);

    $html = $this->actingAs(cmxStaff(), 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();

    expect(fcaRows($html))->toBe(1)
        ->and($html)->not->toContain(Money::format(123_456))
        ->and($html)->not->toContain('1,234.56')
        ->and($html)->not->toContain('123456')
        ->and($html)->not->toContain('₦')
        ->and($html)->not->toContain('Zainab Buyer')
        ->and($html)->not->toContain('zainab.buyer@example.test')
        ->and($html)->not->toContain('08031234567')
        ->and($html)->not->toContain('Amara Referrer')
        ->and($html)->not->toContain('amara.referrer@example.test');
});

it('shows no NIN, BVN, PIN or provider result value', function () {
    $referrer = cmxReferrer();
    fcaRecord(fcaPurchase(cmxReferred($referrer), 'nin', 50_000, '31234567890', ['SECRET-NIN-RESULT-7731', 'SECRET-NIN-RESULT-0042']),
        $referrer, CommissionFailureReason::WalletRefused);
    fcaRecord(fcaPurchase(cmxReferred($referrer), 'exam-pin', 20_000, '', ['SECRET-PIN-4417-9930', 'SECRET-SERIAL-88213']),
        $referrer, CommissionFailureReason::WalletRefused);

    $html = $this->actingAs(cmxStaff(), 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();

    expect(fcaRows($html))->toBe(2)
        ->and($html)->not->toContain('31234567890')
        ->and($html)->not->toContain('SECRET-')
        ->and(preg_match('/\d{11}/', $html))->toBe(0);
});

it('lets referrals.view read the page', function () {
    $referrer = cmxReferrer();
    fcaRecord(fcaPurchase(cmxReferred($referrer), 'airtime', 50_000, '08012345678'), $referrer, CommissionFailureReason::UnexpectedError);
    $viewer = cmxStaffWith(['admin.access', 'referrals.view']);

    $html = $this->actingAs($viewer, 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();

    expect(fcaRows($html))->toBe(1);
});

it('refuses the page to staff without referrals.view', function () {
    $other = cmxStaffWith(['admin.access', 'customers.view']);

    $this->actingAs($other, 'admin')->get(route('admin.referrals.failed'))->assertForbidden();
});

it('gives view-only staff no action controls', function () {
    $referrer = cmxReferrer();
    fcaRecord(fcaPurchase(cmxReferred($referrer), 'airtime', 50_000, '08012345678'), $referrer, CommissionFailureReason::UnexpectedError);
    $viewer = cmxStaffWith(['admin.access', 'referrals.view']);

    $html = $this->actingAs($viewer, 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();

    // Every form that submits by POST must be the admin sign-out form shared by all admin pages: nothing on this page writes.
    preg_match_all('/<form\b[^>]*>/i', $html, $forms);
    $writes = array_filter($forms[0], fn (string $tag) => stripos($tag, 'method="post"') !== false && ! str_contains($tag, 'action="'.route('admin.logout').'"'));

    expect(fcaRows($html))->toBe(1)
        ->and($html)->not->toContain('referrals/commissions/')
        ->and($html)->not->toContain('Reverse commission')
        ->and($html)->not->toContain('Cancel commission')
        ->and($html)->not->toContain('data-reversal-form')
        ->and($html)->not->toContain('data-cancellation-form')
        ->and($writes)->toBe([]);
});

it('pages the list twenty-five to a page, and searches by purchase reference', function () {
    $referrer = cmxReferrer();
    $oldest = null;
    for ($i = 0; $i < 26; $i++) {
        $purchase = fcaPurchase(cmxReferred($referrer), 'airtime', 50_000, '08012345678');
        fcaRecord($purchase, $referrer, CommissionFailureReason::UnexpectedError);
        $oldest ??= $purchase;
    }
    $staff = cmxStaff();

    $first = $this->actingAs($staff, 'admin')->get(route('admin.referrals.failed'))->assertOk()->getContent();
    $second = $this->actingAs($staff, 'admin')->get(route('admin.referrals.failed', ['page' => 2]))->assertOk()->getContent();
    $search = $this->actingAs($staff, 'admin')->get(route('admin.referrals.failed', ['q' => strtolower($oldest->reference)]))->assertOk()->getContent();
    $none = $this->actingAs($staff, 'admin')->get(route('admin.referrals.failed', ['q' => 'PUR-NOTHINGHERE']))->assertOk()->getContent();

    expect(fcaRows($first))->toBe(25)
        ->and(fcaRows($second))->toBe(1)
        ->and($second)->toContain('data-failed-purchase>'.$oldest->reference.'</a>')
        ->and(fcaRows($search))->toBe(1)
        ->and($search)->toContain($oldest->reference)
        ->and(fcaRows($none))->toBe(0)
        ->and($none)->toContain('No failed attempts found');
});

it('cannot be changed from this page: no write reaches an attempt, and reading changes nothing', function () {
    $referrer = cmxReferrer();
    $attempt = fcaRecord(fcaPurchase(cmxReferred($referrer), 'airtime', 50_000, '08012345678'), $referrer, CommissionFailureReason::UnexpectedError);
    $staff = cmxStaff();
    $before = FailedCommissionAttempt::count();

    $this->actingAs($staff, 'admin')->get(route('admin.referrals.failed'))->assertOk();
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs($staff, 'admin')->{$method}(route('admin.referrals.failed'))->assertStatus(405);
    }

    expect(FailedCommissionAttempt::count())->toBe($before)
        ->and($attempt->fresh()->reason_code)->toBe(CommissionFailureReason::UnexpectedError);
});
