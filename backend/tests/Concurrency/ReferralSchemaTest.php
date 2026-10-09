<?php

use App\Models\ReferralCode;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Referrals\ReferralCodes;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../Support/Purchases/helpers.php';

/*
 * Phase 12 CP1 on real MariaDB: the CHECK constraints that repeat the model
 * guards (each one rejects invalid rows around its boundaries and accepts the
 * valid ones, including the largest amount a wallet can hold), the column
 * types, the restricting and compound foreign keys, and the migration: a
 * clean rollback while empty, and a refusal before any schema change while a
 * row exists. Rows are written with the query builder on purpose, past the
 * model guards, so the database itself is what is tested. Purchases run
 * through PurchaseService with the test-only FakeProvider.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

const RST_MIGRATION = '2026_10_07_100000_create_referral_and_commission_tables';

const RST_TABLES = ['failed_commission_attempts', 'commission_actions', 'commissions', 'commission_setting_changes', 'commission_settings', 'referrals', 'referral_codes'];

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    puxDrivers();
});

/** @return array<string, list<string>> the CHECK constraints of the seven tables, by table */
function rstChecks(): array
{
    $checks = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->whereIn('TABLE_NAME', RST_TABLES)->get()
        ->groupBy('TABLE_NAME')->map(fn ($rows) => $rows->pluck('CONSTRAINT_NAME')->sort()->values()->all())->all();
    ksort($checks);

    return $checks;
}

/**
 * A referred customer's successful data purchase of ₦500.01; the referral link is written directly.
 *
 * @return array{0: User, 1: User, 2: int} the referrer, the buyer and the purchase id
 */
