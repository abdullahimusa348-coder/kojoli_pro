<?php

/*
 * Buy NIN/BVN HTTP worker (Phase 11 CP3): a separate PHP process (own DB
 * connection) that waits at a start barrier, then POSTs the same sealed
 * confirmation to /buy/<service> through the HTTP kernel as the signed-in
 * customer, printing one JSON line per submission (status and redirect
 * path). The sealed confirmation arrives in IDENTITY_TEST_CONFIRMATION, never
 * on the command line; the worker never sees or prints the number. Each
 * submission scripts the test-only FakeProvider and neutral generated fixture
 * result fields. Started only by tests/Concurrency/IdentityBuyTest.php.
 *
 * Usage: php identity_buy_worker.php <barrier> <user-id> <service> <count> <script-csv> <delay-ms>
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

[, $barrier, $userId, $service, $count, $script, $delay] = $argv;
$confirmation = (string) getenv('IDENTITY_TEST_CONFIRMATION');
FakeProvider::reset();
FakeProvider::$services = ['nin', 'bvn'];
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
        $request = Request::create("/buy/{$service}", 'POST', ['confirmation' => $confirmation, 'consent' => '1']);
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
