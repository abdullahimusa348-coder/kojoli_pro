<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * Real concurrency against MariaDB: several separate PHP processes (each with
 * its own database connection) hit the same wallet at the same moment. Run
 * with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Starts the given workers (each: [mode, id, amount, count, key]) as separate
 * processes, releases them together, and returns every printed result.
 *
 * @param  list<array{0: string, 1: int, 2: int, 3: int, 4?: string}>  $workers
 * @return list<array<string, mixed>>
 */
function raceAll(array $workers): array
{
    $barrier = sys_get_temp_dir().'/wallet-race-'.uniqid();
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];

    $pool = Process::pool(function ($pool) use ($workers, $barrier, $env) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env($env)->timeout(120)
                ->command([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $barrier, $w[0], (string) $w[1], (string) $w[2], (string) $w[3], $w[4] ?? '-']);
        }
    })->start();

    // Release all workers at the same instant, once every one has booted and connected.
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
            $lines[] = json_decode($line, true) + ['mode' => null];
        }
    }

    return $lines;
}

/** $workers identical workers. @return list<array<string, mixed>> */
function race(int $workers, string $mode, int $id, int $amount, int $count, string $key = '-'): array
{
    return raceAll(array_fill(0, $workers, [$mode, $id, $amount, $count, $key]));
}

function fundedWallet(int $kobo): Wallet
{
    $wallets = app(WalletService::class);
    $wallet = $wallets->walletFor(User::factory()->create());
    if ($kobo > 0) {
        $wallets->credit($wallet, $kobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Concurrency test funding');
    }

    return $wallet->refresh();
}

function assertLedgerConsistent(Wallet $wallet): void
{
    $wallet->refresh();
    $running = 0;
    foreach (WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get() as $entry) {
        $running += $entry->signedKobo();
        expect($entry->balance_after_kobo)->toBe($running)->and($running)->toBeGreaterThanOrEqual(0);
    }
    expect($wallet->balance_kobo)->toBe($running);
    expect(Artisan::call('wallet:verify'))->toBe(0);
}

it('never double-spends: parallel debits stop exactly at zero', function () {
    $wallet = fundedWallet(300_000); // room for exactly 300 debits of ₦10.00

    $results = collect(race(workers: 10, mode: 'debit', id: $wallet->id, amount: 1_000, count: 40)); // 400 attempts

    expect($results)->toHaveCount(400)
        ->and($results->where('result', 'ok')->count())->toBe(300)
        ->and($results->where('result', 'InsufficientFunds')->count())->toBe(100)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($wallet->fresh()->balance_kobo)->toBe(0)
        ->and(WalletLedgerEntry::where('wallet_id', $wallet->id)->where('direction', 'debit')->count())->toBe(300)
        ->and(Transaction::where('wallet_id', $wallet->id)->count())->toBe(301);
    assertLedgerConsistent($wallet);
});

it('loses no updates when credits and debits run at the same time', function () {
    $wallet = fundedWallet(500_000);

    // 6 debit workers (100 × ₦7.00) and 6 credit workers (100 × ₦3.00) released together.
    $results = collect(raceAll([
        ...array_fill(0, 6, ['debit', $wallet->id, 700, 100]),
        ...array_fill(0, 6, ['credit', $wallet->id, 300, 100]),
    ]));

    expect($results)->toHaveCount(1_200)
        ->and($results->where('result', 'ok')->count())->toBe(1_200)
        ->and($results->where('result', 'error')->values()->all())->toBe([])
        ->and($wallet->fresh()->balance_kobo)->toBe(500_000 - 600 * 700 + 600 * 300)
        ->and(WalletLedgerEntry::where('wallet_id', $wallet->id)->count())->toBe(1_201);
    assertLedgerConsistent($wallet);
});

it('posts a repeated idempotency key once, even when sent in parallel', function () {
    $wallet = fundedWallet(100_000);

    $results = collect(race(workers: 8, mode: 'debit', id: $wallet->id, amount: 2_500, count: 5, key: 'same-order-key'));

    expect($results)->toHaveCount(40)
        ->and($results->where('result', 'ok')->count())->toBe(1)
        ->and($results->where('result', 'replayed')->count())->toBe(39)
        ->and($results->pluck('transaction')->unique()->count())->toBe(1)
        ->and(Transaction::where('idempotency_key', 'same-order-key')->count())->toBe(1)
        ->and(WalletLedgerEntry::where('wallet_id', $wallet->id)->where('direction', 'debit')->count())->toBe(1)
        ->and($wallet->fresh()->balance_kobo)->toBe(97_500);
    assertLedgerConsistent($wallet);
});

it('creates exactly one wallet when many requests create it at once', function () {
    $user = User::factory()->create();

    $results = collect(race(workers: 8, mode: 'create', id: $user->id, amount: 0, count: 3));

    expect($results)->toHaveCount(24)->and($results->where('result', 'ok')->count())->toBe(24)
        ->and($results->pluck('wallet')->unique()->count())->toBe(1)
        ->and(Wallet::where('user_id', $user->id)->count())->toBe(1);
});