function rstReferredPurchase(): array
{
    $referrer = User::factory()->create();
    app(WalletService::class)->walletFor($referrer);
    $buyer = puxCustomer(1_000_000);
    DB::table('referrals')->insert(['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id]);
    $plan = puxPlan('data', 50_001);
    puxRoute($plan);
    FakeProvider::$purchaseScript = ['succeeded'];

    return [$referrer, $buyer, puxService()->purchase($buyer, $plan, '08012345678', null, (string) Str::uuid())->id];
}

/** A commission credit (or another posting) on $user's Main Wallet; returns the transaction id. */
function rstPosting(User $user, int $amount, bool $debit = false, LedgerEntryType $entry = LedgerEntryType::CommissionCredit): int
{
    $wallets = app(WalletService::class);
    $wallet = $wallets->walletFor($user);
    $result = $debit
        ? $wallets->debit($wallet, $amount, $entry, TransactionType::Commission, 'Commission reversal', 'test:'.Str::uuid())
        : $wallets->credit($wallet, $amount, $entry, TransactionType::Commission, 'Referral commission', 'test:'.Str::uuid());

    return $result->transaction->id;
}

/** Expects the insert to be refused by the named CHECK constraint. */
function rstRefusedBy(string $constraint, Closure $insert): void
{
    expect($insert)->toThrow(QueryException::class, "CONSTRAINT `{$constraint}` failed");
}

it('has the CHECK constraints, column types and restricting foreign keys, CHECKs on restricted key columns included', function () {
    $type = fn (string $table, string $column) => collect(Schema::getColumns($table))->firstWhere('name', $column)['type'];
    $keys = collect(RST_TABLES)->flatMap(fn (string $table) => Schema::getForeignKeys($table));

    expect(rstChecks())->toBe([
        'commission_actions' => ['commission_actions_type_rule'],
        'commission_setting_changes' => ['commission_setting_changes_values'],
        'commission_settings' => ['commission_settings_rate_range'],
        'commissions' => ['commissions_amount_rule'],
        'failed_commission_attempts' => ['failed_commission_attempts_reason_code'],
        'referral_codes' => ['referral_codes_code_length'],
        'referrals' => ['referrals_not_self'],
    ])
        ->and($type('referral_codes', 'code'))->toBe('char(8)')
        ->and($type('commission_settings', 'rate_bps'))->toBe('smallint(5) unsigned')
        ->and($type('commission_settings', 'cap_kobo'))->toBe('bigint(20) unsigned')
        ->and($type('commissions', 'base_amount_kobo'))->toBe('bigint(20) unsigned')
        ->and($type('commissions', 'rate_bps'))->toBe('smallint(5) unsigned')
        ->and($type('commissions', 'amount_kobo'))->toBe('bigint(20) unsigned')
        ->and($type('commission_setting_changes', 'reason'))->toBe('varchar(500)')
        ->and($type('commission_actions', 'reason'))->toBe('varchar(500)')
        ->and($type('commission_actions', 'type'))->toBe('varchar(12)')
        ->and($type('commission_actions', 'idempotency_key'))->toBe('char(36)')
        ->and($type('failed_commission_attempts', 'reason_code'))->toBe('varchar(40)')
        ->and($keys)->toHaveCount(21)
        ->and($keys->pluck('on_delete')->unique()->values()->all())->toBe(['restrict'])
        ->and($keys->filter(fn (array $key) => count($key['columns']) === 2)->map(fn (array $key) => implode(',', $key['columns']).' -> '.$key['foreign_table'])
            ->sort()->values()->all())->toBe(['commission_id,wallet_id -> commissions', 'credit_transaction_id,wallet_id -> transactions',
                'reversal_transaction_id,wallet_id -> transactions']);
});

it('refuses invalid codes, self-referral, settings and rate and cap history with their CHECK constraints, and accepts the boundaries', function () {
    $staff = SystemUser::factory()->create();
    $customer = User::factory()->create();
    $service = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);

    rstRefusedBy('referral_codes_code_length', fn () => DB::table('referral_codes')->insert(['user_id' => $customer->id, 'code' => 'ABC2345']));
    rstRefusedBy('referral_codes_code_length', fn () => DB::table('referral_codes')->insert(['user_id' => $customer->id, 'code' => '']));
    DB::table('referral_codes')->insert(['user_id' => $customer->id, 'code' => ReferralCodes::generate()]);

    rstRefusedBy('referrals_not_self', fn () => DB::table('referrals')->insert(['referrer_id' => $customer->id, 'referred_user_id' => $customer->id]));
    DB::table('referrals')->insert(['referrer_id' => $customer->id, 'referred_user_id' => User::factory()->create()->id]);

    $setting = ['service_id' => $service->id, 'updated_by' => $staff->id];
    rstRefusedBy('commission_settings_rate_range', fn () => DB::table('commission_settings')->insert($setting + ['rate_bps' => 10_000, 'cap_kobo' => 1]));
    $settingId = DB::table('commission_settings')->insertGetId($setting + ['rate_bps' => 9_999, 'cap_kobo' => 0]);

    $change = fn (array $values) => DB::table('commission_setting_changes')->insert($values + ['commission_setting_id' => $settingId, 'service_id' => $service->id,
        'old_rate_bps' => null, 'new_rate_bps' => 9_999, 'old_cap_kobo' => null, 'new_cap_kobo' => 0, 'reason' => str_repeat('a', 10), 'changed_by' => $staff->id]);
    foreach ([['reason' => str_repeat('a', 9)], ['reason' => ''], ['new_rate_bps' => 10_000], ['old_rate_bps' => 10_000, 'old_cap_kobo' => 1],
        ['old_rate_bps' => 250], ['old_cap_kobo' => 1_000]] as $values) {
        rstRefusedBy('commission_setting_changes_values', fn () => $change($values));
    }
    expect(fn () => $change(['reason' => str_repeat('a', 501)]))->toThrow(QueryException::class, "Data too long for column 'reason'");
    $change(['reason' => str_repeat('é', 10)]);
    $change(['reason' => str_repeat('a', 500), 'old_rate_bps' => 0, 'old_cap_kobo' => 0]);

    expect(DB::table('referral_codes')->count())->toBe(1)
        ->and(DB::table('referrals')->count())->toBe(1)
        ->and(DB::table('commission_settings')->count())->toBe(1)
        ->and(DB::table('commission_setting_changes')->count())->toBe(2);
});

it('refuses commission amounts that are not the capped, rounded-down rate, and accepts exact ones up to the largest wallet amount', function () {
    [$referrer, , $purchase] = rstReferredPurchase();
    [, , $purchase2] = rstReferredPurchase();
    [, , $purchase3] = rstReferredPurchase();
    $wallet = Wallet::where('user_id', $referrer->id)->value('id');
    $credit = rstPosting($referrer, 1_250);
    $row = fn (array $values) => $values + ['reference' => WalletService::reference('COM'), 'purchase_id' => $purchase, 'referrer_id' => $referrer->id,
        'wallet_id' => $wallet, 'credit_transaction_id' => $credit, 'base_amount_kobo' => 50_001, 'rate_bps' => 250, 'cap_kobo' => 100_000,
        'amount_kobo' => 1_250, 'credited_at' => now()];

    foreach ([
        'rounded up' => ['amount_kobo' => 1_251],
        'one kobo short' => ['amount_kobo' => 1_249],
        'above its cap' => ['cap_kobo' => 1_000],
        'a rate of 0' => ['rate_bps' => 0, 'amount_kobo' => 0],
        'a rate above 99.99%' => ['rate_bps' => 10_000, 'amount_kobo' => 50_001],
        'rounded down to 0' => ['base_amount_kobo' => 199, 'rate_bps' => 50, 'amount_kobo' => 0],
        'a cap of 0' => ['cap_kobo' => 0, 'amount_kobo' => 0],
    ] as $case => $values) {
        rstRefusedBy('commissions_amount_rule', fn () => DB::table('commissions')->insert($row($values)));
    }
    expect(DB::table('commissions')->count())->toBe(0);

    DB::table('commissions')->insert($row([])); // 2.5% of 50,001 kobo = 1,250.025, rounded down
    DB::table('commissions')->insert($row(['purchase_id' => $purchase2, 'credit_transaction_id' => rstPosting($referrer, 1_000), 'rate_bps' => 9_999,
        'cap_kobo' => 1_000, 'amount_kobo' => 1_000])); // exactly the cap, at the highest rate
    DB::table('commissions')->insert($row(['purchase_id' => $purchase3, 'credit_transaction_id' => rstPosting($referrer, 1), 'rate_bps' => 9_999,
        'base_amount_kobo' => WalletService::MAX_BALANCE_KOBO, 'cap_kobo' => WalletService::MAX_BALANCE_KOBO,
        'amount_kobo' => 99_990_000_000_000])); // the largest purchase a wallet can pay: no overflow in the CHECK's arithmetic

    expect(DB::table('commissions')->orderBy('id')->pluck('amount_kobo')->all())->toBe([1_250, 1_000, 99_990_000_000_000]);
});

it('refuses actions that are neither a reversal with its debit nor a cancellation without one, and failed attempts with an unknown reason code', function () {
    $staff = SystemUser::factory()->create();
    [$referrer, , $purchase] = rstReferredPurchase();
    [, , $purchase2] = rstReferredPurchase();
    $wallet = Wallet::where('user_id', $referrer->id)->value('id');
    $commission = fn (int $purchaseId) => DB::table('commissions')->insertGetId(['reference' => WalletService::reference('COM'), 'purchase_id' => $purchaseId,
        'referrer_id' => $referrer->id, 'wallet_id' => $wallet, 'credit_transaction_id' => rstPosting($referrer, 1_250), 'base_amount_kobo' => 50_001,
        'rate_bps' => 250, 'cap_kobo' => 100_000, 'amount_kobo' => 1_250, 'credited_at' => now()]);
    $first = $commission($purchase);
    $second = $commission($purchase2);
    $debit = rstPosting($referrer, 1_250, true, LedgerEntryType::CommissionReversal);
    $action = fn (int $commissionId, array $values) => DB::table('commission_actions')->insert($values + ['reference' => WalletService::reference('CMA'),
        'commission_id' => $commissionId, 'wallet_id' => $wallet, 'type' => 'cancellation', 'reversal_transaction_id' => null,
        'reason' => str_repeat('a', 10), 'idempotency_key' => (string) Str::uuid(), 'acted_by' => $staff->id]);

    foreach ([
        'a reversal without its debit' => ['type' => 'reversal'],
        'a cancellation with a debit' => ['reversal_transaction_id' => $debit],
        'another action type' => ['type' => 'refund'],
        'another action type with a debit' => ['type' => 'refund', 'reversal_transaction_id' => $debit],
        'a reason of 9 characters' => ['reason' => str_repeat('a', 9)],
        'no reason' => ['reason' => ''],
    ] as $case => $values) {
        rstRefusedBy('commission_actions_type_rule', fn () => $action($first, $values));
    }
    $action($first, ['type' => 'reversal', 'reversal_transaction_id' => $debit, 'reason' => str_repeat('é', 500)]);
    $action($second, []);
    expect(DB::table('commission_actions')->orderBy('id')->pluck('type')->all())->toBe(['reversal', 'cancellation']);

    $attempt = fn (string $code) => DB::table('failed_commission_attempts')->insert(['purchase_id' => rstReferredPurchase()[2],
        'referrer_id' => $referrer->id, 'reason_code' => $code]);
    rstRefusedBy('failed_commission_attempts_reason_code', fn () => $attempt('insufficient_mood'));
    rstRefusedBy('failed_commission_attempts_reason_code', fn () => $attempt(''));
    foreach (['wallet_balance_limit', 'wallet_unavailable', 'wallet_refused', 'unexpected_error'] as $code) {
        $attempt($code);
    }
    expect(DB::table('failed_commission_attempts')->count())->toBe(4);
});

it('enforces the unique keys and the restricting and compound foreign keys', function () {
    $staff = SystemUser::factory()->create();
    [$referrer, $buyer, $purchase] = rstReferredPurchase();
    [, , $purchase2] = rstReferredPurchase();
    $wallet = Wallet::where('user_id', $referrer->id)->value('id');
    $buyerWallet = Wallet::where('user_id', $buyer->id)->value('id');
    $buyerDebit = DB::table('purchases')->where('id', $purchase2)->value('debit_transaction_id');
    $credit = rstPosting($referrer, 1_250);
    $row = ['purchase_id' => $purchase, 'referrer_id' => $referrer->id, 'wallet_id' => $wallet, 'base_amount_kobo' => 50_001, 'rate_bps' => 250,
        'cap_kobo' => 100_000, 'amount_kobo' => 1_250, 'credited_at' => now()];

    expect(fn () => DB::table('referrals')->insert(['referrer_id' => User::factory()->create()->id, 'referred_user_id' => $buyer->id]))
        ->toThrow(QueryException::class, 'Duplicate entry')
        ->and(fn () => DB::table('commissions')->insert(['reference' => WalletService::reference('COM'), 'credit_transaction_id' => $buyerDebit] + $row))
        ->toThrow(QueryException::class, 'commissions_credit_wallet_fk');
    $commission = DB::table('commissions')->insertGetId(['reference' => WalletService::reference('COM'), 'credit_transaction_id' => $credit] + $row);
    expect(fn () => DB::table('commissions')->insert(['reference' => WalletService::reference('COM'), 'credit_transaction_id' => rstPosting($referrer, 1_250)] + $row))
        ->toThrow(QueryException::class, 'Duplicate entry');

    $action = ['reference' => WalletService::reference('CMA'), 'commission_id' => $commission, 'type' => 'cancellation', 'reason' => str_repeat('a', 10),
        'idempotency_key' => (string) Str::uuid(), 'acted_by' => $staff->id];
    expect(fn () => DB::table('commission_actions')->insert(['wallet_id' => $buyerWallet, 'reversal_transaction_id' => null] + $action))
        ->toThrow(QueryException::class, 'commission_actions_commission_wallet_fk')
        ->and(fn () => DB::table('commission_actions')->insert(['wallet_id' => $wallet, 'type' => 'reversal', 'reversal_transaction_id' => $buyerDebit] + $action))
        ->toThrow(QueryException::class, 'commission_actions_reversal_wallet_fk');
    DB::table('commission_actions')->insert(['wallet_id' => $wallet, 'reversal_transaction_id' => null] + $action);

    foreach ([['users', $referrer->id], ['users', $buyer->id], ['purchases', $purchase], ['transactions', $credit], ['commissions', $commission],
        ['system_users', $staff->id]] as [$table, $id]) {
        expect(fn () => DB::table($table)->where('id', $id)->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');
    }
});

it('rolls back cleanly while empty, and refuses before any schema change while a row exists', function () {
    $snapshot = fn () => ['tables' => collect(Schema::getTables())->pluck('name')->sort()->values()->all(), 'checks' => rstChecks(),
        'foreign_keys' => collect(RST_TABLES)->mapWithKeys(fn (string $table) => [$table => Schema::hasTable($table) ? Schema::getForeignKeys($table) : null])->all(),
        // A set, not an order: migrating again puts this migration after the Phase 13 KYC ones.
        'migrations' => DB::table('migrations')->pluck('migration')->sort()->values()->all()];
    $before = $snapshot();

    // This migration and every later one are counted; the later Phase 13 KYC migrations are skipped (not in this path).
    $steps = DB::table('migrations')->where('migration', '>=', RST_MIGRATION)->count();
    Artisan::call('migrate:rollback', ['--step' => $steps, '--path' => [database_path('migrations/'.RST_MIGRATION.'.php')], '--realpath' => true, '--force' => true]);
    expect(collect(RST_TABLES)->filter(fn (string $table) => Schema::hasTable($table))->all())->toBe([])
        ->and(rstChecks())->toBe([])
        ->and(DB::table('migrations')->where('migration', RST_MIGRATION)->exists())->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);
    expect($snapshot())->toEqual($before);

    $owner = User::factory()->create();
    (new ReferralCode)->forceFill(['user_id' => $owner->id, 'code' => ReferralCodes::generate()])->save();
    $rows = DB::table('referral_codes')->get()->map(fn ($row) => (array) $row)->all();
    $message = 'Refusing to roll back: referral_codes holds referral or commission records, which would be lost. Nothing was changed.';

    expect(fn () => Artisan::call('migrate:rollback', ['--step' => DB::table('migrations')->where('migration', '>=', RST_MIGRATION)->count(),
        '--path' => [database_path('migrations/'.RST_MIGRATION.'.php')], '--realpath' => true, '--force' => true]))->toThrow(RuntimeException::class, $message)
        ->and($snapshot())->toEqual($before)
        ->and(DB::table('referral_codes')->get()->map(fn ($row) => (array) $row)->all())->toBe($rows);
});
