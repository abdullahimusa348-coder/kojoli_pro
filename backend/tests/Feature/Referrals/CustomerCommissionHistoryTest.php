<?php

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Actions\Customers\ChangeUserType;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\Referral;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\Money;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 remediation 2 (F1): the customer commission history on the Referral page. Each line shows only the credit date
 * (Business timezone), the original commission amount and the derived status (Credited, Reversed or Cancelled). A reversed
 * or cancelled commission stays listed at its original amount. Server-side pagination (20 a page), read-only, and nothing
 * that names a buyer, a commission, a purchase, a wallet transaction, a staff member or a reason. The totals still count
 * credited commissions only. Subscribers, Vendors and Affiliates see it; an API User gets a 404.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

/** How many commission lines the Referral page shows. */
function cchRows(string $html): int
{
    return substr_count($html, 'data-commission-row');
}

/** The value shown for the referral figure $key (earned, this-month, ...). */
function cchFigure(string $html, string $key): string
{
    preg_match('/data-referral-figure="'.preg_quote($key, '/').'">\s*<p[^>]*>[^<]*<\/p>\s*<p[^>]*>([^<]*)<\/p>/', $html, $match);

    return $match[1] ?? '';
}

/** A commission of $referrer's: $rateBps on a ₦500 Data purchase by $buyer (a new referred customer when null). */
function cchCommission(User $referrer, int $rateBps = 250, ?User $buyer = null): Commission
{
    cmxSetting('data', $rateBps, 100_000);
    $purchase = cmxBuy($buyer ?? cmxReferred($referrer), cmxPlan('data', 50_000));

    return Commission::where('purchase_id', $purchase->id)->sole();
}

/** A referred customer with a name, an email and a phone that the Referral page must never show. */
function cchBuyer(User $referrer, string $name, string $email, string $phone): User
{
    $buyer = User::factory()->ofType(UserType::Subscriber)->create(['name' => $name, 'email' => $email, 'phone' => $phone]);
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->walletFor($buyer), 1_000_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
    (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id])->save();

    return $buyer;
}

/** Reverses or cancels $commission with $reason, as $staff does from its commission page. */
function cchAct(Commission $commission, CommissionActionType $type, string $reason, SystemUser $staff): void
{
    app(ActOnCommission::class)->handle($commission, $type, $reason, CommissionActionToken::issue($commission, $type, $staff), $staff);
}

it('shows a credited commission with its original amount and the Credited status', function () {
    $referrer = cmxReferrer();
    $commission = cchCommission($referrer);

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1)
        ->and($html)->toContain('data-commission-amount>'.Money::format($commission->amount_kobo).'</p>')
        ->and($html)->toContain('data-commission-status="credited">Credited</span>');
});

it('keeps a reversed commission at its original amount, with the Reversed status', function () {
    $referrer = cmxReferrer();
    $commission = cchCommission($referrer);
    cchAct($commission, CommissionActionType::Reversal, 'Purchase disputed by the bank; reviewed by support.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1)
        ->and($html)->toContain('data-commission-amount>'.Money::format($commission->amount_kobo).'</p>')
        ->and($html)->toContain('data-commission-status="reversed">Reversed</span>');
});

it('keeps a cancelled commission at its original amount, with the Cancelled status', function () {
    $referrer = cmxReferrer();
    $commission = cchCommission($referrer);
    cchAct($commission, CommissionActionType::Cancellation, 'Referral found to be the same household as the buyer.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1)
        ->and($html)->toContain('data-commission-amount>'.Money::format($commission->amount_kobo).'</p>')
        ->and($html)->toContain('data-commission-status="cancelled">Cancelled</span>');
});

it('does not drop a reversed commission from the history', function () {
    $referrer = cmxReferrer();
    cchCommission($referrer);
    cchAct(cchCommission($referrer), CommissionActionType::Reversal, 'Purchase disputed by the bank; reviewed by support.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(2)
        ->and(substr_count($html, 'data-commission-status="credited"'))->toBe(1)
        ->and(substr_count($html, 'data-commission-status="reversed"'))->toBe(1);
});

it('does not drop a cancelled commission from the history', function () {
    $referrer = cmxReferrer();
    cchCommission($referrer);
    cchAct(cchCommission($referrer), CommissionActionType::Cancellation, 'Referral found to be the same household as the buyer.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(2)
        ->and(substr_count($html, 'data-commission-status="credited"'))->toBe(1)
        ->and(substr_count($html, 'data-commission-status="cancelled"'))->toBe(1);
});

it('pages the history twenty to a page', function () {
    $referrer = cmxReferrer();
    for ($i = 0; $i < 21; $i++) {
        cchCommission($referrer);
    }

    $first = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();
    $second = $this->actingAs($referrer)->get(route('referrals', ['commissions' => 2]))->assertOk()->getContent();

    expect(cchRows($first))->toBe(20)
        ->and(cchRows($second))->toBe(1)
        ->and($second)->toContain('Showing 21–21 of 21');
});

it('shows a customer only their own commissions', function () {
    $alice = cmxReferrer();
    $bob = cmxReferrer();
    $aliceCommission = cchCommission($alice, 250);
    $bobCommission = cchCommission($bob, 777);

    $aliceHtml = $this->actingAs($alice)->get(route('referrals'))->assertOk()->getContent();
    $bobHtml = $this->actingAs($bob)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($aliceHtml))->toBe(1)
        ->and($aliceHtml)->toContain('data-commission-amount>'.Money::format($aliceCommission->amount_kobo).'</p>')
        ->and($aliceHtml)->not->toContain(Money::format($bobCommission->amount_kobo))
        ->and(cchRows($bobHtml))->toBe(1)
        ->and($bobHtml)->toContain('data-commission-amount>'.Money::format($bobCommission->amount_kobo).'</p>')
        ->and($bobHtml)->not->toContain(Money::format($aliceCommission->amount_kobo));
});

it('gives an API User no Referral page, and so no commission history', function () {
    $referrer = cmxReferrer();
    cchCommission($referrer);
    app(ChangeUserType::class)->handle($referrer, UserType::ApiUser, cmxStaff());

    $this->actingAs($referrer)->get(route('referrals'))->assertNotFound()->assertDontSee('data-commission-history', false);
});

it('shows the history to Subscribers, Vendors and Affiliates alike', function (UserType $type) {
    $referrer = cmxReferrer($type);
    cchCommission($referrer);

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1);
})->with(['a Subscriber' => [UserType::Subscriber], 'a Vendor' => [UserType::Vendor], 'an Affiliate' => [UserType::Affiliate]]);

it('shows the credit date in the Business timezone, not the app timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC')); // 00:30 on 8 October in Africa/Lagos
    $referrer = cmxReferrer();
    cchCommission($referrer);

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect($html)->toContain('data-commission-date>8 Oct 2026</p>');
});

