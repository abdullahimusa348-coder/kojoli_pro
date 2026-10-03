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
 *         | submit (the customer's confirmed Buy form, POSTed through the HTTP kernel as the signed-in customer;
 *                   key "service|token|phone|confirmed-kobo", token "unique" gives every submission a new one)
 *         | credit (WalletService adjustment credit of <target> kobo to the customer's wallet)
 *         | reprice (after <delay-ms>, sets the Subscriber price of plan <target> to <key> kobo)
 *         | freeze (after <delay-ms>, freezes the customer's wallet; prints the highest ledger entry id after the freeze committed)
 *   script: provider answers for purchase calls (buy/execute/submit) or status queries (reconcile/recheck)
 */

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Purchases\PurchaseService;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

if ($mode === 'reprice' || $mode === 'freeze') {
    usleep((int) $delay * 1000);
    if ($mode === 'reprice') {
        PlanPrice::where('plan_id', (int) $targetId)->where('user_type', UserType::Subscriber)->sole()->forceFill(['price_kobo' => (int) $key])->save();
        echo json_encode(['result' => 'repriced']), "\n";
    } else {
        $wallets = app(WalletService::class);
        $wallets->setStatus($wallets->walletFor(User::findOrFail((int) $userId)), WalletStatus::Frozen);
        echo json_encode(['result' => 'frozen', 'max_entry_id' => (int) WalletLedgerEntry::max('id')]), "\n";
    }
    exit(0);
}

for ($i = 0; $i < (int) $count; $i++) {
    $answers = $script === '-' ? [] : explode(',', $script);
    in_array($mode, ['reconcile', 'recheck'], true) ? FakeProvider::$queryScript = $answers : FakeProvider::$purchaseScript = $answers;
    try {
        if ($mode === 'reconcile') {
            echo json_encode(['result' => 'ok', 'stats' => $service->reconcile()]), "\n";

            continue;
        }
        if ($mode === 'credit') {
            $wallets = app(WalletService::class);
            $wallets->credit($wallets->walletFor(User::findOrFail((int) $userId)), (int) $targetId, LedgerEntryType::AdjustmentCredit,
                TransactionType::Adjustment, 'Concurrency test credit');
            echo json_encode(['result' => 'credited']), "\n";

            continue;
        }
        if ($mode === 'submit') {
            [$slug, $token, $phone, $confirmed] = explode('|', $key);
            $token = $token === 'unique' ? (string) Str::uuid() : $token;
            $request = Request::create('/buy/'.$slug, 'POST', ['plan' => (int) $targetId, 'phone' => $phone,
                'confirmed_amount_kobo' => (int) $confirmed, 'token' => $token]);
            Auth::guard('web')->setUser(User::findOrFail((int) $userId));
            $kernel = app(HttpKernel::class);
            $response = $kernel->handle($request);
            $location = (string) $response->headers->get('Location');
            $errors = $request->hasSession() ? $request->session()->get('errors')?->all() : null;
            $kernel->terminate($request, $response);
            echo json_encode(preg_match('#/purchases/(PUR-[0-9A-Z]{26})$#', $location, $m)
                ? ['result' => 'ok', 'reference' => $m[1], 'confirmed' => (int) $confirmed, 'phone' => $phone, 'http' => $response->getStatusCode()]
                : ['result' => $response->getStatusCode() === 302 ? 'refused' : 'error', 'http' => $response->getStatusCode(),
                    'message' => $errors ? implode(' ', $errors) : null, 'confirmed' => (int) $confirmed, 'phone' => $phone]), "\n";

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
