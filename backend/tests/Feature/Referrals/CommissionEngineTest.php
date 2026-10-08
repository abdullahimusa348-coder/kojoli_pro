<?php

use App\Actions\Admin\Referrals\SaveCommissionSetting;
use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Referrals\CommissionService;
use App\Services\Referrals\PreparedCommission;
use App\Services\Referrals\ReferralCodeIssuer;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Referrals\CommissionStatus;
use App\Support\Referrals\QualifyingServices;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 CP4: the commission engine on SQLite. When a referred customer's
 * purchase of a qualifying service succeeds (through its execution or a
 * re-check), their direct referrer is credited the rate of the purchase
 * amount, capped and rounded down, to their Main Wallet as "Referral
 * commission", with a commission record, in the same transaction. Who
 * takes part is decided at that moment: an API User or disabled referrer, a
 * frozen wallet, an API User buyer, a missing link, a missing or zero rate
 * or cap, or less than 1 kobo pays nothing and records nothing. One
 * commission per purchase, never recalculated or changed. Technical
 * failures are in CommissionFailureTest, the verify command in
 * VerifyCommissionsTest and the MariaDB races in
 * tests/Concurrency/CommissionEngineRaceTest.php.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

/** Nothing paid and nothing recorded for any purchase, and no commission money anywhere. */
function cetNothingPaid(User $referrer): void
{
    expect(Commission::count())->toBe(0)
        ->and(FailedCommissionAttempt::count())->toBe(0)
        ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(0)
        ->and(cmxBalance($referrer))->toBe(0);
}