it('shows no buyer, purchase, commission or wallet reference, nor any personal detail of a buyer', function () {
    $referrer = cmxReferrer();
    $buyer = cchBuyer($referrer, 'Zainab Buyer', 'zainab.buyer@example.test', '08031234567');
    $commission = cchCommission($referrer, 250, $buyer);

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1);
    foreach (['Zainab Buyer', 'zainab.buyer@example.test', '08031234567', $commission->reference, $commission->purchase->reference,
        $commission->creditTransaction->reference] as $secret) {
        expect($html)->not->toContain($secret);
    }
    expect($html)->not->toContain('COM-')->and($html)->not->toContain('PUR-')->and($html)->not->toContain('TXN-');
});

it('shows no action reason, staff member or action reference in the history', function () {
    $referrer = cmxReferrer();
    $commission = cchCommission($referrer);
    $staff = cmxStaff();
    $staff->forceFill(['name' => 'Ruth Adeyemi', 'email' => 'ruth.adeyemi@example.test'])->save();
    cchAct($commission, CommissionActionType::Reversal, 'Refund confirmed by support after the customer disputed the charge with the bank.', $staff);
    $action = $commission->fresh()->action;

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchRows($html))->toBe(1)->and($html)->toContain('data-commission-status="reversed">Reversed</span>');
    foreach (['Refund confirmed by support', 'disputed the charge', 'Ruth Adeyemi', 'ruth.adeyemi@example.test', $action->reference] as $secret) {
        expect($html)->not->toContain($secret);
    }
    expect($html)->not->toContain('CMA-');
});

it('keeps total earned to credited commissions only', function () {
    $referrer = cmxReferrer();
    cchCommission($referrer, 250); // credited: ₦12.50
    $reversed = cchCommission($referrer, 777); // ₦38.85, then reversed
    cchAct($reversed, CommissionActionType::Reversal, 'Purchase disputed by the bank; reviewed by support.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchFigure($html, 'earned'))->toBe(Money::format(1250))
        ->and(cchRows($html))->toBe(2);
});

it('counts this month\'s total from credited commissions in the current Business month only', function () {
    $referrer = cmxReferrer();
    $this->travelTo(now()->startOfMonth()->subMonths(2)->addDays(5));
    cchCommission($referrer, 777); // ₦38.85, credited two months ago: in the total, not in this month
    $this->travelBack();
    cchCommission($referrer, 250); // ₦12.50, credited this month
    $reversed = cchCommission($referrer, 777); // ₦38.85, credited this month, then reversed: in neither
    cchAct($reversed, CommissionActionType::Reversal, 'Purchase disputed by the bank; reviewed by support.', cmxStaff());

    $html = $this->actingAs($referrer)->get(route('referrals'))->assertOk()->getContent();

    expect(cchFigure($html, 'earned'))->toBe(Money::format(3885 + 1250))
        ->and(cchFigure($html, 'this-month'))->toBe(Money::format(1250));
});

it('changes nothing when the history is read', function () {
    $referrer = cmxReferrer();
    cchCommission($referrer);
    $before = [Commission::count(), Transaction::count(), CommissionAction::count()];

    $this->actingAs($referrer)->get(route('referrals'))->assertOk();
    $this->actingAs($referrer)->get(route('referrals', ['commissions' => 1]))->assertOk();

    expect([Commission::count(), Transaction::count(), CommissionAction::count()])->toBe($before);
});
