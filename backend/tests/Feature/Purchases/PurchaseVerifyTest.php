<?php

use App\Models\Purchase;
use App\Support\Purchases\PurchaseSource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 3, CP2: purchases:verify (report only, never repairs) and the
 * daily integrity schedule (wallet:verify and purchases:verify). Tampering is
 * done with raw database writes, which bypass the model guards; the database
 * itself already refuses a debit or refund on another wallet and a delivering
 * attempt of another purchase. Test-only FakeProvider.
 */

beforeEach(function () {
    puxDrivers();
    Http::preventStrayRequests();
});

/** @return array{ok: Purchase, failed: Purchase, pending: Purchase, review: Purchase, airtime: Purchase} */
function pvfData(): array
{
    $buy = function (array $script) {
        $plan = puxPlan('data', 50_000);
        puxRoute($plan);
        FakeProvider::reset();
        FakeProvider::$purchaseScript = $script;

        return puxService()->purchase(puxCustomer(1_000_000), $plan, '08012345678', null, (string) Str::uuid());
    };
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 08:00:00', 'UTC'));
    $review = $buy(['unknown']);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 09:00:00', 'UTC'));
    FakeProvider::reset();
    FakeProvider::$queryScript = ['unknown'];
    $review = puxService()->recheck($review->fresh(), PurchaseSource::Reconcile);

    $airtimePlan = puxPlan('airtime', 0, true);
    puxRoute($airtimePlan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 300]);
    FakeProvider::reset();
    FakeProvider::$purchaseScript = ['succeeded'];
    $airtime = puxService()->purchase(puxCustomer(1_000_000), $airtimePlan, '08012345678', 100_000, (string) Str::uuid());

    return ['ok' => $buy(['succeeded']), 'failed' => $buy(['failed_definite']), 'pending' => $buy(['unknown']), 'review' => $review, 'airtime' => $airtime];
}

/** @return array{0: int, 1: string} exit code and output */
function pvfRun(): array
{
    $code = Artisan::call('purchases:verify');

    return [$code, Artisan::output()];
}