describe('who takes part, decided when the purchase succeeds', function () {
    it('pays a Subscriber, Vendor or Affiliate referrer automatically when their referred customer\'s purchase succeeds', function (UserType $type) {
        $referrer = cmxReferrer($type);
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);

        $purchase = cmxBuy($buyer, cmxPlan('data', 50_000));

        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and(Commission::sole()->only(['purchase_id', 'referrer_id', 'base_amount_kobo', 'rate_bps', 'cap_kobo', 'amount_kobo']))->toBe([
                'purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'base_amount_kobo' => 50_000, 'rate_bps' => 250, 'cap_kobo' => 100_000,
                'amount_kobo' => 1_250,
            ])
            ->and(cmxBalance($referrer))->toBe(1_250)
            ->and(cmxBalance($buyer))->toBe(1_000_000 - 50_000) // the buyer pays the price, nothing more
            ->and(FailedCommissionAttempt::count())->toBe(0);
        cmxClean();
    })->with(['Subscriber' => [UserType::Subscriber], 'Vendor' => [UserType::Vendor], 'Affiliate' => [UserType::Affiliate]]);

    it('pays the referrer of a customer who signed up with their code, through the web signup and Buy form', function () {
        $referrer = cmxReferrer();
        $code = app(ReferralCodeIssuer::class)->codeFor($referrer)->code;
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan('data', 50_000);

        $this->post('/register', ['name' => 'New Customer', 'email' => 'new.customer@example.com', 'phone' => '08031234567',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'referral_code' => strtolower($code)])->assertRedirect('/dashboard');
        $buyer = User::where('email', 'new.customer@example.com')->sole();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($buyer), 100_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
        FakeProvider::$purchaseScript = ['succeeded'];
        $confirm = $this->post('/buy/data/confirm', ['plan' => $plan->id, 'phone' => '08012345678'])->assertOk();
        $this->post('/buy/data', ['plan' => $plan->id, 'phone' => $confirm->viewData('phone'), 'confirmed_amount_kobo' => 50_000,
            'token' => $confirm->viewData('token')])->assertRedirect();

        expect(Referral::sole()->only(['referrer_id', 'referred_user_id']))->toBe(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id])
            ->and(Purchase::sole()->status)->toBe(PurchaseStatus::Successful)
            ->and(Commission::sole()->only(['purchase_id', 'referrer_id', 'amount_kobo']))->toBe(['purchase_id' => Purchase::sole()->id,
                'referrer_id' => $referrer->id, 'amount_kobo' => 1_250])
            ->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays a referrer who is an API User nothing and records nothing, and pays again only for purchases after they stop being one', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan();
        app(ChangeUserType::class)->handle($referrer, UserType::ApiUser, $staff);

        $unpaid = cmxBuy($buyer, $plan);
        expect($unpaid->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($referrer);

        app(ChangeUserType::class)->handle($referrer->fresh(), UserType::Vendor, $staff);
        $paid = cmxBuy($buyer, $plan);

        expect(Commission::pluck('purchase_id')->all())->toBe([$paid->id]) // never the purchase made while they were an API User
            ->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays a disabled referrer nothing and records nothing, and never pays that purchase after they are enabled again', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan();
        app(ChangeCustomerStatus::class)->handle($referrer, UserStatus::Disabled, $staff);

        $unpaid = cmxBuy($buyer, $plan);
        expect($unpaid->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($referrer);

        app(ChangeCustomerStatus::class)->handle($referrer->fresh(), UserStatus::Active, $staff);
        $paid = cmxBuy($buyer, $plan);

        expect(Commission::pluck('purchase_id')->all())->toBe([$paid->id])->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays nothing and records nothing while the referrer\'s Main Wallet is frozen, and never pays that purchase after it is unfrozen', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan();
        app(SetWalletStatus::class)->handle($referrer, WalletStatus::Frozen, $staff);

        $unpaid = cmxBuy($buyer, $plan);
        expect($unpaid->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($referrer);

        app(SetWalletStatus::class)->handle($referrer, WalletStatus::Active, $staff);
        $paid = cmxBuy($buyer, $plan);

        expect(Commission::pluck('purchase_id')->all())->toBe([$paid->id])->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays nothing and records nothing when the buyer is an API User at the moment the purchase succeeds', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan(), 'timeout'); // bought as a Subscriber, still unclear
        app(ChangeUserType::class)->handle($buyer, UserType::ApiUser, $staff);

        expect(cmxRecheck($purchase)->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($referrer);
        cmxClean();
    });

    it('uses the referrer\'s type and status when the purchase succeeds, not when it was bought', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer(UserType::ApiUser);
        $buyer = cmxReferred($referrer); // linked while they could refer (the link is permanent)
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan(), 'timeout');
        app(ChangeUserType::class)->handle($referrer, UserType::Affiliate, $staff);

        cmxRecheck($purchase);

        expect(Commission::sole()->purchase_id)->toBe($purchase->id)->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays nothing and records nothing for a buyer who signed up without a referral code', function () {
        $someone = cmxReferrer();
        $buyer = cmxReferred(null);
        cmxSetting('data', 250, 100_000);

        expect(cmxBuy($buyer, cmxPlan())->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($someone);
        cmxClean();
    });

    it('pays the customer who referred the buyer, never the staff member whose re-check settles the purchase', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan(), 'timeout');
        expect($purchase->status)->toBe(PurchaseStatus::Pending)->and(Commission::count())->toBe(0);

        expect(cmxRecheck($purchase, 'succeeded', $staff)->status)->toBe(PurchaseStatus::Successful);

        $credit = Transaction::where('type', TransactionType::Commission->value)->sole();
        expect(Commission::sole()->referrer_id)->toBe($referrer->id)
            ->and($credit->user_id)->toBe($referrer->id)
            ->and($credit->created_by)->toBeNull() // no actor: the engine pays, not the staff member
            ->and(Transaction::where('created_by', $staff->id)->count())->toBe(0)
            ->and(WalletLedgerEntry::where('created_by', $staff->id)->count())->toBe(0);
        cmxClean();
    });
});

