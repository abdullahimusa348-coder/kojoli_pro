<?php

/*
 * Result concurrency worker (Phase 11 CP2 NIN/BVN, CP4 Exam PIN): a separate
 * PHP process (own DB connection) that waits at a start barrier, then
 * executes or re-checks one NIN/BVN or Exam PIN purchase through
 * PurchaseService with the test-only FakeProvider, printing one JSON line per
 * call (status only: never a result value or a number). With "fixture", every
 * succeeded answer carries neutral fixture result fields generated when it
 * runs; with "none", none (the provider "forgets" the result); with "blank",
 * fields whose values are all blank. Started only by
 * tests/Concurrency/PurchaseResultTest.php and ExamPinTest.php.
 *
 * Usage: php result_worker.php <barrier> <mode> <staff-id> <purchase-id> <count> <script-csv> <fixture|none|blank> <delay-ms>
 *   mode: execute (purchase id) | reconcile (scheduled re-check run) | recheck (staff re-check of the purchase by staff id)
 *   script: provider answers for purchase calls (execute) or status queries (reconcile/recheck)
 */

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Purchases\PurchaseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Providers\FakeProvider;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['providers.drivers' => ['fake-provider' => FakeProvider::class]]);

[, $barrier, $mode, $staffId, $purchaseId, $count, $script, $results, $delay] = $argv;
FakeProvider::reset();
FakeProvider::$services = ['nin', 'bvn', 'exam-pin'];
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
    $mode === 'execute' ? FakeProvider::$purchaseScript = $answers : FakeProvider::$queryScript = $answers;
    FakeProvider::$resultScript = match ($results) {
        'fixture' => array_map(fn () => FakeProvider::fixtureFields(), $answers),
        'blank' => array_map(fn () => new ProviderResultFields([['key' => 'fixture_1', 'label' => 'Fixture 1', 'value' => ' ']]), $answers),
        default => [],
    };
    try {
        if ($mode === 'reconcile') {
            echo json_encode(['result' => 'ok', 'stats' => $service->reconcile()]), "\n";

            continue;
        }
        $purchase = match ($mode) {
            'execute' => $service->execute(Purchase::findOrFail((int) $purchaseId)),
            'recheck' => app(RecheckPurchase::class)->handle(Purchase::findOrFail((int) $purchaseId), SystemUser::findOrFail((int) $staffId)),
        };
        echo json_encode(['result' => 'ok', 'purchase' => $purchase->id, 'status' => $purchase->status->value]), "\n";
    } catch (PurchaseException $e) {
        echo json_encode(['result' => 'refused', 'message' => $e->getMessage()]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['result' => 'error', 'class' => $e::class]), "\n";
    }
}
