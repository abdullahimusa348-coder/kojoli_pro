<?php

/*
 * Payment concurrency worker: a separate PHP process (own DB connection) that
 * waits at a start barrier, then settles payments through PaymentService the
 * way webhooks, customer returns, reconciliation and staff rechecks do,
 * printing one JSON line per attempt. Uses the test-only FakeGateway, whose
 * gateway side lives in the shared database cache. Started only by
 * tests/Concurrency/PaymentConcurrencyTest.php.
 *
 * Usage: php payment_worker.php <barrier-file> <mode> <payment-or-user-id> <count> <event-key-prefix-or-dash>
 *   mode: webhook | return | reconcile | admin | user-all
 */

use App\Exceptions\Payments\GatewayException;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\SystemUser;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\PaymentService;
use App\Support\Payments\PaymentSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Payments\FakeGateway;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['payments.drivers' => ['fake' => FakeGateway::class]]);

[, $barrier, $mode, $id, $count, $eventPrefix] = $argv;
DB::connection()->getPdo(); // connect before the race starts
touch($barrier.'.ready.'.getmypid());
$deadline = microtime(true) + 60;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(2);
    }
    usleep(500);
}

$service = app(PaymentService::class);
$secret = $argv[6] ?? '';
for ($i = 0; $i < (int) $count; $i++) {
    try {
        if ($mode === 'user-all') {
            foreach (Payment::where('user_id', (int) $id)->orderBy('id')->get() as $payment) {
                $service->verifyAndFinalize($payment, PaymentSource::Return);
            }
            echo json_encode(['result' => 'ok']), "\n";

            continue;
        }

        if ($mode === 'reconcile') {
            echo json_encode(['result' => 'ok', 'stats' => $service->reconcile()]), "\n";

            continue;
        }

        $payment = Payment::findOrFail((int) $id);
        if ($mode === 'webhook') {
            $eventKey = $eventPrefix === 'same' ? 'evt-same' : $eventPrefix.'-'.getmypid().'-'.$i;
            $body = json_encode(['event_id' => $eventKey, 'event' => 'paid', 'reference' => $payment->reference]);
            $result = $service->handleWebhook(PaymentGateway::findOrFail($payment->payment_gateway_id)->code,
                new WebhookRequest($body, ['x-fake-signature' => FakeGateway::sign($body, $secret)]));
            echo json_encode(['result' => 'ok', 'http' => $result->httpStatus, 'duplicate' => $result->duplicate]), "\n";

            continue;
        }

        $source = match ($mode) {
            'return' => PaymentSource::Return,
            'admin' => PaymentSource::Admin,
        };
        $after = $service->verifyAndFinalize($payment, $source, $source === PaymentSource::Admin ? SystemUser::first() : null);
        echo json_encode(['result' => 'ok', 'status' => $after->status->value]), "\n";
    } catch (GatewayException $e) {
        echo json_encode(['result' => 'gateway', 'message' => $e->getMessage()]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
    }
}