describe('qualifying purchases', function () {
    it('pays for a successful purchase of each qualifying service at that service\'s own rate', function (string $slug) {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        foreach (QualifyingServices::SLUGS as $i => $each) {
            cmxSetting($each, 100 * ($i + 1), 100_000); // 1% for Data up to 5% for Exam PIN
        }
        $rate = 100 * (array_search($slug, QualifyingServices::SLUGS, true) + 1);

        $purchase = cmxBuy($buyer, cmxPlan($slug, 20_000));

        expect($purchase->status)->toBe(PurchaseStatus::Successful)
            ->and(Commission::sole()->only(['purchase_id', 'rate_bps', 'amount_kobo']))->toBe(['purchase_id' => $purchase->id, 'rate_bps' => $rate,
                'amount_kobo' => intdiv(20_000 * $rate, 10_000)])
            ->and(cmxBalance($referrer))->toBe(intdiv(20_000 * $rate, 10_000));
        cmxClean();
    })->with(['Data' => 'data', 'Airtime' => 'airtime', 'NIN' => 'nin', 'BVN' => 'bvn', 'Exam PIN' => 'exam-pin']);

    it('pays nothing and records nothing while a purchase is pending, unclear or in review, or when it fails', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan();

        $pending = cmxBuy($buyer, $plan, 'unknown');
        $unclear = cmxBuy($buyer, $plan, 'timeout');
        $failed = cmxBuy($buyer, $plan, 'failed_definite');
        $review = cmxBuy($buyer, $plan, 'timeout');
        $this->travel(config('purchases.review_after_hours') + 1)->hours();
        $review = cmxRecheck($review, 'unknown');

        expect([$pending->status, $unclear->status, $failed->status, $review->status])
            ->toBe([PurchaseStatus::Pending, PurchaseStatus::Pending, PurchaseStatus::Failed, PurchaseStatus::Review]);
        cetNothingPaid($referrer);
        cmxClean();
    });

    it('pays once when an unclear purchase in review succeeds on a later re-check, however often it is re-checked or executed again', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan(), 'timeout');
        $this->travel(config('purchases.review_after_hours') + 1)->hours();
        expect(cmxRecheck($purchase, 'unknown')->status)->toBe(PurchaseStatus::Review)->and(Commission::count())->toBe(0);

        expect(cmxRecheck($purchase, 'succeeded')->status)->toBe(PurchaseStatus::Successful);
        cmxRecheck($purchase->fresh(), 'succeeded');
        cmxRecheck($purchase->fresh(), 'succeeded', $staff);
        puxService()->execute($purchase->fresh());
        puxService()->reconcile();

        expect(Commission::sole()->purchase_id)->toBe($purchase->id)
            ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(1)
            ->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });

    it('pays nothing for a service that does not qualify, even with a rate stored for it', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('cable-tv', 250, 100_000); // never possible through the admin page (CP2): stored directly here

        expect(cmxBuy($buyer, cmxPlan('cable-tv'))->status)->toBe(PurchaseStatus::Successful);
        cetNothingPaid($referrer);
        cmxClean();
    });

    it('never pays for Smile Data: it does not qualify and its purchases are refused before any money moves', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);

        expect(fn () => cmxBuy($buyer, cmxPlan('smile-data')))->toThrow(PurchaseException::class, 'This service is not available yet.')
            ->and(QualifyingServices::includes('smile-data'))->toBeFalse()
            ->and(Purchase::count())->toBe(0)
            ->and(cmxBalance($buyer))->toBe(1_000_000);
        cetNothingPaid($referrer);
    });

    it('pays when a purchase succeeds on a re-check during maintenance mode, which only stops new purchases', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan(), 'timeout');
        app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

        expect(MaintenanceMode::active())->toBeTrue()
            ->and(cmxRecheck($purchase)->status)->toBe(PurchaseStatus::Successful)
            ->and(Commission::sole()->amount_kobo)->toBe(1_250)
            ->and(cmxBalance($referrer))->toBe(1_250);
        cmxClean();
    });
});

