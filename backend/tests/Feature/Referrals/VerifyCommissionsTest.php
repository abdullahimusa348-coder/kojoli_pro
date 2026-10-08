<?php

use App\Models\Commission;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 CP4: commissions:verify, on SQLite. It checks every commission
 * against its purchase, the buyer's referral link and its wallet credit,
 * every failed attempt, orphan commission credits and the totals, and only
 * reports: whatever it finds, it changes nothing (every table it reads, and
 * the money tables, are compared before and after). Data is broken here
 * directly in the database, as only a bug or a manual edit could.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

/** @return array{0: int, 1: string} the exit code and output of commissions:verify */
function vctRun(): array
{
    $code = Artisan::call('commissions:verify');

    return [$code, Artisan::output()];
}

/** Every row of the tables the command reads, and the money tables. */
function vctRows(): array
{
    return collect(['commissions', 'commission_actions', 'failed_commission_attempts', 'purchases', 'purchase_status_changes', 'referrals',
        'commission_settings', 'services', 'transactions', 'wallet_ledger_entries', 'wallets', 'users'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

/**
 * Two paid commissions (Data, and NIN with its result), one failed attempt (a referrer without a Main Wallet) and a purchase
 * that earned nothing (Airtime has no rate).
 *
 * @return array{referrer: User, walletless: User, data: Purchase, nin: Purchase, failed: Purchase, unpaid: Purchase}
 */
function vctData(): array
{
    $referrer = cmxReferrer();
    $walletless = User::factory()->create();
    cmxSetting('data', 250, 100_000);
    cmxSetting('nin', 500, 1_000);
    $buyer = cmxReferred($referrer);

    return ['referrer' => $referrer, 'walletless' => $walletless, 'data' => cmxBuy($buyer, cmxPlan('data', 50_000)),
        'nin' => cmxBuy($buyer, cmxPlan('nin', 30_000)), 'failed' => cmxBuy(cmxReferred($walletless), cmxPlan('data', 50_000)),
        'unpaid' => cmxBuy($buyer, cmxPlan('airtime', 50_000))];
}

it('reports every commission and failed attempt consistent, changing nothing', function () {
    vctData();
    $rows = vctRows();

    [$code, $output] = vctRun();

    expect($code)->toBe(0)
        ->and(trim($output))->toBe('All 2 commission(s) and 1 failed commission attempt(s) are consistent with their purchases, referrals and wallet credits, and the commission totals match.')
        ->and(Commission::count())->toBe(2)
        ->and(FailedCommissionAttempt::count())->toBe(1)
        ->and(vctRows())->toBe($rows);
});

it('reports with nothing to check', function () {
    expect(vctRun())->toBe([0, "All 0 commission(s) and 0 failed commission attempt(s) are consistent with their purchases, referrals and wallet credits, and the commission totals match.\n"]);
});

it('reports each kind of problem by reference, and still changes nothing', function (Closure $break, array $expected) {
    $data = vctData();
    $commission = Commission::where('purchase_id', $data['data']->id)->sole();
    $replace = ['{COM}' => $commission->reference, '{PUR}' => $data['data']->reference, '{FAILED}' => $data['failed']->reference,
        '{TXN}' => DB::table('transactions')->where('id', $commission->credit_transaction_id)->value('reference'),
        '{REFERRER}' => (string) $data['referrer']->id, '{WALLETLESS}' => (string) $data['walletless']->id];
    $break($data, $commission);
    $rows = vctRows();

    [$code, $output] = vctRun();

    expect($code)->toBe(1);
    foreach ($expected as $line) {
        expect($output)->toContain(strtr($line, $replace));
    }
    expect($output)->toContain('Nothing was changed; investigate before any correction.')
        ->and(vctRows())->toBe($rows);
})->with([
    'a commission whose purchase is not successful' => [fn (array $d) => DB::table('purchases')->where('id', $d['data']->id)->update(['status' => 'review']),
        ['Commission {COM} (purchase {PUR}): its purchase is review, not successful.']],
    'a commission on a service that does not qualify' => [fn (array $d) => DB::table('purchases')->where('id', $d['data']->id)->update(['service_id' => cmxService('cable-tv')->id]),
        ['Commission {COM} (purchase {PUR}): its purchase is not of a qualifying service.']],
    'a commission to someone other than the buyer\'s referrer' => [fn (array $d) => DB::table('referrals')->where('referred_user_id', $d['data']->user_id)
        ->update(['referrer_id' => $d['walletless']->id]), ['Commission {COM} (purchase {PUR}): it is not credited to the buyer\'s referrer.']],
    'a purchase with a commission and a failed attempt' => [fn (array $d) => DB::table('failed_commission_attempts')->insert(['purchase_id' => $d['data']->id,
        'referrer_id' => $d['referrer']->id, 'reason_code' => 'unexpected_error', 'created_at' => now()]), [
            'Commission {COM} (purchase {PUR}): its purchase also has a failed commission attempt.',
            'Failed commission attempt for purchase {PUR}: its purchase also has a commission.',
        ]],
    'a base amount that is not the purchase amount' => [fn (array $d) => DB::table('purchases')->where('id', $d['data']->id)->update(['amount_kobo' => 40_000]),
        ['Commission {COM} (purchase {PUR}): its base amount is not the purchase amount.']],
    'an amount that is not the capped, rounded-down rate' => [fn (array $d, Commission $c) => DB::table('commissions')->where('id', $c->id)->update(['amount_kobo' => 1_251]), [
        'Commission {COM} (purchase {PUR}): its amount is not its rate of the base amount, capped and rounded down, and at least 1 kobo.',
        'Commission {COM} (purchase {PUR}): its credit {TXN} is ₦12.50 but the commission is ₦12.51.',
        'Totals: successful commission credits add up to ₦22.50, but commissions add up to ₦22.51.',
    ]],
    'a commission on a wallet that is not the referrer\'s' => [fn (array $d, Commission $c) => DB::table('wallets')->where('id', $c->wallet_id)
        ->update(['user_id' => $d['walletless']->id]), ['Commission {COM} (purchase {PUR}): it is not on the referrer\'s Main Wallet.']],
    'a credit posted for another purchase' => [fn (array $d, Commission $c) => DB::table('transactions')->where('id', $c->credit_transaction_id)
        ->update(['idempotency_key' => 'commission:'.$d['nin']->reference.'-x']), ['Commission {COM} (purchase {PUR}): its credit {TXN} was not posted for this purchase.']],
    'a credit that was reversed' => [fn (array $d, Commission $c) => DB::table('transactions')->where('id', $c->credit_transaction_id)->update(['status' => 'reversed']), [
        'Commission {COM} (purchase {PUR}): its credit {TXN} is reversed, not successful.',
        'Totals: successful commission credits add up to ₦10.00, but commissions add up to ₦22.50.',
    ]],
    'a credit with another kind of ledger entry' => [fn (array $d, Commission $c) => DB::table('wallet_ledger_entries')->where('transaction_id', $c->credit_transaction_id)
        ->update(['entry_type' => 'adjustment_credit']), ['Commission {COM} (purchase {PUR}): its credit {TXN} is not one commission credit ledger entry of the commission amount.']],
    'a commission credit that belongs to no commission' => [function (array $d) {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($d['referrer']), 700, LedgerEntryType::CommissionCredit, TransactionType::Commission, 'Referral commission',
            'test-orphan:'.Str::uuid());
    }, ['a commission credit that belongs to no commission.', 'Totals: successful commission credits add up to ₦29.50, but commissions add up to ₦22.50.']],
    'a failed attempt for a purchase that is not successful' => [fn (array $d) => DB::table('purchases')->where('id', $d['failed']->id)->update(['status' => 'pending']),
        ['Failed commission attempt for purchase {FAILED}: its purchase is pending, not successful.']],
    'a failed attempt naming someone else' => [fn (array $d) => DB::table('failed_commission_attempts')->update(['referrer_id' => $d['referrer']->id]),
        ['Failed commission attempt for purchase {FAILED}: it does not name the buyer\'s referrer (customer #{REFERRER}).']],
    'a failed attempt with an unknown reason code' => [fn (array $d) => DB::table('failed_commission_attempts')->update(['reason_code' => 'insufficient_mood']),
        ['Failed commission attempt for purchase {FAILED}: has an unknown reason code.']],
]);

it('never names a customer, email or phone number, nor anything a purchase delivered', function () {
    $data = vctData();
    DB::table('commissions')->update(['amount_kobo' => 1]);
    DB::table('failed_commission_attempts')->update(['reason_code' => 'insufficient_mood']);

    [$code, $output] = vctRun();

    expect($code)->toBe(1);
    foreach ([$data['referrer'], $data['walletless'], $data['data']->user, $data['failed']->user] as $customer) {
        expect($output)->not->toContain($customer->email)->not->toContain($customer->name)->not->toContain((string) $customer->phone);
    }
    expect($output)->not->toContain('08012345678')->not->toContain('FIXTURE-')->not->toContain('•');
});

describe('daily integrity schedule', function () {
    it('runs daily and logs an error when it finds problems, nothing when it passes', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'commissions:verify'));
        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');

        Log::spy();
        $event->exitCode = 0;
        $event->callAfterCallbacks(app());
        Log::shouldNotHaveReceived('error');
        $event->exitCode = 1;
        $event->callAfterCallbacks(app());
        Log::shouldHaveReceived('error')->once()->with('Scheduled integrity check found problems', ['command' => 'commissions:verify']);
    });
});