/** A purchase transaction row written directly (no purchase points to it unless the test links it). */
function pvfTransaction(Purchase $on, string $direction, int $amount): int
{
    return DB::table('transactions')->insertGetId([
        'reference' => 'TXN-TAMPER'.Str::upper(Str::random(8)), 'user_id' => $on->user_id, 'wallet_id' => $on->wallet_id,
        'type' => 'purchase', 'direction' => $direction, 'amount_kobo' => $amount, 'status' => 'successful',
        'idempotency_key' => 'tamper-'.Str::random(8), 'description' => 'Tampered', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, list<array<string, mixed>>> every row the purchase engine and wallet own */
function pvfSnapshot(): array
{
    return collect(['purchases', 'purchase_attempts', 'purchase_status_changes', 'transactions', 'wallet_ledger_entries', 'wallets'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

describe('purchases:verify', function () {
    it('passes when every purchase matches its money and attempts', function () {
        pvfData();

        [$code, $output] = pvfRun();
        expect($code)->toBe(0)->and($output)->toContain('All 5 purchase(s) are consistent with their debits, refunds and attempts, and the purchase totals match.');
    });

    it('passes when there are no purchases', function () {
        [$code, $output] = pvfRun();

        expect($code)->toBe(0)->and($output)->toContain('All 0 purchase(s) are consistent');
    });

    it('reports each kind of inconsistency with the purchase reference', function (Closure $tamper, string $key, string $problem) {
        $data = pvfData();
        $tamper($data);

        [$code, $output] = pvfRun();
        expect($code)->toBe(1)
            ->and($output)->toContain("Purchase {$data[$key]->reference}")
            ->and($output)->toContain($problem)
            ->and($output)->toContain('Nothing was changed; investigate before any correction.');
    })->with([
        'debit amount changed' => [fn ($d) => DB::table('transactions')->where('id', $d['ok']->debit_transaction_id)->update(['amount_kobo' => 1]),
            'ok', 'is ₦0.01 but the purchase charged ₦500.00.'],
        'debit not successful' => [fn ($d) => DB::table('transactions')->where('id', $d['ok']->debit_transaction_id)->update(['status' => 'failed']),
            'ok', 'is failed, not successful.'],
        'debit in the wrong direction' => [fn ($d) => DB::table('transactions')->where('id', $d['airtime']->debit_transaction_id)->update(['direction' => 'credit']),
            'airtime', 'is not a purchase debit.'],
        'debit posted for something else' => [fn ($d) => DB::table('transactions')->where('id', $d['pending']->debit_transaction_id)->update(['idempotency_key' => 'purchase:PUR-SOMETHING-ELSE']),
            'pending', 'was not posted for this purchase.'],
        'debit missing' => [fn ($d) => DB::table('purchases')->where('id', $d['ok']->id)->update(['debit_transaction_id' => null]),
            'ok', 'has no debit transaction.'],
        'failed purchase without its refund' => [fn ($d) => DB::table('purchases')->where('id', $d['failed']->id)->update(['refund_transaction_id' => null]),
            'failed', 'failed but has no refund transaction.'],
        'refund amount changed' => [fn ($d) => DB::table('transactions')->where('id', $d['failed']->refund_transaction_id)->update(['amount_kobo' => 40_000]),
            'failed', 'is ₦400.00 but the purchase charged ₦500.00.'],
        'refund on a successful purchase' => [fn ($d) => DB::table('purchases')->where('id', $d['ok']->id)->update(['refund_transaction_id' => pvfTransaction($d['ok'], 'credit', 50_000)]),
            'ok', 'but only a failed purchase is refunded.'],
        'delivering attempt not succeeded' => [fn ($d) => DB::table('purchase_attempts')->where('id', $d['ok']->successful_attempt_id)->update(['status' => 'failed_definite']),
            'ok', 'its delivering attempt has not succeeded.'],
        'attempt succeeded on a pending purchase' => [fn ($d) => DB::table('purchase_attempts')->where('purchase_id', $d['pending']->id)->update(['status' => 'succeeded']),
            'pending', 'an attempt succeeded but the purchase is not successful.'],
        'successful purchase without completion time' => [fn ($d) => DB::table('purchases')->where('id', $d['ok']->id)->update(['completed_at' => null]),
            'ok', 'has no completion time.'],
        'review purchase with a completion time' => [fn ($d) => DB::table('purchases')->where('id', $d['review']->id)->update(['completed_at' => now()]),
            'review', 'has a completion time but is not final.'],
        'purchase amount changed' => [fn ($d) => DB::table('purchases')->where('id', $d['airtime']->id)->update(['amount_kobo' => 1]),
            'airtime', 'but the purchase charged ₦0.01.'],
    ]);

    it('reports purchase transactions that belong to no purchase and totals that do not add up', function () {
        $data = pvfData();
        $orphan = pvfTransaction($data['ok'], 'debit', 25_000);
        $reference = DB::table('transactions')->where('id', $orphan)->value('reference');

        [$code, $output] = pvfRun();
        expect($code)->toBe(1)
            ->and($output)->toContain("Transaction {$reference}: a purchase transaction that belongs to no purchase.")
            ->and($output)->toContain('Totals: purchase debits')
            ->and($output)->toContain('but purchases that were not refunded add up to');
    });

    it('changes nothing and gives the same answer every time it runs', function (bool $tampered) {
        $data = pvfData();
        if ($tampered) {
            DB::table('transactions')->where('id', $data['ok']->debit_transaction_id)->update(['amount_kobo' => 1]);
            pvfTransaction($data['failed'], 'debit', 1_000);
        }
        $before = pvfSnapshot();
        FakeProvider::reset();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        [$firstCode, $firstOutput] = pvfRun();
        [$secondCode, $secondOutput] = pvfRun();

        expect([$firstCode, $secondCode])->toBe($tampered ? [1, 1] : [0, 0])
            ->and($secondOutput)->toBe($firstOutput)
            ->and($writes)->toBe([])
            ->and(FakeProvider::$calls)->toBe([])
            ->and(pvfSnapshot())->toBe($before);
    })->with(['consistent data' => [false], 'inconsistent data' => [true]]);

    it('never names phone numbers or customers', function () {
        $data = pvfData();
        DB::table('transactions')->where('id', $data['ok']->debit_transaction_id)->update(['amount_kobo' => 1]);
        $customer = $data['ok']->user;

        [, $output] = pvfRun();
        expect($output)->not->toContain('0801')->not->toContain('8012345678')
            ->not->toContain($customer->email)->not->toContain($customer->name);
    });
});

describe('daily integrity schedule', function () {
    it('runs each check daily and logs an error when it finds problems', function (string $command) {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, $command));
        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 0 * * *');

        Log::spy();
        $event->exitCode = 1;
        $event->callAfterCallbacks(app());
        Log::shouldHaveReceived('error')->once()->with('Scheduled integrity check found problems', ['command' => $command]);
    })->with(['wallet:verify', 'purchases:verify']);

    it('logs nothing when a check passes', function (string $command) {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, $command));

        Log::spy();
        $event->exitCode = 0;
        $event->callAfterCallbacks(app());
        Log::shouldNotHaveReceived('error');
    })->with(['wallet:verify', 'purchases:verify']);

    it('keeps the existing schedule unchanged', function () {
        $expressions = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [trim(Str::after($e->command, 'artisan'), " '") => $e->expression]);

        expect($expressions->all())->toMatchArray([
            'sanctum:prune-expired --hours=24' => '0 0 * * *',
            'payments:reconcile' => '*/5 * * * *',
            'payments:prune-webhooks' => '0 0 * * *',
            'purchases:reconcile' => '*/5 * * * *',
            'wallet:verify' => '0 0 * * *',
            'purchases:verify' => '0 0 * * *',
        ])->and($expressions)->toHaveCount(6);
    });
});
