<?php

use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\CommissionSetting;
use App\Models\CommissionSettingChange;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Admin\AdminModule;
use App\Support\BusinessTime;
use App\Support\Customer\CustomerNav;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Referrals\CommissionActionType;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Referrals\CommissionStatus;
use App\Support\Referrals\QualifyingServices;
use App\Support\Referrals\ReferralCodes;
use App\Support\Referrals\ReferralEligibility;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 12 CP1: the referral and commission foundation on SQLite (the test
 * database). The seven tables with their keys and restricting foreign keys;
 * every model guard (the matching CHECK constraints are tested on MariaDB in
 * tests/Concurrency/ReferralSchemaTest.php); the enums and helpers; the
 * business month; and the scope: nothing seeded, no permission, route, page
 * or menu change, no bulk write that would skip the guards (T7), and
 * rollback refused while any row exists. Purchases run through
 * PurchaseService with the test-only FakeProvider. Commission records are
 * built here directly, only to exercise the guards: nothing in the app
 * writes them before CP4.
 */

const RFT_MIGRATION = '2026_10_07_100000_create_referral_and_commission_tables';

/** The seven tables, in the order the migration checks and drops them. */
const RFT_TABLES = ['failed_commission_attempts', 'commission_actions', 'commissions', 'commission_setting_changes', 'commission_settings', 'referrals', 'referral_codes'];

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'cable-tv'];
});

/** A customer of $type with an empty Main Wallet. */
function rftCustomer(UserType $type = UserType::Subscriber): User
{
    $user = User::factory()->ofType($type)->create();
    app(WalletService::class)->walletFor($user);

    return $user;
}

/**
 * A referred customer's purchase of $slug through the engine, the provider answering $answer.
 *
 * @return array{0: User, 1: User, 2: Purchase} the referrer, the buyer and the purchase
 */
function rftReferredPurchase(string $slug = 'data', int $priceKobo = 50_001, string $answer = 'succeeded'): array
{
    $referrer = rftCustomer();
    $buyer = puxCustomer(1_000_000);
    (new Referral)->forceFill(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id])->save();
    $plan = puxPlan($slug, $priceKobo);
    puxRoute($plan);
    FakeProvider::$purchaseScript = [$answer];

    return [$referrer, $buyer, puxService()->purchase($buyer, $plan, '08012345678', null, (string) Str::uuid())];
}

/** A wallet credit of $amount to $user's Main Wallet (a commission credit unless told otherwise). */
function rftCredit(User $user, int $amount, TransactionType $type = TransactionType::Commission, LedgerEntryType $entry = LedgerEntryType::CommissionCredit): Transaction
{
    $wallets = app(WalletService::class);

    return $wallets->credit($wallets->walletFor($user), $amount, $entry, $type, 'Referral commission', 'test-credit:'.Str::uuid())->transaction;
}

/** An unsaved commission on $purchase for $referrer: the amount from the rate and cap, with its matching credit unless overridden. */
function rftCommission(User $referrer, Purchase $purchase, int $rateBps = 250, int $capKobo = 100_000, array $overrides = []): Commission
{
    $amount = $overrides['amount_kobo'] ?? Commission::amountFor($purchase->amount_kobo, $rateBps, $capKobo);
    if (! array_key_exists('credit_transaction_id', $overrides)) {
        $overrides['credit_transaction_id'] = rftCredit($referrer, max(1, $amount))->id;
    }

    return (new Commission)->forceFill($overrides + [
        'reference' => WalletService::reference('COM'),
        'purchase_id' => $purchase->id,
        'referrer_id' => $referrer->id,
        'wallet_id' => app(WalletService::class)->walletFor($referrer)->id,
        'base_amount_kobo' => $purchase->amount_kobo,
        'rate_bps' => $rateBps,
        'cap_kobo' => $capKobo,
        'amount_kobo' => $amount,
        'credited_at' => now(),
    ]);
}

/** A saved commission on a new referred customer's successful purchase. */
function rftSavedCommission(): Commission
{
    [$referrer, , $purchase] = rftReferredPurchase();

    return tap(rftCommission($referrer, $purchase))->save();
}

/** The commission reversal debit of $commission's amount on its wallet (as staff will post it in CP5). */
function rftReversalDebit(Commission $commission, SystemUser $staff, ?int $amount = null, LedgerEntryType $entry = LedgerEntryType::CommissionReversal): Transaction
{
    return app(WalletService::class)->debit(Wallet::findOrFail($commission->wallet_id), $amount ?? $commission->amount_kobo, $entry,
        TransactionType::Commission, 'Commission reversal', 'test-reversal:'.Str::uuid(), $staff)->transaction;
}

/** An unsaved reversal of $commission with its separate debit unless overridden. */
function rftReversal(Commission $commission, SystemUser $staff, array $overrides = []): CommissionAction
{
    if (! array_key_exists('reversal_transaction_id', $overrides)) {
        $overrides['reversal_transaction_id'] = rftReversalDebit($commission, $staff)->id;
    }

    return (new CommissionAction)->forceFill($overrides + [
        'reference' => WalletService::reference('CMA'),
        'commission_id' => $commission->id,
        'wallet_id' => $commission->wallet_id,
        'type' => CommissionActionType::Reversal,
        'reason' => 'Reversed after a staff review of the purchase.',
        'idempotency_key' => (string) Str::uuid(),
        'acted_by' => $staff->id,
    ]);
}

/** An unsaved cancellation of $commission (no wallet transaction). */
function rftCancellation(Commission $commission, SystemUser $staff, array $overrides = []): CommissionAction
{
    return (new CommissionAction)->forceFill($overrides + [
        'reference' => WalletService::reference('CMA'),
        'commission_id' => $commission->id,
        'wallet_id' => $commission->wallet_id,
        'type' => CommissionActionType::Cancellation,
        'reversal_transaction_id' => null,
        'reason' => 'Cancelled after a staff review of the purchase.',
        'idempotency_key' => (string) Str::uuid(),
        'acted_by' => $staff->id,
    ]);
}