describe('calculation', function () {
    it('pays the rate of the purchase amount, then the cap, rounded down to the kobo, and nothing when that is under 1 kobo', function (int $price, ?array $setting, ?int $amount) {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        if ($setting !== null) {
            cmxSetting('data', ...$setting);
        }

        $purchase = cmxBuy($buyer, cmxPlan('data', $price));

        expect($purchase->status)->toBe(PurchaseStatus::Successful);
        if ($amount === null) {
            cetNothingPaid($referrer);
        } else {
            expect(Commission::sole()->only(['base_amount_kobo', 'rate_bps', 'cap_kobo', 'amount_kobo']))->toBe([
                'base_amount_kobo' => $price, 'rate_bps' => $setting[0], 'cap_kobo' => $setting[1], 'amount_kobo' => $amount,
            ])->and(cmxBalance($referrer))->toBe($amount);
        }
        cmxClean();
    })->with([
        '2.5% of ₦500' => [50_000, [250, 100_000], 1_250],
        'rounded down (1,250.025 kobo)' => [50_001, [250, 100_000], 1_250],
        'the highest rate, 99.99%' => [50_000, [9_999, 100_000], 49_995],
        'the lowest rate, 0.01%' => [50_000, [1, 100_000], 5],
        'above the cap: the cap' => [50_000, [9_999, 1_000], 1_000],
        'exactly the cap' => [40_000, [250, 1_000], 1_000],
        'just below the cap' => [39_960, [250, 1_000], 999],
        'just above the cap: the cap' => [40_040, [250, 1_000], 1_000],
        'a cap of 1 kobo' => [50_000, [250, 1], 1],
        'exactly 1 kobo (0.25% of 400 kobo)' => [400, [25, 100_000], 1],
        'under 1 kobo (0.25% of 399 kobo = 0.9975 kobo): nothing' => [399, [25, 100_000], null],
        'a rate of 0: nothing' => [50_000, [0, 100_000], null],
        'a cap of 0: nothing' => [50_000, [250, 0], null],
        'no rate and cap set: nothing' => [50_000, null, null],
    ]);

    it('calculates from what the customer paid, never the face value, the provider cost or the margin', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        cmxSetting('airtime', 1_000, 100_000); // 10%
        $plan = puxPlan('airtime', 0, true); // the customer gets a 2% discount on the face value
        puxRoute($plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]); // the provider gives 3%
        FakeProvider::$purchaseScript = ['succeeded'];

        $purchase = puxService()->purchase($buyer, $plan->fresh(), '08012345678', 100_000, (string) Str::uuid());

        expect($purchase->only(['face_value_kobo', 'amount_kobo', 'cost_kobo', 'margin_kobo']))->toBe(['face_value_kobo' => 100_000,
            'amount_kobo' => 98_000, 'cost_kobo' => 97_000, 'margin_kobo' => 1_000])
            ->and(Commission::sole()->only(['base_amount_kobo', 'amount_kobo']))->toBe(['base_amount_kobo' => 98_000, 'amount_kobo' => 9_800]);
        cmxClean();
    });

    it('keeps the rate and cap a commission used when they change later, and pays later purchases at the new values', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        $plan = cmxPlan('data', 50_000);
        $save = fn (int $rate, int $cap) => app(SaveCommissionSetting::class)->handle(cmxService('data'), $rate, $cap, 'Commission rate for this test.',
            $staff, SaveCommissionSetting::fingerprint(cmxService('data')));

        $save(250, 100_000);
        $first = cmxBuy($buyer, $plan);
        $paid = Commission::sole()->getAttributes();
        $this->travel(1)->minutes();
        $save(500, 1_000);
        $second = cmxBuy($buyer, $plan);

        expect(Commission::where('purchase_id', $first->id)->sole()->getAttributes())->toBe($paid) // never recalculated
            ->and(Commission::where('purchase_id', $second->id)->sole()->only(['rate_bps', 'cap_kobo', 'amount_kobo']))
            ->toBe(['rate_bps' => 500, 'cap_kobo' => 1_000, 'amount_kobo' => 1_000])
            ->and(cmxBalance($referrer))->toBe(2_250);
        cmxClean();
    });
});

describe('wallet credit', function () {
    it('credits the referrer\'s Main Wallet at once as "Referral commission": one successful credit and ledger entry, no actor and no details', function () {
        $referrer = cmxReferrer();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($referrer), 7_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
        $buyer = cmxReferred($referrer);
        cmxSetting('data', 250, 100_000);

        $purchase = cmxBuy($buyer, cmxPlan('data', 50_000));

        $commission = Commission::sole();
        $wallet = Wallet::where('user_id', $referrer->id)->sole();
        $credit = Transaction::findOrFail($commission->credit_transaction_id);
        expect($wallet->type)->toBe(WalletType::Main)
            ->and($commission->wallet_id)->toBe($wallet->id)
            ->and($credit->only(['user_id', 'wallet_id', 'type', 'direction', 'amount_kobo', 'status', 'description', 'idempotency_key', 'created_by', 'metadata']))
            ->toBe(['user_id' => $referrer->id, 'wallet_id' => $wallet->id, 'type' => TransactionType::Commission, 'direction' => Direction::Credit,
                'amount_kobo' => 1_250, 'status' => TransactionStatus::Successful, 'description' => 'Referral commission',
                'idempotency_key' => 'commission:'.$purchase->reference, 'created_by' => null, 'metadata' => null])
            ->and(WalletLedgerEntry::where('transaction_id', $credit->id)->sole()->only(['wallet_id', 'direction', 'amount_kobo', 'balance_after_kobo',
                'entry_type', 'description', 'created_by', 'metadata', 'reverses_entry_id']))
            ->toBe(['wallet_id' => $wallet->id, 'direction' => Direction::Credit, 'amount_kobo' => 1_250, 'balance_after_kobo' => 8_250,
                'entry_type' => LedgerEntryType::CommissionCredit, 'description' => 'Referral commission', 'created_by' => null, 'metadata' => null,
                'reverses_entry_id' => null])
            ->and($wallet->balance_kobo)->toBe(8_250)
            ->and($commission->reference)->toMatch('/\ACOM-[0-9A-Z]{26}\z/')
            ->and($commission->credited_at->equalTo($credit->completed_at))->toBeTrue()
            ->and($commission->status())->toBe(CommissionStatus::Credited)
            ->and(Wallet::where('user_id', $referrer->id)->count())->toBe(1);
        cmxClean();
    });

    it('shows the referrer the credit on their wallet page, without anything about the buyer or what they bought', function () {
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        $buyer->forceFill(['name' => 'Bisi Adewale', 'email' => 'bisi.adewale@example.com'])->save();
        cmxSetting('data', 250, 100_000);
        $purchase = cmxBuy($buyer, cmxPlan('data', 50_000));

        $html = $this->actingAs($referrer)->get('/wallet')->assertOk()->assertSee('Referral commission')->assertSee('₦12.50')->getContent();

        expect($html)->not->toContain('Bisi')->not->toContain('bisi.adewale')->not->toContain($buyer->phone)
            ->not->toContain('08012345678')->not->toContain($purchase->reference)->not->toContain(Commission::sole()->reference);
    });
});

