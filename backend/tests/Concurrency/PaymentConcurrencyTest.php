<?php

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentStatusChange;
use App\Models\PaymentWebhook;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Payments\PaymentService;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\PaymentStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\Payments\FakeGateway;

/*
 * Real payment concurrency against MariaDB: separate PHP processes settle the
 * same payment at the same moment through webhooks, customer returns,
 * reconciliation and staff rechecks. The gateway side (FakeGateway) lives in
 * the shared database cache. Run with: php artisan test -c phpunit.concurrency.xml
 */

const PAYC_SECRET = 'fake-webhook-secret-NOT-REAL-c0nc';

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    config(['cache.default' => 'database', 'payments.drivers' => ['fake' => FakeGateway::class]]);
    Cache::flush();
    FakeGateway::reset();
});

function paycGateway(): PaymentGateway
{
    $gateway = PaymentGateway::factory()->create(['code' => 'race-gw']);
    foreach (['api_key' => 'fake-api-key-NOT-REAL-c0nc', 'webhook_secret' => PAYC_SECRET] as $key => $value) {
        (new PaymentGatewayCredential)->forceFill(['payment_gateway_id' => $gateway->id, 'mode' => GatewayMode::Sandbox, 'key' => $key,
            'value' => $value, 'hint' => PaymentGatewayCredential::hintFor($value)])->save();
    }

    return $gateway;
}

/** A started payment the gateway reports as paid (exact amount). */
function paycPaid(PaymentGateway $gateway, ?User $user = null, int $kobo = 250_000): Payment
{
    $service = app(PaymentService::class);
    $payment = $service->initialize($service->create($user ?? User::factory()->create(), $kobo, $gateway, (string) Str::uuid()), 'https://nadabo.test/r');
    FakeGateway::pay($payment->reference);

    return $payment;
}

/**
 * @param  list<array{0: string, 1: int, 2: int, 3?: string}>  $workers  [mode, id, count, event-prefix]
 * @return list<array<string, mixed>>
 */
function paycRace(array $workers): array
{
    $barrier = sys_get_temp_dir().'/payment-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'database'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)
                ->command([PHP_BINARY, base_path('tests/Concurrency/payment_worker.php'), $barrier, $w[0], (string) $w[1], (string) $w[2], $w[3] ?? '-', PAYC_SECRET]);
        }
    })->start();

    $deadline = microtime(true) + 60;
    while (count(glob($barrier.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(count(glob($barrier.'.ready.*')))->toBe(count($workers));
    touch($barrier);
    $results = $pool->wait();
    array_map('unlink', [$barrier, ...glob($barrier.'.ready.*')]);

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/** Exactly one funding transaction and one ledger entry, the exact balance, and a clean wallet:verify. */
function paycAssertCreditedOnce(Payment $payment, int $expectedBalance): void
{
    $payment->refresh();
    $tx = Transaction::where('idempotency_key', 'payment:'.$payment->reference)->get();

    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($tx)->toHaveCount(1)
        ->and($payment->wallet_transaction_id)->toBe($tx->first()->id)
        ->and(WalletLedgerEntry::where('transaction_id', $tx->first()->id)->count())->toBe(1)
        ->and(Transaction::where('wallet_id', $payment->wallet_id)->count())->toBe(1)
        ->and(WalletLedgerEntry::where('wallet_id', $payment->wallet_id)->count())->toBe(1)
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe($expectedBalance)
        ->and(PaymentStatusChange::where('payment_id', $payment->id)->where('new_status', 'successful')->count())->toBe(1)
        ->and(Artisan::call('wallet:verify'))->toBe(0);
}

it('credits once when many duplicate webhooks for one payment arrive at once', function () {
    $payment = paycPaid(paycGateway());

    $results = collect(paycRace(array_fill(0, 10, ['webhook', $payment->id, 5, 'same'])));

    expect($results)->toHaveCount(50)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('http', 200)->count())->toBe(50)
        ->and($results->where('duplicate', false)->count())->toBe(1)
        ->and(PaymentWebhook::count())->toBe(1);
    paycAssertCreditedOnce($payment, 250_000);
});

it('credits once when many different webhook events for one payment arrive at once', function () {
    $payment = paycPaid(paycGateway());

    $results = collect(paycRace(array_fill(0, 10, ['webhook', $payment->id, 5, 'evt'])));

    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and(PaymentWebhook::count())->toBe(50)
        ->and(PaymentWebhook::where('outcome', 'processed')->count())->toBe(50);
    paycAssertCreditedOnce($payment, 250_000);
});

it('credits once when webhooks, browser returns, reconciliation and staff rechecks race', function () {
    $payment = paycPaid(paycGateway());
    Payment::whereKey($payment->id)->update(['created_at' => now()->subMinutes(10)]); // old enough for reconciliation
    SystemUser::factory()->create();

    $results = collect(paycRace([
        ...array_fill(0, 4, ['webhook', $payment->id, 5, 'evt']),
        ...array_fill(0, 4, ['return', $payment->id, 5]),
        ...array_fill(0, 2, ['reconcile', $payment->id, 5]),
        ...array_fill(0, 2, ['admin', $payment->id, 5]),
    ]));

    expect($results)->toHaveCount(60)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->whereNotNull('status')->pluck('status')->unique()->values()->all())->toBe(['successful']);
    paycAssertCreditedOnce($payment, 250_000);
});

it('credits once under many duplicate verification calls', function () {
    $payment = paycPaid(paycGateway());

    $results = collect(paycRace(array_fill(0, 12, ['return', $payment->id, 10])));

    expect($results)->toHaveCount(120)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->pluck('status')->unique()->values()->all())->toBe(['successful']);
    paycAssertCreditedOnce($payment, 250_000);
});

it('credits each of many payments to one wallet exactly once with an exact final balance', function () {
    $gateway = paycGateway();
    $user = User::factory()->create();
    $payments = collect(range(1, 10))->map(fn ($i) => paycPaid($gateway, $user, 10_000 * $i));

    $results = collect(paycRace(array_fill(0, 8, ['user-all', $user->id, 3])));

    expect($results->where('result', 'error')->values()->all())->toBe([]);
    foreach ($payments as $payment) {
        expect($payment->fresh()->status)->toBe(PaymentStatus::Successful)
            ->and(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->count())->toBe(1);
    }
    $wallet = Wallet::where('user_id', $user->id)->sole();
    expect($wallet->balance_kobo)->toBe(550_000)
        ->and(Transaction::where('wallet_id', $wallet->id)->count())->toBe(10)
        ->and(WalletLedgerEntry::where('wallet_id', $wallet->id)->count())->toBe(10)
        ->and(Artisan::call('wallet:verify'))->toBe(0);
});