/** An unsaved failed commission attempt on $purchase, naming $referrer. */
function rftAttempt(Purchase $purchase, User $referrer, CommissionFailureReason $reason = CommissionFailureReason::WalletBalanceLimit): FailedCommissionAttempt
{
    return (new FailedCommissionAttempt)->forceFill(['purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'reason_code' => $reason]);
}

/** A saved setting of service $slug. */
function rftSetting(string $slug = 'data', int $rateBps = 250, int $capKobo = 100_000, ?SystemUser $staff = null): CommissionSetting
{
    $service = Service::where('slug', $slug)->first() ?? Service::factory()->create(['name' => Str::headline($slug), 'slug' => $slug]);

    return tap((new CommissionSetting)->forceFill(['service_id' => $service->id, 'rate_bps' => $rateBps, 'cap_kobo' => $capKobo,
        'updated_by' => ($staff ?? SystemUser::factory()->create())->id]))->save();
}

/** An unsaved history row recording $setting's current values (a first save unless overridden). */
function rftChange(CommissionSetting $setting, array $overrides = []): CommissionSettingChange
{
    return (new CommissionSettingChange)->forceFill($overrides + [
        'commission_setting_id' => $setting->id,
        'service_id' => $setting->service_id,
        'old_rate_bps' => null,
        'new_rate_bps' => $setting->rate_bps,
        'old_cap_kobo' => null,
        'new_cap_kobo' => $setting->cap_kobo,
        'reason' => 'First commission rate for this service.',
        'changed_by' => $setting->updated_by,
    ]);
}

/** Every table with its columns, indexes and foreign keys, as the schema builder reports them. */
function rftSchema(): array
{
    $sorted = fn (array $items) => collect($items)->sortBy(fn (array $item) => json_encode($item))->values()->all();

    return collect(Schema::getTables())->pluck('name')->sort()->values()->mapWithKeys(fn (string $table) => [$table => [
        'columns' => Schema::getColumns($table), 'indexes' => $sorted(Schema::getIndexes($table)), 'foreign_keys' => $sorted(Schema::getForeignKeys($table)),
    ]])->all();
}

/** @return array<string, list<array<string, mixed>>> every row of the seven tables */
function rftRows(): array
{
    return collect(RFT_TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

describe('schema', function () {
    it('creates the seven tables with exactly these columns, and a failed attempt holds no money (T10)', function () {
        $columns = fn (string $table) => collect(Schema::getColumns($table))->pluck('name')->all();
        $nullable = fn (string $table) => collect(Schema::getColumns($table))->where('nullable', true)->pluck('name')->values()->all();

        expect($columns('referral_codes'))->toBe(['id', 'user_id', 'code', 'created_at'])
            ->and($columns('referrals'))->toBe(['id', 'referrer_id', 'referred_user_id', 'created_at'])
            ->and($columns('commission_settings'))->toBe(['id', 'service_id', 'rate_bps', 'cap_kobo', 'updated_by', 'created_at', 'updated_at'])
            ->and($columns('commission_setting_changes'))->toBe(['id', 'commission_setting_id', 'service_id', 'old_rate_bps', 'new_rate_bps', 'old_cap_kobo',
                'new_cap_kobo', 'reason', 'changed_by', 'created_at'])
            ->and($columns('commissions'))->toBe(['id', 'reference', 'purchase_id', 'referrer_id', 'wallet_id', 'credit_transaction_id', 'base_amount_kobo',
                'rate_bps', 'cap_kobo', 'amount_kobo', 'credited_at', 'created_at'])
            ->and($columns('commission_actions'))->toBe(['id', 'reference', 'commission_id', 'wallet_id', 'type', 'reversal_transaction_id', 'reason',
                'idempotency_key', 'acted_by', 'created_at'])
            ->and($columns('failed_commission_attempts'))->toBe(['id', 'purchase_id', 'referrer_id', 'reason_code', 'created_at'])
            ->and(preg_grep('/kobo|amount|bps|rate|cap|balance|money/', $columns('failed_commission_attempts')))->toBe([]);

        expect($nullable('commission_setting_changes'))->toBe(['old_rate_bps', 'old_cap_kobo'])
            ->and($nullable('commission_actions'))->toBe(['reversal_transaction_id'])
            ->and($nullable('commission_settings'))->toBe(['created_at', 'updated_at']);
        foreach (['referral_codes', 'referrals', 'commissions', 'failed_commission_attempts'] as $table) {
            expect($nullable($table))->toBe([], "{$table} has nullable columns");
        }
    });

    it('adds the unique keys and indexes', function () {
        $indexes = fn (string $table, bool $unique) => collect(Schema::getIndexes($table))
            ->filter(fn (array $index) => $index['unique'] === $unique && ! $index['primary'])
            ->map(fn (array $index) => implode(',', $index['columns']))->sort()->values()->all();

        expect($indexes('referral_codes', true))->toBe(['code', 'user_id'])
            ->and($indexes('referrals', true))->toBe(['referred_user_id'])
            ->and($indexes('referrals', false))->toBe(['referrer_id,id'])
            ->and($indexes('commission_settings', true))->toBe(['service_id'])
            ->and($indexes('commission_setting_changes', true))->toBe([])
            ->and($indexes('commission_setting_changes', false))->toBe(['service_id,id'])
            ->and($indexes('commissions', true))->toBe(['credit_transaction_id', 'id,wallet_id', 'purchase_id', 'reference'])
            ->and($indexes('commissions', false))->toBe(['credited_at', 'referrer_id,credited_at'])
            ->and($indexes('commission_actions', true))->toBe(['commission_id', 'idempotency_key', 'reference', 'reversal_transaction_id'])
            ->and($indexes('failed_commission_attempts', true))->toBe(['purchase_id']);
    });

    it('restricts every foreign key, including the compound keys that keep a credit and its reversal on the commission\'s wallet', function () {
        $foreign = fn (string $table) => collect(Schema::getForeignKeys($table))
            ->map(fn (array $key) => implode(',', $key['columns']).' -> '.$key['foreign_table'].'('.implode(',', $key['foreign_columns']).') '.$key['on_delete'])
            ->sort()->values()->all();

        expect($foreign('referral_codes'))->toBe(['user_id -> users(id) restrict'])
            ->and($foreign('referrals'))->toBe(['referred_user_id -> users(id) restrict', 'referrer_id -> users(id) restrict'])
            ->and($foreign('commission_settings'))->toBe(['service_id -> services(id) restrict', 'updated_by -> system_users(id) restrict'])
            ->and($foreign('commission_setting_changes'))->toBe(['changed_by -> system_users(id) restrict',
                'commission_setting_id -> commission_settings(id) restrict', 'service_id -> services(id) restrict'])
            ->and($foreign('commissions'))->toBe(['credit_transaction_id -> transactions(id) restrict',
                'credit_transaction_id,wallet_id -> transactions(id,wallet_id) restrict', 'purchase_id -> purchases(id) restrict',
                'referrer_id -> users(id) restrict', 'wallet_id -> wallets(id) restrict'])
            ->and($foreign('commission_actions'))->toBe(['acted_by -> system_users(id) restrict', 'commission_id -> commissions(id) restrict',
                'commission_id,wallet_id -> commissions(id,wallet_id) restrict', 'reversal_transaction_id -> transactions(id) restrict',
                'reversal_transaction_id,wallet_id -> transactions(id,wallet_id) restrict', 'wallet_id -> wallets(id) restrict'])
            ->and($foreign('failed_commission_attempts'))->toBe(['purchase_id -> purchases(id) restrict', 'referrer_id -> users(id) restrict']);
    });

    it('enforces the referral, code and setting keys in the database itself', function () {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $other = User::factory()->create();
        DB::table('referrals')->insert(['referrer_id' => $referrer->id, 'referred_user_id' => $referred->id]);

        expect(fn () => DB::table('referrals')->insert(['referrer_id' => $other->id, 'referred_user_id' => $referred->id]))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: referrals.referred_user_id')
            ->and(fn () => DB::table('referrals')->insert(['referrer_id' => 999_999, 'referred_user_id' => $other->id]))
            ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('users')->where('id', $referrer->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('users')->where('id', $referred->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

        $code = ReferralCodes::generate();
        DB::table('referral_codes')->insert(['user_id' => $other->id, 'code' => $code]);
        expect(fn () => DB::table('referral_codes')->insert(['user_id' => $referrer->id, 'code' => $code]))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: referral_codes.code')
            ->and(fn () => DB::table('referral_codes')->insert(['user_id' => $other->id, 'code' => 'ZZZZ2345']))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: referral_codes.user_id')
            ->and(fn () => DB::table('users')->where('id', $other->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

        $setting = rftSetting();
        expect(fn () => DB::table('commission_settings')->insert(['service_id' => $setting->service_id, 'rate_bps' => 1, 'cap_kobo' => 1,
            'updated_by' => $setting->updated_by]))->toThrow(QueryException::class, 'UNIQUE constraint failed: commission_settings.service_id')
            ->and(fn () => DB::table('services')->where('id', $setting->service_id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('system_users')->where('id', $setting->updated_by)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
    });

    it('enforces the commission, action and failed-attempt keys in the database itself', function () {
        $staff = SystemUser::factory()->create();
        [$referrer, $buyer, $purchase] = rftReferredPurchase();
        [$referrer2, , $purchase2] = rftReferredPurchase();
        $commission = tap(rftCommission($referrer, $purchase))->save();
        $row = collect((array) DB::table('commissions')->find($commission->id))->except('id')->all();
        $credit = fn () => rftCredit($referrer, $commission->amount_kobo)->id;

        // One commission per purchase, per credit and per reference; the credit must be on the commission's wallet.
        expect(fn () => DB::table('commissions')->insert(['reference' => WalletService::reference('COM'), 'credit_transaction_id' => $credit()] + $row))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commissions.purchase_id')
            ->and(fn () => DB::table('commissions')->insert(['reference' => WalletService::reference('COM'), 'purchase_id' => $purchase2->id] + $row))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commissions.credit_transaction_id')
            ->and(fn () => DB::table('commissions')->insert(['purchase_id' => $purchase2->id, 'credit_transaction_id' => $credit()] + $row))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commissions.reference')
            ->and(fn () => DB::table('commissions')->insert(['reference' => WalletService::reference('COM'), 'purchase_id' => $purchase2->id,
                'credit_transaction_id' => $purchase2->debit_transaction_id] + $row))
            ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('transactions')->where('id', $commission->credit_transaction_id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('purchases')->where('id', $purchase->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

        // One action per commission and per token; the action and its debit must be on the commission's wallet.
        $action = tap(rftReversal($commission, $staff))->save();
        $actionRow = collect((array) DB::table('commission_actions')->find($action->id))->except('id')->all();
        $commission2 = tap(rftCommission($referrer2, $purchase2))->save();
        $buyerWallet = Wallet::where('user_id', $buyer->id)->value('id');
        expect(fn () => DB::table('commission_actions')->insert(['reference' => WalletService::reference('CMA'), 'idempotency_key' => (string) Str::uuid(),
            'type' => 'cancellation', 'reversal_transaction_id' => null] + $actionRow))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commission_actions.commission_id')
            ->and(fn () => DB::table('commission_actions')->insert(['reference' => WalletService::reference('CMA'), 'commission_id' => $commission2->id,
                'wallet_id' => $commission2->wallet_id, 'type' => 'cancellation', 'reversal_transaction_id' => null] + $actionRow))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commission_actions.idempotency_key')
            ->and(fn () => DB::table('commission_actions')->insert(['reference' => WalletService::reference('CMA'), 'commission_id' => $commission2->id,
                'wallet_id' => $commission2->wallet_id, 'idempotency_key' => (string) Str::uuid()] + $actionRow))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: commission_actions.reversal_transaction_id')
            ->and(fn () => DB::table('commission_actions')->insert(['reference' => WalletService::reference('CMA'), 'commission_id' => $commission2->id,
                'wallet_id' => $buyerWallet, 'idempotency_key' => (string) Str::uuid(), 'type' => 'cancellation', 'reversal_transaction_id' => null] + $actionRow))
            ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('commission_actions')->insert(['reference' => WalletService::reference('CMA'), 'commission_id' => $commission2->id,
                'wallet_id' => $commission2->wallet_id, 'idempotency_key' => (string) Str::uuid(), 'reversal_transaction_id' => $purchase2->debit_transaction_id] + $actionRow))
            ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('commissions')->where('id', $commission->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed')
            ->and(fn () => DB::table('system_users')->where('id', $staff->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

        // One failed attempt per purchase, for an existing purchase and customer.
        [$referrer3, , $purchase3] = rftReferredPurchase();
        DB::table('failed_commission_attempts')->insert(['purchase_id' => $purchase3->id, 'referrer_id' => $referrer3->id, 'reason_code' => 'wallet_refused']);
        expect(fn () => DB::table('failed_commission_attempts')->insert(['purchase_id' => $purchase3->id, 'referrer_id' => $referrer3->id, 'reason_code' => 'unexpected_error']))
            ->toThrow(QueryException::class, 'UNIQUE constraint failed: failed_commission_attempts.purchase_id')
            ->and(fn () => DB::table('failed_commission_attempts')->insert(['purchase_id' => 999_999, 'referrer_id' => $referrer3->id, 'reason_code' => 'wallet_refused']))
            ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');
    });
});

describe('guards', function () {
    it('keeps codes, links, history, commissions, actions and failed attempts unchangeable and undeletable', function () {
        $staff = SystemUser::factory()->create();
        [$referrer, $buyer, $purchase] = rftReferredPurchase();
        $code = tap((new ReferralCode)->forceFill(['user_id' => $referrer->id, 'code' => ReferralCodes::generate()]))->save();
        $link = Referral::where('referred_user_id', $buyer->id)->sole();
        $setting = rftSetting('data', 250, 100_000, $staff);
        $history = tap(rftChange($setting))->save();
        $commission = tap(rftCommission($referrer, $purchase))->save();
        $action = tap(rftCancellation($commission, $staff))->save();
        [$referrer2, , $purchase2] = rftReferredPurchase('airtime'); // no Airtime rate: CP4 pays it nothing, so its failed attempt can be built here
        $attempt = tap(rftAttempt($purchase2, $referrer2))->save();
        $rows = rftRows();

        foreach ([
            [$code, ['code' => ReferralCodes::generate()]],
            [$link, ['referrer_id' => rftCustomer()->id]],
            [$history, ['reason' => 'A different reason for this change.']],
            [$commission, ['amount_kobo' => 1]],
            [$action, ['reason' => 'A different reason for this action.']],
            [$attempt, ['reason_code' => CommissionFailureReason::UnexpectedError]],
        ] as [$model, $attributes]) {
            expect(fn () => $model->forceFill($attributes)->save())->toThrow(LogicException::class)
                ->and(fn () => $model->fresh()->delete())->toThrow(LogicException::class);
        }
        expect(rftRows())->toBe($rows);

        // A setting's values change (CP2 does, with history), but never its service, and it is never deleted.
        $setting->forceFill(['rate_bps' => 300, 'cap_kobo' => 50_000])->save();
        expect($setting->fresh()->only(['rate_bps', 'cap_kobo']))->toBe(['rate_bps' => 300, 'cap_kobo' => 50_000])
            ->and(fn () => $setting->fresh()->forceFill(['service_id' => Service::factory()->create()->id])->save())
            ->toThrow(LogicException::class, 'A commission setting always belongs to the same service.')
            ->and(fn () => $setting->fresh()->delete())->toThrow(LogicException::class, 'Commission settings are never deleted.')
            ->and(CommissionSetting::sole()->service_id)->toBe(Service::where('slug', 'data')->value('id'));
    });

    it('refuses a malformed referral code', function (string $code) {
        expect(fn () => (new ReferralCode)->forceFill(['user_id' => rftCustomer()->id, 'code' => $code])->save())
            ->toThrow(LogicException::class, 'A referral code is 8 upper-case characters from the referral code alphabet.')
            ->and(ReferralCode::count())->toBe(0);
    })->with([
        'lower case' => 'abcd2345', 'seven characters' => 'ABCD234', 'nine characters' => 'ABCD23456', 'the letter O' => 'ABCD2O45',
        'the letter I' => 'ABCD2I45', 'the digit 0' => 'ABCD2045', 'the digit 1' => 'ABCD2145', 'a space' => 'ABCD 345', 'a dash' => 'ABCD-345',
        'a non-Latin letter' => 'ABCDÉ345', 'empty' => '',
    ]);

    it('creates a code only for a Subscriber, Vendor or Affiliate, never for an API User', function () {
        foreach ([UserType::Subscriber, UserType::Vendor, UserType::Affiliate] as $type) {
            (new ReferralCode)->forceFill(['user_id' => rftCustomer($type)->id, 'code' => ReferralCodes::generate()])->save();
        }

        expect(fn () => (new ReferralCode)->forceFill(['user_id' => rftCustomer(UserType::ApiUser)->id, 'code' => ReferralCodes::generate()])->save())
            ->toThrow(LogicException::class, 'A referral code is created only for a Subscriber, Vendor or Affiliate.')
            ->and(fn () => (new ReferralCode)->forceFill(['user_id' => 999_999, 'code' => ReferralCodes::generate()])->save())
            ->toThrow(LogicException::class, 'A referral code is created only for a Subscriber, Vendor or Affiliate.')
            ->and(ReferralCode::count())->toBe(3);
    });

    it('refuses self-referral and a link without both customers', function () {
        $customer = rftCustomer();

        expect(fn () => (new Referral)->forceFill(['referrer_id' => $customer->id, 'referred_user_id' => $customer->id])->save())
            ->toThrow(LogicException::class, 'A customer can never refer themselves.')
            ->and(fn () => (new Referral)->forceFill(['referrer_id' => $customer->id])->save())
            ->toThrow(LogicException::class, 'A referral links a referrer and the customer they referred.')
            ->and(fn () => (new Referral)->forceFill(['referred_user_id' => $customer->id])->save())
            ->toThrow(LogicException::class, 'A referral links a referrer and the customer they referred.')
            ->and(Referral::count())->toBe(0);
    });

    it('keeps a commission setting between 0 and 9999 basis points, with a cap of 0 kobo or more', function () {
        $staff = SystemUser::factory()->create();
        $service = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
        $make = fn (?int $rate, ?int $cap) => (new CommissionSetting)->forceFill(['service_id' => $service->id, 'rate_bps' => $rate, 'cap_kobo' => $cap,
            'updated_by' => $staff->id]);

        foreach ([[10_000, 100], [-1, 100], [null, 100]] as [$rate, $cap]) {
            expect(fn () => $make($rate, $cap)->save())->toThrow(LogicException::class, 'A commission rate is 0 to 9999 basis points (0–99.99%).');
        }
        foreach ([[250, -1], [250, null]] as [$rate, $cap]) {
            expect(fn () => $make($rate, $cap)->save())->toThrow(LogicException::class, 'A commission cap is a whole number of kobo, 0 or more.');
        }
        expect(CommissionSetting::count())->toBe(0);

        $make(0, 0)->save(); // 0 is allowed and means no commission
        $setting = CommissionSetting::sole();
        expect($setting->only(['rate_bps', 'cap_kobo']))->toBe(['rate_bps' => 0, 'cap_kobo' => 0])
            ->and(fn () => $setting->forceFill(['rate_bps' => 10_000])->save())->toThrow(LogicException::class, 'A commission rate is 0 to 9999 basis points (0–99.99%).');
        $setting->fresh()->forceFill(['rate_bps' => 9_999, 'cap_kobo' => 1])->save();
        expect(CommissionSetting::sole()->only(['rate_bps', 'cap_kobo']))->toBe(['rate_bps' => 9_999, 'cap_kobo' => 1]);
    });

    it('records a rate and cap change only as the values the setting now has, with a reason of 10 to 500 characters and its staff member', function () {
        $setting = rftSetting('data', 250, 100_000);
        $other = rftSetting('airtime', 100, 5_000);
        $sameValues = 'A rate and cap change records the values the setting now has.';
        $pairs = "The old rate and cap are both recorded, or both empty on a service's first save.";
        $reason = 'A rate or cap change needs a reason of 10 to 500 characters.';

        foreach ([
            [['new_rate_bps' => 300], $sameValues],
            [['new_cap_kobo' => 1], $sameValues],
            [['service_id' => $other->service_id], "A rate and cap change belongs to its setting and that setting's service."],
            [['commission_setting_id' => 999_999], "A rate and cap change belongs to its setting and that setting's service."],
            [['old_rate_bps' => 200], $pairs],
            [['old_cap_kobo' => 200], $pairs],
            [['old_rate_bps' => 10_000, 'old_cap_kobo' => 1], 'A commission rate is 0 to 9999 basis points (0–99.99%).'],
            [['reason' => str_repeat('a', 9)], $reason],
            [['reason' => str_repeat('a', 501)], $reason],
            [['reason' => null], $reason],
            [['changed_by' => null], 'A rate or cap change records the staff member who made it.'],
        ] as [$overrides, $message]) {
            expect(fn () => rftChange($setting, $overrides)->save())->toThrow(LogicException::class, $message);
        }
        expect(CommissionSettingChange::count())->toBe(0);

        rftChange($setting, ['reason' => str_repeat('é', 10)])->save(); // exactly 10 characters (20 bytes)
        rftChange($setting, ['old_rate_bps' => 200, 'old_cap_kobo' => 50_000, 'reason' => str_repeat('a', 500)])->save();
        expect(CommissionSettingChange::count())->toBe(2);
    });

    it('accepts a commission that is the capped, rounded-down rate of its buyer\'s successful qualifying purchase, credited to their referrer', function () {
        [$referrer, , $purchase] = rftReferredPurchase('data', 50_001);
        $commission = tap(rftCommission($referrer, $purchase, 250, 100_000))->save()->fresh();
        [$referrer2, , $airtime] = rftReferredPurchase('airtime', 99_900);
        $capped = tap(rftCommission($referrer2, $airtime, 9_999, 1_000))->save()->fresh();

        expect($commission->only(['purchase_id', 'referrer_id', 'base_amount_kobo', 'rate_bps', 'cap_kobo', 'amount_kobo']))->toBe([
            'purchase_id' => $purchase->id, 'referrer_id' => $referrer->id, 'base_amount_kobo' => 50_001, 'rate_bps' => 250, 'cap_kobo' => 100_000,
            'amount_kobo' => 1_250, // 2.5% of ₦500.01 = 1,250.025 kobo, rounded down
        ])
            ->and($commission->status())->toBe(CommissionStatus::Credited)
            ->and($commission->creditTransaction->only(['type', 'direction', 'amount_kobo']))
            ->toBe(['type' => TransactionType::Commission, 'direction' => Direction::Credit, 'amount_kobo' => 1_250])
            ->and($commission->wallet->user_id)->toBe($referrer->id)
            ->and($capped->amount_kobo)->toBe(1_000) // 99.99% of ₦999 = 99,890.01 kobo, capped at ₦10.00
            ->and(Commission::count())->toBe(2);
    });

    it('refuses a commission that does not match its purchase, referrer, wallet or credit', function (Closure $variant, string $message) {
        $commission = $variant();

        expect(fn () => $commission->save())->toThrow(LogicException::class, $message)
            ->and(Commission::count())->toBe(0);
    })->with([
        'a malformed reference' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['reference' => 'COM-NOT-A-ULID']);
        }, 'A commission reference is COM- followed by a ULID.'],
        'a pending purchase' => [function () {
            [$r, , $p] = rftReferredPurchase('data', 50_001, 'unknown');

            return rftCommission($r, $p);
        }, 'A commission belongs to a successful purchase.'],
        'a failed purchase' => [function () {
            [$r, , $p] = rftReferredPurchase('data', 50_001, 'failed_definite');

            return rftCommission($r, $p);
        }, 'A commission belongs to a successful purchase.'],
        'a service that does not qualify' => [function () {
            [$r, , $p] = rftReferredPurchase('cable-tv');

            return rftCommission($r, $p);
        }, 'Only purchases of the qualifying services earn commission.'],
        'another customer as referrer' => [function () {
            [, , $p] = rftReferredPurchase();

            return rftCommission(rftCustomer(), $p);
        }, 'A commission goes to the buyer\'s own referrer, never to the buyer.'],
        'the buyer as referrer' => [function () {
            [, $b, $p] = rftReferredPurchase();

            return rftCommission($b, $p);
        }, 'A commission goes to the buyer\'s own referrer, never to the buyer.'],
        'a purchase with a failed attempt' => [function () {
            [$r, , $p] = rftReferredPurchase();
            rftAttempt($p, $r)->save();

            return rftCommission($r, $p);
        }, 'A purchase with a failed commission attempt has no commission.'],
        'a base that is not the purchase amount' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['base_amount_kobo' => 40_000, 'amount_kobo' => 1_000]);
        }, 'A commission is calculated from the purchase amount.'],
        'an amount rounded up' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['amount_kobo' => 1_251]);
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'an amount above its cap' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 1_000, ['amount_kobo' => 1_250]);
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'a rate of 0' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 0, 100_000);
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'a rate above 99.99%' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 10_000, 100_000);
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'a cap of 0' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 0);
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'an amount that rounds down to 0 kobo' => [function () {
            [$r, , $p] = rftReferredPurchase('data', 199);

            return rftCommission($r, $p, 50, 100_000); // 0.5% of 199 kobo = 0.995 kobo
        }, 'A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.'],
        'another customer\'s wallet' => [function () {
            [$r, $b, $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['wallet_id' => Wallet::where('user_id', $b->id)->value('id')]);
        }, 'A commission is credited to the referrer\'s Main Wallet.'],
        'a credit on another wallet' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['credit_transaction_id' => rftCredit(rftCustomer(), 1_250)->id]);
        }, 'A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.'],
        'a credit of another amount' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['credit_transaction_id' => rftCredit($r, 1_000)->id]);
        }, 'A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.'],
        'an adjustment instead of a commission credit' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['credit_transaction_id' => rftCredit($r, 1_250, TransactionType::Adjustment, LedgerEntryType::AdjustmentCredit)->id]);
        }, 'A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.'],
        'a commission transaction with another entry type' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['credit_transaction_id' => rftCredit($r, 1_250, TransactionType::Commission, LedgerEntryType::AdjustmentCredit)->id]);
        }, 'A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.'],
        'a debit instead of a credit' => [function () {
            [$r, , $p] = rftReferredPurchase();
            rftCredit($r, 5_000, TransactionType::Adjustment, LedgerEntryType::AdjustmentCredit);
            $wallets = app(WalletService::class);
            $debit = $wallets->debit($wallets->walletFor($r), 1_250, LedgerEntryType::CommissionReversal, TransactionType::Commission, 'Commission reversal', 'test:'.Str::uuid());

            return rftCommission($r, $p, 250, 100_000, ['credit_transaction_id' => $debit->transaction->id]);
        }, 'A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.'],
        'no time of credit' => [function () {
            [$r, , $p] = rftReferredPurchase();

            return rftCommission($r, $p, 250, 100_000, ['credited_at' => null]);
        }, 'A commission records when it was credited.'],
    ]);

    it('allows one action ever: a reversal with its own separate debit, or a cancellation without one', function () {
        $staff = SystemUser::factory()->create();
        $reversed = rftSavedCommission();
        $credit = Transaction::findOrFail($reversed->credit_transaction_id)->getAttributes();
        $reversal = tap(rftReversal($reversed, $staff))->save();
        $cancelled = rftSavedCommission();
        tap(rftCancellation($cancelled, $staff))->save();

        expect($reversed->fresh()->status())->toBe(CommissionStatus::Reversed)
            ->and($cancelled->fresh()->status())->toBe(CommissionStatus::Cancelled)
            ->and(Transaction::findOrFail($reversed->credit_transaction_id)->getAttributes())->toBe($credit) // the original credit is untouched
            ->and($reversal->reversalTransaction->only(['type', 'direction', 'amount_kobo']))
            ->toBe(['type' => TransactionType::Commission, 'direction' => Direction::Debit, 'amount_kobo' => $reversed->amount_kobo])
            ->and(Transaction::where('wallet_id', $cancelled->wallet_id)->count())->toBe(1); // a cancellation moves no money: only its credit

        foreach ([[$reversed, fn () => rftCancellation($reversed, $staff)], [$cancelled, fn () => rftReversal($cancelled, $staff)],
            [$cancelled, fn () => rftCancellation($cancelled, $staff)]] as [$commission, $second]) {
            expect(fn () => $second()->save())->toThrow(LogicException::class, 'A commission can have only one action, ever.');
        }
        expect(CommissionAction::count())->toBe(2);
    });

    it('refuses an action that does not match its commission, debit, reason, token or staff member', function (Closure $variant, string $message) {
        $action = $variant(SystemUser::factory()->create());

        expect(fn () => $action->save())->toThrow(LogicException::class, $message)
            ->and(CommissionAction::count())->toBe(0);
    })->with([
        'a malformed reference' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['reference' => 'CMA-1']),
            'A commission action reference is CMA- followed by a ULID.'],
        'no commission' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['commission_id' => 999_999]),
            'A commission action belongs to a commission.'],
        'another wallet' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['wallet_id' => rftSavedCommission()->wallet_id]),
            'A commission action is on the commission\'s own wallet.'],
        'a reason of 9 characters' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['reason' => str_repeat('a', 9)]),
            'A commission action needs a reason of 10 to 500 characters.'],
        'a reason of 501 characters' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['reason' => str_repeat('a', 501)]),
            'A commission action needs a reason of 10 to 500 characters.'],
        'no token' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['idempotency_key' => null]),
            'A commission action keeps its one-time form token (a UUID).'],
        'a token that is not a UUID' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['idempotency_key' => str_repeat('a', 36)]),
            'A commission action keeps its one-time form token (a UUID).'],
        'no staff member' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['acted_by' => null]),
            'A commission action records the staff member who took it.'],
        'no type' => [fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s, ['type' => null]),
            'A commission action is a reversal or a cancellation.'],
        'a cancellation that moves money' => [function (SystemUser $s) {
            $commission = rftSavedCommission();

            return rftCancellation($commission, $s, ['reversal_transaction_id' => rftReversalDebit($commission, $s)->id]);
        }, 'A cancellation moves no money, so it has no wallet transaction.'],
        'a reversal without a debit' => [fn (SystemUser $s) => rftReversal(rftSavedCommission(), $s, ['reversal_transaction_id' => null]),
            'A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.'],
        'a reversal pointing at the original credit' => [function (SystemUser $s) {
            $commission = rftSavedCommission();

            return rftReversal($commission, $s, ['reversal_transaction_id' => $commission->credit_transaction_id]);
        }, 'A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.'],
        'a partial reversal' => [function (SystemUser $s) {
            $commission = rftSavedCommission();

            return rftReversal($commission, $s, ['reversal_transaction_id' => rftReversalDebit($commission, $s, $commission->amount_kobo - 1)->id]);
        }, 'A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.'],
        'a reversal debit with another entry type' => [function (SystemUser $s) {
            $commission = rftSavedCommission();

            return rftReversal($commission, $s, ['reversal_transaction_id' => rftReversalDebit($commission, $s, null, LedgerEntryType::AdjustmentDebit)->id]);
        }, 'A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.'],
        'a reversal debit on another wallet' => [function (SystemUser $s) {
            $other = rftSavedCommission();

            return rftReversal(rftSavedCommission(), $s, ['reversal_transaction_id' => rftReversalDebit($other, $s)->id]);
        }, 'A reversal is one separate, successful commission reversal debit of the commission amount on its wallet.'],
    ]);

    it('records a failed attempt only for a successful purchase of the buyer\'s own referrer, and never next to a commission', function () {
        foreach (CommissionFailureReason::cases() as $reason) {
            [$referrer, , $purchase] = rftReferredPurchase();
            rftAttempt($purchase, $referrer, $reason)->save();
        }
        expect(FailedCommissionAttempt::pluck('reason_code')->all())->toBe(CommissionFailureReason::cases());

        [$referrer, , $pending] = rftReferredPurchase('data', 50_001, 'unknown');
        [, , $other] = rftReferredPurchase();
        $commission = rftSavedCommission();
        expect(fn () => rftAttempt($pending, $referrer)->save())->toThrow(LogicException::class, 'A failed commission attempt belongs to a successful purchase.')
            ->and(fn () => rftAttempt($other, rftCustomer())->save())->toThrow(LogicException::class, 'A failed commission attempt names the buyer\'s own referrer.')
            ->and(fn () => rftAttempt(Purchase::findOrFail($commission->purchase_id), User::findOrFail($commission->referrer_id))->save())
            ->toThrow(LogicException::class, 'A purchase with a commission has no failed commission attempt.')
            ->and(fn () => (new FailedCommissionAttempt)->forceFill(['reason_code' => 'insufficient_mood']))->toThrow(ValueError::class)
            ->and(fn () => (new FailedCommissionAttempt)->forceFill(['purchase_id' => $other->id, 'referrer_id' => $referrer->id])->save())
            ->toThrow(LogicException::class, 'A failed commission attempt has one of the fixed reason codes.')
            ->and(FailedCommissionAttempt::count())->toBe(count(CommissionFailureReason::cases()));
    });
});

