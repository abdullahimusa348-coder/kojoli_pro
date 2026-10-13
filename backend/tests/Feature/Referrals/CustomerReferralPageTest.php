<?php

use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\MaintenanceMode;
use App\Support\Purchases\PurchaseSource;
use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 12 CP3: the customer Referral page. Subscribers, Vendors and
 * Affiliates only (API Users get a 404 and no menu item); the first visit
 * creates the Main Wallet (in its own short transaction) and then the code
 * (T3 part 1); the code, the signup link with copy and share buttons; the
 * figures, read-only and derived from the records as they are now (referred
 * customers, successful referrals from real qualifying purchases, credited
 * earnings in the Business timezone); the anonymous referral history with
 * nothing but joined dates and Active/Disabled (T14); and the customer route
 * matrix. Purchases run through PurchaseService with the test-only
 * FakeProvider.
 */

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'cable-tv'];
});

function crpCustomer(UserType $type = UserType::Subscriber, array $attributes = []): User
{
    return User::factory()->ofType($type)->create($attributes);
}

/** A customer referred by $referrer, linked as at their signup. */
function crpReferred(User $referrer, array $attributes = []): User
{
    $customer = User::factory()->create($attributes);
    (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $customer->id])->save();

    return $customer;
}

/** A purchase by $buyer of a ₦500.01 $slug plan, the provider answering $answer. */
function crpPurchase(User $buyer, string $slug = 'data', string $answer = 'succeeded'): Purchase
{
    $wallets = app(WalletService::class);
    $wallets->credit($wallets->walletFor($buyer), 100_000, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
    $plan = puxPlan($slug, 50_001);
    puxRoute($plan);
    FakeProvider::$purchaseScript = [$answer];

    return puxService()->purchase($buyer, $plan, '08012345678', null, (string) Str::uuid());
}

/** A credited commission to $referrer on a new successful Data purchase by $buyer, as the CP4 engine will record it. */
function crpCommission(User $referrer, User $buyer, int $rateBps, string $creditedAt): Commission
{
    $purchase = crpPurchase($buyer);
    $amount = Commission::amountFor($purchase->amount_kobo, $rateBps, 1_000_000);
    $wallets = app(WalletService::class);
    $wallet = $wallets->walletFor($referrer);
    $credit = $wallets->credit($wallet, $amount, LedgerEntryType::CommissionCredit, TransactionType::Commission, 'Referral commission', 'test-commission:'.Str::uuid());

    return tap((new Commission)->forceFill([
        'reference' => WalletService::reference('COM'), 'purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'wallet_id' => $wallet->id,
        'credit_transaction_id' => $credit->transaction->id, 'base_amount_kobo' => $purchase->amount_kobo, 'rate_bps' => $rateBps,
        'cap_kobo' => 1_000_000, 'amount_kobo' => $amount, 'credited_at' => CarbonImmutable::parse($creditedAt, 'UTC'),
    ]))->save();
}

/** A staff reversal (with its separate debit) or cancellation of $commission, as CP5 will record it. */
function crpAction(Commission $commission, CommissionActionType $type): CommissionAction
{
    $staff = SystemUser::factory()->create();
    $debit = $type === CommissionActionType::Reversal
        ? app(WalletService::class)->debit(Wallet::findOrFail($commission->wallet_id), $commission->amount_kobo, LedgerEntryType::CommissionReversal,
            TransactionType::Commission, 'Commission reversal', 'test-reversal:'.Str::uuid(), $staff)->transaction->id
        : null;

    return tap((new CommissionAction)->forceFill([
        'reference' => WalletService::reference('CMA'), 'commission_id' => $commission->id, 'wallet_id' => $commission->wallet_id, 'type' => $type,
        'reversal_transaction_id' => $debit, 'reason' => 'Changed after a staff review of the purchase.', 'idempotency_key' => (string) Str::uuid(),
        'acted_by' => $staff->id,
    ]))->save();
}

/** Row counts of every table. */
function crpCounts(): array
{
    return collect(Schema::getTableListing())->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
}

describe('access', function () {
    it('shows the Referrals menu item and page to Subscribers, Vendors and Affiliates', function (UserType $type) {
        $customer = crpCustomer($type);

        $this->actingAs($customer)->get('/dashboard')->assertSee('data-customer-menu="referrals"', false)
            ->assertSee('href="'.route('referrals').'"', false)->assertDontSee('data-customer-bottom-nav="referrals"', false);
        $this->get('/referrals')->assertOk()->assertSee('Your referral code')
            ->assertSee('data-customer-menu="referrals"', false);
    })->with([UserType::Subscriber, UserType::Vendor, UserType::Affiliate]);

    it('keeps API Users out: no menu item, a 404, and no wallet or code created', function () {
        $api = crpCustomer(UserType::ApiUser);

        $this->actingAs($api)->get('/dashboard')->assertOk()->assertDontSee('data-customer-menu="referrals"', false);
        $this->get('/referrals')->assertNotFound();

        expect(Wallet::count())->toBe(0)->and(ReferralCode::count())->toBe(0);
    });

    it('hides an API User\'s existing code and shows the same code once they are eligible again', function () {
        $customer = crpCustomer();
        $this->actingAs($customer)->get('/referrals')->assertOk();
        $code = ReferralCode::where('user_id', $customer->id)->value('code');

        $customer->forceFill(['user_type' => UserType::ApiUser])->save();
        $this->get('/referrals')->assertNotFound();
        $this->get('/dashboard')->assertDontSee('data-customer-menu="referrals"', false)->assertDontSee($code);

        $customer->forceFill(['user_type' => UserType::Affiliate])->save();
        $this->get('/referrals')->assertOk()->assertSee('data-referral-code>'.$code.'</p>', false);
        expect(ReferralCode::count())->toBe(1);
    });

    it('follows the Wallet page\'s email-verification rule', function () {
        $customer = crpCustomer(attributes: ['email_verified_at' => null]);

        $this->actingAs($customer)->get('/referrals')->assertOk(); // verification is off by default

        config(['nadabo.require_email_verification' => true]);
        $this->get('/referrals')->assertRedirect(route('verification.notice'));
        $this->get('/wallet')->assertRedirect(route('verification.notice'));
    });

    it('sends guests to the login page and signs out disabled customers, creating nothing', function () {
        $this->get('/referrals')->assertRedirect(route('login'));

        $this->actingAs(crpCustomer(attributes: ['status' => UserStatus::Disabled]))->get('/referrals')->assertRedirect(route('login'));
        $this->assertGuest('web');

        expect(Wallet::count())->toBe(0)->and(ReferralCode::count())->toBe(0);
    });

    it('works during maintenance mode, which only stops new purchases', function () {
        app(SettingsStore::class)->set(MaintenanceMode::SETTING, true);

        $this->actingAs(crpCustomer())->get('/referrals')->assertOk()->assertSee('data-referral-code', false);
    });
});

describe('first visit', function () {
    it('creates the Main Wallet in its own transaction and then the code, and nothing on later visits (T3 part 1)', function () {
        $customer = crpCustomer();
        $created = [];
        Wallet::created(function () use (&$created) {
            $created[] = ['wallet', DB::transactionLevel()];
        });
        ReferralCode::created(function () use (&$created) {
            $created[] = ['code', DB::transactionLevel()];
        });
        $base = DB::transactionLevel();

        $this->actingAs($customer)->get('/referrals')->assertOk();

        // The wallet in its own short transaction, then the code, outside it.
        expect($created)->toBe([['wallet', $base + 1], ['code', $base]])
            ->and(Wallet::where('user_id', $customer->id)->pluck('type')->all())->toBe([WalletType::Main])
            ->and(ReferralCode::where('user_id', $customer->id)->count())->toBe(1);

        $before = crpCounts();
        $code = ReferralCode::where('user_id', $customer->id)->value('code');
        $this->get('/referrals')->assertOk()->assertSee('data-referral-code>'.$code.'</p>', false);
        expect($created)->toHaveCount(2)->and(crpCounts())->toBe($before);
    });

    it('uses the wallet the customer already has', function () {
        $customer = crpCustomer();
        $wallet = app(WalletService::class)->walletFor($customer);

        $this->actingAs($customer)->get('/referrals')->assertOk();

        expect(Wallet::where('user_id', $customer->id)->pluck('id')->all())->toBe([$wallet->id]);
    });

    it('keeps the page and the code of a customer whose wallet is frozen', function () {
        $customer = crpCustomer();
        $wallets = app(WalletService::class);
        $wallets->setStatus($wallets->walletFor($customer), WalletStatus::Frozen);

        $this->actingAs($customer)->get('/referrals')->assertOk()->assertSee('data-referral-code', false);

        expect(ReferralCode::where('user_id', $customer->id)->exists())->toBeTrue();
    });
});

describe('page', function () {
    it('shows the code and the signup link, with copy and share buttons', function () {
        $customer = crpCustomer();

        $response = $this->actingAs($customer)->get('/referrals')->assertOk();

        $code = ReferralCode::where('user_id', $customer->id)->value('code');
        $response->assertSee('data-referral-code>'.$code.'</p>', false)
            ->assertSee('value="'.url('/register?ref='.$code).'"', false)
            ->assertSee('data-copy-code', false)->assertSee('data-copy-link', false)->assertSee('data-share-link', false)
            ->assertSee('No referred customers yet.')
            ->assertSeeInOrder(['data-referral-figure="referred"', '>0<', 'data-referral-figure="successful"', '>0<',
                'data-referral-figure="earned"', '₦0.00', 'data-referral-figure="this-month"', '₦0.00'], false);
    });

    it('counts referred customers and successful referrals from the purchases as they are now', function () {
        $referrer = crpCustomer();
        crpPurchase(crpReferred($referrer));                                       // a successful Data purchase
        $twice = crpReferred($referrer);
        crpPurchase($twice);
        crpPurchase($twice, 'airtime');                                            // two successes: one successful referral
        crpPurchase(crpReferred($referrer), 'data', 'failed_definite');            // failed only
        $pending = crpPurchase(crpReferred($referrer), 'airtime', 'unknown');      // pending only, for now
        crpPurchase(crpReferred($referrer), 'cable-tv');                           // a service that does not qualify
        $nowApi = crpReferred($referrer);
        crpPurchase($nowApi, 'airtime');
        $nowApi->forceFill(['user_type' => UserType::ApiUser])->save();           // an API User now: still counts
        crpReferred($referrer);                                                    // no purchase
        crpPurchase(crpReferred(crpCustomer()));                                   // someone else's referred customer
        crpPurchase(crpCustomer());                                                // not referred by anyone

        $this->actingAs($referrer)->get('/referrals')->assertOk()
            ->assertSeeInOrder(['data-referral-figure="referred"', '>7<', 'data-referral-figure="successful"', '>3<'], false);

        // Derived, never stored: once the pending purchase succeeds on a re-check, the figure follows, with no referral record written.
        $referralTables = fn () => array_intersect_key(crpCounts(), array_flip(['referral_codes', 'referrals', 'commission_settings',
            'commission_setting_changes', 'commissions', 'commission_actions', 'failed_commission_attempts']));
        $before = $referralTables();
        FakeProvider::$queryScript = ['succeeded'];
        expect(puxService()->recheck($pending, PurchaseSource::Reconcile)->status->value)->toBe('successful')
            ->and($referralTables())->toBe($before);

        $this->get('/referrals')->assertSeeInOrder(['data-referral-figure="successful"', '>4<'], false);
    });

    it('counts credited earnings only, and this month in the Business timezone', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));
        $referrer = crpCustomer();
        $buyer = crpReferred($referrer);
        crpCommission($referrer, $buyer, 1_000, '2026-10-07 09:00:00');                      // ₦50.00, October
        crpCommission($referrer, $buyer, 2_000, '2026-09-30 23:30:00');                      // ₦100.00, 1 Oct 00:30 in Lagos
        crpCommission($referrer, $buyer, 400, '2026-09-30 22:30:00');                        // ₦20.00, 30 Sep 23:30 in Lagos
        crpAction(crpCommission($referrer, $buyer, 3_000, '2026-10-02 10:00:00'), CommissionActionType::Reversal);
        crpAction(crpCommission($referrer, $buyer, 5_000, '2026-10-03 10:00:00'), CommissionActionType::Cancellation);
        $other = crpCustomer();
        crpCommission($other, crpReferred($other), 1_000, '2026-10-05 10:00:00');            // someone else's

        $this->actingAs($referrer)->get('/referrals')->assertOk()
            ->assertSeeInOrder(['data-referral-figure="earned"', '₦170.00', 'data-referral-figure="this-month"', '₦150.00'], false);

        app(SettingsStore::class)->set('app.timezone', 'UTC'); // in UTC the 23:30 commission is September's
        $this->get('/referrals')->assertSeeInOrder(['data-referral-figure="earned"', '₦170.00', 'data-referral-figure="this-month"', '₦50.00'], false);
    });

    it('lists referred customers anonymously, newest first, with joined dates in the Business timezone (T14)', function () {
        $referrer = crpCustomer();
        $older = crpReferred($referrer, ['name' => 'Chidi Okafor', 'email' => 'chidi@example.com', 'phone' => '08035550001', 'created_at' => '2026-03-31 23:30:00']);
        $newer = crpReferred($referrer, ['name' => 'Ngozi Bello', 'email' => 'ngozi@example.com', 'phone' => '08035550002', 'created_at' => '2026-10-06 09:00:00',
            'status' => UserStatus::Disabled]);
        crpPurchase($older);
        $this->actingAs($referrer)->get('/referrals')->assertOk(); // first visit: wallet and code
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->get('/referrals')->assertOk()
            ->assertSeeInOrder(['data-referral-history', 'Joined 6 Oct 2026', 'Disabled', 'Joined 1 Apr 2026', 'Active'], false);

        $html = $response->getContent();
        foreach ([$older, $newer] as $customer) {
            foreach ([$customer->name, $customer->email, $customer->phone, 'Okafor', 'Bello', 'ngozi', 'chidi', 'PUR-'] as $identifier) {
                expect($html)->not->toContain($identifier);
            }
        }
        expect(substr_count($html, 'data-referred-customer>'))->toBe(2)
            ->and(substr_count($html, 'data-referred-customer'))->toBe(2)
            ->and(array_keys($response->original->getData()))->toBe(['code', 'link', 'figures', 'history', 'commissions'])
            ->and($response->viewData('figures'))->toBe(['referred' => 2, 'successful' => 1, 'earned_kobo' => 0, 'this_month_kobo' => 0])
            ->and($response->viewData('history')->items())->toBe([['joined' => '6 Oct 2026', 'status' => 'Disabled'], ['joined' => '1 Apr 2026', 'status' => 'Active']]);

        $referralQueries = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, '"referrals"')));
        expect($referralQueries)->not->toBeEmpty();
        foreach ($referralQueries as $sql) {
            expect($sql)->not->toMatch('/name|email|phone|"users"\.\*|select \* from "referrals"/');
        }
    });

    it('pages the referral history 20 at a time', function () {
        $referrer = crpCustomer();
        foreach (range(1, 21) as $i) {
            crpReferred($referrer, ['created_at' => now()->subDays(30 - $i)]);
        }

        $this->actingAs($referrer)->get('/referrals')->assertSee('Showing 1–20 of 21');
        expect(substr_count($this->get('/referrals')->getContent(), 'data-referred-customer>'))->toBe(20)
            ->and(substr_count($this->get('/referrals?history=2')->getContent(), 'data-referred-customer>'))->toBe(1);
    });

    it('adds no referral card or shortcut to the dashboard', function () {
        $referrer = crpCustomer();
        crpPurchase(crpReferred($referrer));

        $html = $this->actingAs($referrer)->get('/dashboard')->assertOk()->getContent();

        expect(mb_strtolower(Str::between($html, '<main', '</main>')))->not->toContain('referral')->not->toContain('commission');
    });
});

