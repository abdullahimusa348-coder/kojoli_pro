<?php

/*
 * Wallet concurrency worker: a separate PHP process (own DB connection) that
 * waits at a start barrier and then performs wallet operations, printing one
 * JSON line per attempt. Started only by tests/Concurrency/WalletConcurrencyTest.php.
 *
 * Usage: php worker.php <barrier-file> <mode> <wallet-or-user-id> <amount> <count> <key-or-dash>
 *   mode: debit | credit | create
 */

use App\Exceptions\Wallet\WalletException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $barrier, $mode, $id, $amount, $count, $key] = $argv;
DB::connection()->getPdo(); // connect before the race starts
touch($barrier.'.ready.'.getmypid()); // tell the test this worker has booted
$deadline = microtime(true) + 60;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(2);
    }
    usleep(500);
}

$service = app(WalletService::class);
for ($i = 0; $i < (int) $count; $i++) {
    try {
        if ($mode === 'create') {
            $wallet = $service->walletFor(User::findOrFail((int) $id));
            echo json_encode(['result' => 'ok', 'wallet' => $wallet->id]), "\n";

            continue;
        }
        $wallet = Wallet::findOrFail((int) $id);
        $idempotency = $key === '-' ? null : $key;
        $result = $mode === 'debit'
            ? $service->debit($wallet, (int) $amount, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Concurrency test debit', $idempotency)
            : $service->credit($wallet, (int) $amount, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Concurrency test credit', $idempotency);
        echo json_encode(['result' => $result->replayed ? 'replayed' : 'ok', 'transaction' => $result->transaction->id]), "\n";
    } catch (WalletException $e) {
        echo json_encode(['result' => class_basename($e)]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
    }
}