describe('enums and helpers', function () {
    it('adds the commission transaction type and ledger entry types, with neutral labels that fit their columns', function () {
        expect(TransactionType::Commission->value)->toBe('commission')
            ->and(TransactionType::Commission->label())->toBe('Referral commission')
            ->and(LedgerEntryType::CommissionCredit->value)->toBe('commission_credit')
            ->and(LedgerEntryType::CommissionCredit->label())->toBe('Referral commission')
            ->and(LedgerEntryType::CommissionReversal->value)->toBe('commission_reversal')
            ->and(LedgerEntryType::CommissionReversal->label())->toBe('Commission reversal')
            ->and(array_map(fn (TransactionType $type) => $type->value, TransactionType::cases()))->toBe(['adjustment', 'funding', 'purchase', 'commission'])
            ->and(max(array_map(fn (TransactionType $type) => strlen($type->value), TransactionType::cases())))->toBeLessThanOrEqual(20)
            ->and(max(array_map(fn (LedgerEntryType $type) => strlen($type->value), LedgerEntryType::cases())))->toBeLessThanOrEqual(30);
    });

    it('derives a commission status from its single action, and fixes the four failure reason codes', function () {
        expect(CommissionActionType::Reversal->status())->toBe(CommissionStatus::Reversed)
            ->and(CommissionActionType::Cancellation->status())->toBe(CommissionStatus::Cancelled)
            ->and(array_map(fn (CommissionStatus $s) => $s->label(), CommissionStatus::cases()))->toBe(['Credited', 'Reversed', 'Cancelled'])
            ->and(array_map(fn (CommissionActionType $t) => $t->value, CommissionActionType::cases()))->toBe(['reversal', 'cancellation'])
            ->and(CommissionFailureReason::values())->toBe(['wallet_balance_limit', 'wallet_unavailable', 'wallet_refused', 'unexpected_error'])
            ->and(array_filter(array_map(fn (CommissionFailureReason $r) => $r->label(), CommissionFailureReason::cases())))->toHaveCount(4);
    });

    it('generates codes from the 32-character alphabet, without 0, O, 1 or I', function () {
        $codes = collect(range(1, 500))->map(fn () => ReferralCodes::generate());

        expect(ReferralCodes::ALPHABET)->toHaveLength(32)
            ->and(count(array_unique(str_split(ReferralCodes::ALPHABET))))->toBe(32)
            ->and(array_intersect(str_split(ReferralCodes::ALPHABET), ['0', 'O', '1', 'I']))->toBe([])
            ->and(str_split(ReferralCodes::ALPHABET))->toBe([...array_diff(range('A', 'Z'), ['I', 'O']), ...array_map('strval', range(2, 9))])
            ->and($codes->every(fn (string $code) => strlen($code) === 8 && ReferralCodes::isWellFormed($code)))->toBeTrue()
            ->and($codes->unique()->count())->toBe(500)
            ->and(collect(str_split($codes->implode('')))->unique()->sort()->values()->all())->toBe(collect(str_split(ReferralCodes::ALPHABET))->sort()->values()->all());
    });

    it('normalises a typed code by trimming and upper-casing only', function () {
        expect(ReferralCodes::normalise('  abcd2345 '))->toBe('ABCD2345')
            ->and(ReferralCodes::isWellFormed(ReferralCodes::normalise('  abcd2345 ')))->toBeTrue()
            ->and(ReferralCodes::normalise('abcd 2345'))->toBe('ABCD 2345')
            ->and(ReferralCodes::normalise('abcd2o45'))->toBe('ABCD2O45') // no look-alike mapping: O stays O, so it is not a valid code
            ->and(ReferralCodes::normalise('abcd2i45'))->toBe('ABCD2I45')
            ->and(ReferralCodes::normalise(null))->toBe('')
            ->and(collect(['ABCD 2345', 'ABCD2O45', 'ABCD2I45', '', 'abcd2345'])->contains(fn (string $code) => ReferralCodes::isWellFormed($code)))->toBeFalse();
    });

    it('decides who takes part from the current type and status', function () {
        $user = fn (UserType $type, UserStatus $status = UserStatus::Active) => User::factory()->ofType($type)->make(['status' => $status]);

        foreach ([UserType::Subscriber, UserType::Vendor, UserType::Affiliate] as $type) {
            expect(ReferralEligibility::canRefer($user($type)))->toBeTrue()
                ->and(ReferralEligibility::isActiveReferrer($user($type)))->toBeTrue()
                ->and(ReferralEligibility::isActiveReferrer($user($type, UserStatus::Disabled)))->toBeFalse()
                ->and(ReferralEligibility::buyerGeneratesCommission($user($type)))->toBeTrue();
        }
        expect(ReferralEligibility::canRefer($user(UserType::ApiUser)))->toBeFalse()
            ->and(ReferralEligibility::isActiveReferrer($user(UserType::ApiUser)))->toBeFalse()
            ->and(ReferralEligibility::buyerGeneratesCommission($user(UserType::ApiUser)))->toBeFalse();
    });

    it('lists exactly the five qualifying services', function () {
        expect(QualifyingServices::SLUGS)->toBe(['data', 'airtime', 'nin', 'bvn', 'exam-pin']);
        foreach (['smile-data', 'referral-and-commission', 'cable-tv', 'electricity', 'withdraw', 'Data', '', null] as $slug) {
            expect(QualifyingServices::includes($slug))->toBeFalse((string) $slug);
        }
    });

    it('calculates a commission as the rate of the amount, then the cap, rounded down', function (int $base, int $rate, int $cap, int $amount) {
        expect(Commission::amountFor($base, $rate, $cap))->toBe($amount);
    })->with([
        '2.5% of ₦500' => [50_000, 250, 100_000, 1_250],
        'rounded down' => [999, 250, 100_000, 24],            // 24.975 kobo
        'the cap, before rounding' => [50_001, 9_999, 1_000, 1_000],
        'exactly the cap' => [40_000, 250, 1_000, 1_000],
        'rounds down to 0' => [199, 50, 100_000, 0],         // 0.995 kobo: nothing payable
        'a rate of 0' => [50_000, 0, 100_000, 0],
        'a cap of 0' => [50_000, 250, 0, 0],
        'the largest purchase a wallet can pay' => [WalletService::MAX_BALANCE_KOBO, 9_999, WalletService::MAX_BALANCE_KOBO, 99_990_000_000_000],
    ]);
});