describe('routes', function () {
    it('adds only GET /referrals for customers: no API, delete or other customer route, and /api/v1/user is unchanged', function () {
        $routes = collect(Route::getRoutes());
        $list = fn ($filter) => $routes->filter($filter)->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->sort()->values()->all();

        expect($list(fn ($route) => ! str_starts_with($route->uri(), 'admin') && preg_match('/referr|commission/i', $route->uri().' '.$route->getName())))
            ->toBe(['GET|HEAD referrals'])
            ->and($list(fn ($route) => str_starts_with($route->uri(), 'api/')))->toBe([
                'DELETE api/v1/auth/token', 'GET|HEAD api/v1/health', 'GET|HEAD api/v1/user', 'POST api/v1/auth/token', 'POST api/webhooks/payments/{gatewayCode}',
            ])
            ->and($list(fn ($route) => ! str_starts_with($route->uri(), 'admin') && in_array('DELETE', $route->methods(), true)))->toBe([
                'DELETE api/v1/auth/token', 'DELETE security/sessions/{session}', 'DELETE security/tokens', 'DELETE security/tokens/{token}',
            ]);

        $customer = crpReferred(crpCustomer());
        $token = $customer->createToken('phone')->plainTextToken;
        expect(array_keys($this->withToken($token)->getJson('/api/v1/user')->assertOk()->json('data')))
            ->toBe(['id', 'name', 'email', 'phone', 'user_type', 'user_type_label', 'status', 'email_verified_at', 'created_at']);
    });
});
