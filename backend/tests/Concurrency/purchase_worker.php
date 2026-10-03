<?php

/*
 * Purchase concurrency worker: a separate PHP process (own DB connection)
 * that waits at a start barrier, then buys or executes purchases through
 * PurchaseService with the test-only FakeProvider, printing one JSON line per
 * call. Started only by tests/Concurrency/PurchaseConcurrencyTest.php.
 *
 * Usage: php purchase_worker.php <barrier> <mode> <user-or-staff-id> <plan-or-purchase-id> <count> <key> <script-csv> <delay-ms>
 *   mode: buy (create + execute; key "same" or a prefix) | execute (purchase id)
 *         | reconcile (scheduled re-check run) | recheck (staff re-check of a purchase id by staff id)
 *   script: provider answers for purchase calls (buy/execute) or status queries (reconcile/recheck)
 */

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Purchases\PurchaseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Providers\FakeProvider;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['providers.drivers' => ['fake-provider' => FakeProvider::class]]);

[, $barrier, $mode, $userId, $targetId, $count, $key, $script, $delay] = $argv;
FakeProvider::reset();
FakeProvider::$delayMs = (int) $delay;

DB::connection()->getPdo();
touch($barrier.'.ready.'.getmypid());
$deadline = microtime(true) + 60;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(2);
    }
    usleep(500);
}

$service = app(PurchaseService::class);
for ($i = 0; $i < (int) $count; $i++) {
    $answers = $script === '-' ? [] : explode(',', $script);
    in_array($mode, ['reconcile', 'recheck'], true) ? FakeProvider::$queryScript = $answers : FakeProvider::$purchaseScript = $answers;
    try {
        if ($mode === 'reconcile') {
            echo json_encode(['result' => 'ok', 'stats' => $service->reconcile()]), "\n";

            continue;
        }
        $purchase = match ($mode) {
            'buy' => $service->purchase(User::findOrFail((int) $userId), Plan::findOrFail((int) $targetId), '08012345678', null,
                $key === 'same' ? 'same-key' : $key.'-'.getmypid().'-'.$i),
            'execute' => $service->execute(Purchase::findOrFail((int) $targetId)),
            'recheck' => app(RecheckPurchase::class)->handle(Purchase::findOrFail((int) $targetId), SystemUser::findOrFail((int) $userId)),
        };
        echo json_encode(['result' => 'ok', 'purchase' => $purchase->id, 'status' => $purchase->status->value]), "\n";
    } catch (PurchaseException $e) {
        echo json_encode(['result' => 'refused', 'message' => $e->getMessage()]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
    }
}