describe('BusinessTime::thisMonth', function () {
    it('is the current calendar month in the Business timezone, in app time', function (string $now, string $start, string $end) {
        Carbon::setTestNow(CarbonImmutable::parse($now, 'UTC'));
        [$from, $to] = BusinessTime::thisMonth();

        expect($from->getTimezone()->getName())->toBe(config('app.timezone'))
            ->and($from->format('Y-m-d H:i:s'))->toBe($start)
            ->and($to->format('Y-m-d H:i:s'))->toBe($end);
    })->with([
        'mid-month' => ['2026-10-15 12:00:00', '2026-09-30 23:00:00', '2026-10-31 23:00:00'],
        'the first second of the Lagos month' => ['2026-09-30 23:00:00', '2026-09-30 23:00:00', '2026-10-31 23:00:00'],
        'the last second of the Lagos month' => ['2026-10-31 22:59:59', '2026-09-30 23:00:00', '2026-10-31 23:00:00'],
        'before midnight UTC, already the next month in Lagos' => ['2026-10-31 23:30:00', '2026-10-31 23:00:00', '2026-11-30 23:00:00'],
        'a new year in Lagos' => ['2026-12-31 23:00:00', '2026-12-31 23:00:00', '2027-01-31 23:00:00'],
        'a leap-year February' => ['2028-02-29 12:00:00', '2028-01-31 23:00:00', '2028-02-29 23:00:00'],
    ]);

    it('follows the Business timezone setting, including daylight saving time, and falls back to Africa/Lagos when it is not valid', function () {
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-29 12:00:00', 'UTC'));
        $month = fn () => array_map(fn (CarbonImmutable $at) => $at->format('Y-m-d H:i:s'), BusinessTime::thisMonth());

        expect($month())->toBe(['2026-02-28 23:00:00', '2026-03-31 23:00:00']);
        app(SettingsStore::class)->set('app.timezone', 'UTC');
        expect($month())->toBe(['2026-03-01 00:00:00', '2026-04-01 00:00:00']);
        app(SettingsStore::class)->set('app.timezone', 'Europe/London'); // March starts in GMT and ends in BST
        expect($month())->toBe(['2026-03-01 00:00:00', '2026-03-31 23:00:00']);
        app(SettingsStore::class)->set('app.timezone', 'Mars/Olympus_Mons');
        expect($month())->toBe(['2026-02-28 23:00:00', '2026-03-31 23:00:00']);
    });
});

