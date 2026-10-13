<?php

/*
 * Buy Exam PIN HTTP worker (Phase 11 CP4): a separate PHP process (own DB
 * connection) that waits at a start barrier, then POSTs the same sealed
 * confirmation to /buy/exam-pin through the HTTP kernel as the signed-in
 * customer, printing one JSON line per submission (status and redirect path).
 * The sealed confirmation arrives in EXAM_PIN_TEST_CONFIRMATION, never on the
 * command line. Each submission scripts the test-only FakeProvider and neutral
 * generated fixture result fields; the worker never prints a result value.
 * Started only by tests/Concurrency/ExamPinTest.php.
 *
 * Usage: php exam_pin_buy_worker.php <barrier> <user-id> <count> <script-csv> <delay-ms>
 */

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Providers\FakeProvider;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config(['providers.drivers' => ['fake-provider' => FakeProvider::class]]);

[, $barrier, $userId, $count, $script, $delay] = $argv;
$confirmation = (string) getenv('EXAM_PIN_TEST_CONFIRMATION');
FakeProvider::reset();
FakeProvider::$services = ['exam-pin'];
FakeProvider::$delayMs = (int) $delay;

try {
    DB::connection()->getPdo();
    $user = User::findOrFail((int) $userId);
    touch($barrier.'.ready.'.getmypid());
    $deadline = microtime(true) + 60;
    while (! file_exists($barrier)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "barrier timeout\n");
            exit(2);
        }
        usleep(500);
    }

    for ($i = 0; $i < (int) $count; $i++) {
        FakeProvider::$purchaseScript = explode(',', $script);
        FakeProvider::$resultScript = [FakeProvider::fixtureFields()];
        $request = Request::create('/buy/exam-pin', 'POST', ['confirmation' => $confirmation]);
        $app->instance('request', $request);
        Auth::guard('web')->setUser($user); // signed in, as the customer's session would be
        $response = $kernel->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'location' => parse_url((string) $response->headers->get('Location'), PHP_URL_PATH)]), "\n";
        $kernel->terminate($request, $response);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'worker error: '.$e::class."\n"); // the class only: never a message or trace
    exit(1);
}