describe('one commission per purchase', function () {
    it('does nothing when the commission step runs again for the same success, or for the purchase at any later time', function () {
        $logs = cmxRecordLogs();
        app()->bind(CommissionService::class, fn ($app) => new class($app->make(WalletService::class)) extends CommissionService
        {
            public function prepare(Purchase $locked): ?PreparedCommission
            {
                parent::prepare($locked); // repeated in the same success transaction: the same locks again

                return parent::prepare($locked);
            }

            public function settle(Purchase $locked, ?PreparedCommission $prepared): ?Commission
            {
                $first = parent::settle($locked, $prepared);
                expect(parent::settle($locked, $prepared))->toBeNull(); // repeated in the same success transaction

                return $first;
            }
        });
        $referrer = cmxReferrer();
        $walletless = User::factory()->create();
        cmxSetting('data', 250, 100_000);
        $plan = cmxPlan();

        $paid = cmxBuy(cmxReferred($referrer), $plan);
        $failed = cmxBuy(cmxReferred($walletless), $plan);
        $unpaid = cmxBuy(cmxReferred($referrer), cmxPlan('airtime')); // no Airtime rate when it succeeded
        cmxSetting('airtime', 250, 100_000);
        $later = new CommissionService(app(WalletService::class));
        $wallet = Wallet::where('user_id', $referrer->id)->sole();
        foreach ([$paid, $failed, $unpaid] as $purchase) {
            expect($later->prepare($purchase->fresh()))->toBeNull() // a successful purchase is never evaluated again
                ->and($later->settle($purchase->fresh(), PreparedCommission::payable($referrer->id, $wallet, 250, 100_000, 1_250)))->toBeNull()
                ->and($later->settle($purchase->fresh(), PreparedCommission::failed($referrer->id, CommissionFailureReason::UnexpectedError)))->toBeNull();
        }

        expect(Commission::pluck('purchase_id')->all())->toBe([$paid->id])
            ->and(FailedCommissionAttempt::pluck('purchase_id')->all())->toBe([$failed->id])
            ->and(Transaction::where('type', TransactionType::Commission->value)->count())->toBe(1)
            ->and(cmxBalance($referrer))->toBe(1_250)
            ->and(array_values(array_filter($logs->getArrayCopy(), fn (string $line) => str_contains($line, 'commission'))))
            ->toBe(['Referral commission not credited {"purchase":"'.$failed->reference.'","reason":"wallet_unavailable"}']); // once
    });

    it('allows one commission and one failed attempt per purchase, never both, in the database and in the models', function () {
        $referrer = cmxReferrer();
        $walletless = User::factory()->create();
        cmxSetting('data', 250, 100_000);
        $paid = cmxBuy(cmxReferred($referrer), cmxPlan());
        $failed = cmxBuy(cmxReferred($walletless), cmxPlan());
        $commission = Commission::sole();
        $wallets = app(WalletService::class);
        $commissionOn = function (Purchase $purchase, User $to) use ($wallets) {
            $credit = $wallets->credit($wallets->walletFor($to), 1_250, LedgerEntryType::CommissionCredit, TransactionType::Commission,
                'Referral commission', 'test:'.Str::uuid());

            return (new Commission)->forceFill(['reference' => WalletService::reference('COM'), 'purchase_id' => $purchase->id, 'referrer_id' => $to->id,
                'wallet_id' => $credit->transaction->wallet_id, 'credit_transaction_id' => $credit->transaction->id, 'base_amount_kobo' => 50_000,
                'rate_bps' => 250, 'cap_kobo' => 100_000, 'amount_kobo' => 1_250, 'credited_at' => now()]);
        };
        $attemptOn = fn (Purchase $purchase, User $to) => (new FailedCommissionAttempt)->forceFill(['purchase_id' => $purchase->id,
            'referrer_id' => $to->id, 'reason_code' => CommissionFailureReason::UnexpectedError]);

        expect(fn () => $commissionOn($paid, $referrer)->save())->toThrow(UniqueConstraintViolationException::class) // commissions.purchase_id
            ->and(fn () => $attemptOn($paid, $referrer)->save())->toThrow(LogicException::class, 'A purchase with a commission has no failed commission attempt.')
            ->and(fn () => $attemptOn($failed, $walletless)->save())->toThrow(UniqueConstraintViolationException::class) // failed_commission_attempts.purchase_id
            ->and(fn () => $commissionOn($failed, $walletless)->save())->toThrow(LogicException::class, 'A purchase with a failed commission attempt has no commission.')
            ->and(Commission::sole()->is($commission))->toBeTrue()
            ->and(FailedCommissionAttempt::sole()->purchase_id)->toBe($failed->id);
    });
});