describe('scope', function () {
    it('seeds nothing: no rates, caps, codes, referrals, commissions, attempts or money, and no new permission', function () {
        $this->seed();

        foreach (RFT_TABLES as $table) {
            expect(DB::table($table)->count())->toBe(0, "{$table} was seeded");
        }
        expect(Wallet::count())->toBe(0)
            ->and(Transaction::count())->toBe(0)
            ->and(SystemPermission::cases())->toHaveCount(46)
            ->and(Permission::where('guard_name', 'admin')->pluck('name')->sort()->values()->all())->toBe(collect(SystemPermission::values())->sort()->values()->all())
            ->and(Permission::whereIn('name', ['referrals.view', 'referrals.manage'])->count())->toBe(2);
    });

    it('adds only the approved referral routes, module and menu item (CP2 admin module, CP3 Referral page and Referrals tab, CP5 commission page)', function () {
        $referralRoutes = collect(Route::getRoutes())->filter(fn ($route) => preg_match('/referral|commission/i', $route->uri().' '.$route->getName()))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();

        expect($referralRoutes)->toBe([
            'GET|HEAD referrals', 'GET|HEAD admin/referrals', 'GET|HEAD admin/referrals/commissions/{commission}',
            'POST admin/referrals/commissions/{commission}/reverse', 'POST admin/referrals/commissions/{commission}/cancel', // CP5
            'GET|HEAD admin/referrals/links', 'GET|HEAD admin/referrals/failed', 'GET|HEAD admin/referrals/rates', // F2: read-only Failed attempts tab
            'GET|HEAD admin/referrals/rates/{service}/edit', 'PUT admin/referrals/rates/{service}',
        ])
            ->and(AdminModule::Referrals->isBuilt())->toBeTrue()
            ->and(AdminModule::Referrals->plannedPhase())->toBeNull()
            ->and(array_values(array_filter(CustomerNav::cases(), fn (CustomerNav $item) => str_contains($item->value, 'referral'))))->toBe([CustomerNav::Referrals]);
    });

    it('never writes the append-only tables in bulk, which would skip their guards (T7)', function () {
        $tables = ['referral_codes', 'referrals', 'commission_setting_changes', 'commissions', 'commission_actions', 'failed_commission_attempts'];
        $models = ['ReferralCode', 'Referral', 'CommissionSettingChange', 'Commission', 'CommissionAction', 'FailedCommissionAttempt'];
        $bulk = '(?:update|delete|forceDelete|insert|insertOrIgnore|insertGetId|insertUsing|upsert|updateOrInsert|increment|decrement|incrementEach|decrementEach|truncate)';
        $patterns = [
            ...array_map(fn (string $table) => "/DB::table\\(\\s*['\"]{$table}['\"]/", $tables),
            ...array_map(fn (string $table) => "/\\b(?:update|delete\\s+from|insert\\s+into|replace\\s+into|truncate(?:\\s+table)?)\\s+[`\"]?{$table}\\b/i", $tables),
            ...array_map(fn (string $model) => "/\\b{$model}::(?:[^;]*->)?{$bulk}\\s*\\(/s", $models),
            "/->(?:action|changes)\\(\\)[^;]*->{$bulk}\\s*\\(/s",
        ];
        $found = fn (string $code) => array_values(array_filter($patterns, fn (string $pattern) => preg_match($pattern, $code) === 1));

        // The scan finds every kind of bulk write it looks for (and nothing in ordinary reads).
        foreach (["Commission::where('id', 1)->update(['amount_kobo' => 2]);", 'Referral::query()->delete();', 'ReferralCode::insert([]);',
            "DB::table('commission_actions')->where('id', 1)->update([]);", 'DB::statement("UPDATE commissions SET amount_kobo = 1");',
            "DB::delete('delete from referrals');", '$commission->action()->update([]);', 'FailedCommissionAttempt::upsert([], []);',
            "CommissionSettingChange::where('id', 1)->increment('new_rate_bps');"] as $write) {
            expect($found($write))->not->toBe([], $write);
        }
        foreach (["Commission::where('purchase_id', 1)->exists();", "Referral::where('referred_user_id', 2)->first();",
            '$code = ReferralCode::find(1);', 'self::where(\'commission_id\', 1)->exists();'] as $read) {
            expect($found($read))->toBe([], $read);
        }

        $sources = collect([app_path(), base_path('routes'), config_path(), resource_path(), database_path('seeders'), database_path('factories')])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);
        expect($sources->filter(fn (string $code) => $found($code) !== [])->keys()->all())->toBe([]);
    });

    it('rolls back to exactly the schema before it when empty, and migrates again', function () {
        $before = rftSchema();
        $steps = DB::table('migrations')->where('migration', '>=', RFT_MIGRATION)->count(); // this migration and any later one, newest first

        Artisan::call('migrate:rollback', ['--step' => $steps]);

        expect(rftSchema())->toEqual(array_diff_key($before, array_flip(RFT_TABLES)))
            ->and(DB::table('migrations')->where('migration', RFT_MIGRATION)->exists())->toBeFalse();

        Artisan::call('migrate');

        expect(rftSchema())->toEqual($before)
            ->and(DB::table('migrations')->where('migration', RFT_MIGRATION)->exists())->toBeTrue();
    });

    it('refuses to roll back while any of the tables holds a row, changing nothing', function (string $table, Closure $fill) {
        $fill(SystemUser::factory()->create());
        $schema = rftSchema();
        $rows = rftRows();
        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $message = "Refusing to roll back: {$table} holds referral or commission records, which would be lost. Nothing was changed.";

        expect(DB::table($table)->count())->toBe(1)
            ->and(fn () => (require database_path('migrations/'.RFT_MIGRATION.'.php'))->down())->toThrow(RuntimeException::class, $message)
            ->and(fn () => Artisan::call('migrate:rollback', ['--step' => 1]))->toThrow(RuntimeException::class, $message)
            ->and(rftSchema())->toEqual($schema)
            ->and(rftRows())->toBe($rows)
            ->and(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($migrations);
    })->with([
        'a referral code' => ['referral_codes', fn (SystemUser $s) => (new ReferralCode)->forceFill(['user_id' => rftCustomer()->id, 'code' => ReferralCodes::generate()])->save()],
        'a referral link' => ['referrals', fn (SystemUser $s) => (new Referral)->forceFill(['referrer_id' => rftCustomer()->id, 'referred_user_id' => rftCustomer()->id])->save()],
        'a commission setting' => ['commission_settings', fn (SystemUser $s) => rftSetting('data', 250, 100_000, $s)],
        'a rate and cap change' => ['commission_setting_changes', fn (SystemUser $s) => rftChange(rftSetting('data', 250, 100_000, $s))->save()],
        'a commission' => ['commissions', fn (SystemUser $s) => rftSavedCommission()],
        'a commission action' => ['commission_actions', fn (SystemUser $s) => rftCancellation(rftSavedCommission(), $s)->save()],
        'a failed attempt' => ['failed_commission_attempts', function (SystemUser $s) {
            [$referrer, , $purchase] = rftReferredPurchase();
            rftAttempt($purchase, $referrer)->save();
        }],
    ]);
});
