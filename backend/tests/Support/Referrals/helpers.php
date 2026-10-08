<?php

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\Purchases\PurchaseSource;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Purchases/helpers.php';

/*
 * Shared helpers for the Phase 12 CP4 commission engine tests (feature and
 * MariaDB concurrency). Purchases run through PurchaseService with the
 * test-only FakeProvider; links, rates, caps and money exist only inside
 * each test. NIN/BVN numbers are random 11-digit fixtures and results are
 * FakeProvider's neutral fixture fields: never real identity data.
 */

const CMX_NAMES = ['data' => 'Data', 'airtime' => 'Airtime', 'nin' => 'NIN', 'bvn' => 'BVN', 'exam-pin' => 'Exam PIN', 'cable-tv' => 'Cable TV'];

/** FakeProvider for the five qualifying services and one that does not qualify. */
function cmxDrivers(): void
{
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin', 'cable-tv'];
}

/** A customer of $type who has opened their Referral page, so they have a Main Wallet. */
function cmxReferrer(UserType $type = UserType::Subscriber): User
{
    $user = User::factory()->ofType($type)->create();
    app(WalletService::class)->walletFor($user);

    return $user;
}

/** A Subscriber with $balanceKobo in their wallet who signed up with $referrer's code (no link when null). */
function cmxReferred(?User $referrer, int $balanceKobo = 1_000_000): User
{
    $buyer = puxCustomer($balanceKobo);
    if ($referrer !== null) {
        (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id])->save();
    }

    return $buyer;
}

function cmxService(string $slug): Service
{
    return Service::where('slug', $slug)->first() ?? Service::factory()->create(['name' => CMX_NAMES[$slug] ?? Str::headline($slug), 'slug' => $slug]);
}

/** $slug's commission rate and cap, stored directly (the admin page that sets them is tested in CommissionSettingsTest). */
function cmxSetting(string $slug, int $rateBps, int $capKobo): CommissionSetting
{
    $service = cmxService($slug);
    $setting = CommissionSetting::where('service_id', $service->id)->first() ?? new CommissionSetting;

    return tap($setting->forceFill(['service_id' => $service->id, 'rate_bps' => $rateBps, 'cap_kobo' => $capKobo,
        'updated_by' => SystemUser::query()->value('id') ?? SystemUser::factory()->create()->id]))->save();
}

/** An available fixed-price plan of $slug, $priceKobo for Subscribers, with one executable FakeProvider route. */
function cmxPlan(string $slug = 'data', int $priceKobo = 50_000): Plan
{
    if (in_array($slug, ['data', 'airtime', 'cable-tv'], true)) {
        $plan = puxPlan($slug, $priceKobo);
    } else {
        $product = Product::factory()->create(['service_id' => cmxService($slug)->id, 'name' => 'Test product', 'network' => null,
            'code' => $slug.'-test-'.Str::lower(Str::random(6))]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => $priceKobo]);
    }
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/**
 * $buyer's purchase of $plan through PurchaseService, the provider answering $answer: a phone number for phone
 * services, a random 11-digit number with consent for NIN/BVN, no recipient for Exam PIN (the last three deliver
 * neutral fixture fields when they succeed).
 */
function cmxBuy(User $buyer, Plan $plan, string $answer = 'succeeded'): Purchase
{
    $slug = $plan->product->service->slug;
    FakeProvider::$purchaseScript = [$answer];
    FakeProvider::$resultScript = in_array($slug, ['nin', 'bvn', 'exam-pin'], true) ? [FakeProvider::fixtureFields()] : [];
    $recipient = match ($slug) {
        'nin', 'bvn' => (string) random_int(10_000_000_000, 99_999_999_999),
        'exam-pin' => '',
        default => '08012345678',
    };

    return puxService()->purchase($buyer, $plan, $recipient, null, (string) Str::uuid(), null, in_array($slug, ['nin', 'bvn'], true));
}

/** Re-checks an unclear purchase (scheduled, or by $staff), the provider's status lookup answering $answer. */
function cmxRecheck(Purchase $purchase, string $answer = 'succeeded', ?SystemUser $staff = null): Purchase
{
    FakeProvider::$queryScript = [$answer];
    FakeProvider::$resultScript = $purchase->recipient_type->requiresResult() ? [FakeProvider::fixtureFields()] : [];

    return $staff === null ? puxService()->recheck($purchase, PurchaseSource::Reconcile) : app(RecheckPurchase::class)->handle($purchase, $staff);
}

/** A Super Admin. */
function cmxStaff(): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

/** Staff with a custom role holding exactly $permissions. */
function cmxStaffWith(array $permissions): SystemUser
{
    (new RolesAndPermissionsSeeder)->run();
    $role = Role::create(['name' => 'CMX '.Str::random(8), 'guard_name' => 'admin'])->givePermissionTo($permissions);
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role->name);

    return $staff;
}

/**
 * A credited commission (CP4): a referred customer's successful ₦500 Data purchase at 2.5%, so 1,250 kobo, credited to
 * $referrer (a new customer with a Main Wallet when null), whose wallet first gets $balanceKobo by a test adjustment.
 *
 * @return array{0: User, 1: Commission}
 */
function cmxCommission(int $balanceKobo = 0, ?User $referrer = null): array
{
    $referrer ??= cmxReferrer();
    if ($balanceKobo > 0) {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($referrer), $balanceKobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
    }
    cmxSetting('data', 250, 100_000);
    $purchase = cmxBuy(cmxReferred($referrer), cmxPlan('data', 50_000));

    return [$referrer, Commission::where('purchase_id', $purchase->id)->sole()];
}

/** The form fields of a commission's Reverse or Cancel form, with a fresh one-time token for $staff. */
function cmxActionForm(Commission $commission, CommissionActionType $type, SystemUser $staff, array $overrides = []): array
{
    return $overrides + ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
        'token' => CommissionActionToken::issue($commission, $type, $staff)];
}

/** The route a commission's $type form posts to. */
function cmxActionUrl(Commission $commission, CommissionActionType $type): string
{
    return route($type === CommissionActionType::Reversal ? 'admin.referrals.commissions.reverse' : 'admin.referrals.commissions.cancel', $commission);
}

function cmxBalance(User $user): int
{
    return Wallet::where('user_id', $user->id)->sole()->balance_kobo;
}

/** Every log line written from now on, as "message {context}". */
function cmxRecordLogs(): ArrayObject
{
    $lines = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $lines->append(
        $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return $lines;
}

/**
 * A database error as PDO reports it ($sqlstate, the driver's error code and MariaDB's wording), wrapped in a
 * QueryException as Laravel throws it. Only ever thrown by a test.
 */
function cmxDatabaseError(string $sqlstate, string $message): QueryException
{
    $pdo = new class("SQLSTATE[{$sqlstate}]: {$message}", $sqlstate) extends PDOException
    {
        public function __construct(string $message, string $sqlstate)
        {
            parent::__construct($message);
            $this->code = $sqlstate; // PDO's code is the SQLSTATE string
        }
    };
    $pdo->errorInfo = [$sqlstate, preg_match('/: (\d+) /', $message, $code) === 1 ? (int) $code[1] : 0, $message];

    return new QueryException(DB::getDefaultConnection(), 'select 1', [], $pdo);
}

/** The three integrity checks find nothing. */
function cmxClean(): void
{
    foreach (['wallet:verify', 'purchases:verify', 'commissions:verify'] as $check) {
        expect(Artisan::call($check))->toBe(0, $check.': '.Artisan::output());
    }
}