describe('records that never change', function () {
    it('keeps an engine-made commission and failed attempt unchangeable and undeletable, Credited with no action, whatever changes later', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $buyer = cmxReferred($referrer);
        $walletless = User::factory()->create();
        cmxSetting('data', 250, 100_000);
        cmxBuy($buyer, cmxPlan());
        cmxBuy(cmxReferred($walletless), cmxPlan());
        $commission = Commission::sole()->getAttributes();
        $attempt = FailedCommissionAttempt::sole()->getAttributes();

        expect(fn () => Commission::sole()->forceFill(['amount_kobo' => 1])->save())->toThrow(LogicException::class, 'Commissions never change')
            ->and(fn () => Commission::sole()->delete())->toThrow(LogicException::class, 'Commissions are never deleted.')
            ->and(fn () => FailedCommissionAttempt::sole()->forceFill(['reason_code' => CommissionFailureReason::WalletRefused])->save())
            ->toThrow(LogicException::class, 'Failed commission attempts are append-only.')
            ->and(fn () => FailedCommissionAttempt::sole()->delete())->toThrow(LogicException::class, 'Failed commission attempts are append-only.');

        // Later changes apply to later purchases only.
        cmxSetting('data', 9_999, 1);
        app(ChangeUserType::class)->handle($buyer, UserType::ApiUser, $staff);
        app(SetWalletStatus::class)->handle($referrer, WalletStatus::Frozen, $staff);
        app(ChangeUserType::class)->handle($referrer, UserType::ApiUser, $staff);
        app(ChangeCustomerStatus::class)->handle($referrer->fresh(), UserStatus::Disabled, $staff);

        expect(Commission::sole()->getAttributes())->toBe($commission)
            ->and(FailedCommissionAttempt::sole()->getAttributes())->toBe($attempt)
            ->and(Commission::sole()->status())->toBe(CommissionStatus::Credited) // derived: no status column, and no action is ever written here
            ->and(Schema::hasColumn('commissions', 'status'))->toBeFalse()
            ->and(CommissionAction::count())->toBe(0)
            ->and(cmxBalance($referrer))->toBe(1_250);
    });

    it('writes commissions and failed attempts only through their guarded models, and the bulk-write scan (T7) covers the engine', function () {
        $engine = File::get(app_path('Services/Referrals/CommissionService.php'));

        expect($engine)->toContain('(new Commission)->forceFill(')->toContain('(new FailedCommissionAttempt)->forceFill(')
            ->not->toContain('DB::table(')->not->toContain('DB::statement(')->not->toContain('DB::insert(')->not->toContain('DB::update(')
            ->not->toContain('DB::delete(')->not->toContain('DB::unprepared(')
            ->and(collect(File::allFiles(app_path()))->map(fn ($file) => $file->getPathname())->all())
            ->toContain(app_path('Services/Referrals/CommissionService.php'), app_path('Console/Commands/VerifyCommissionsCommand.php'));
    });
});
