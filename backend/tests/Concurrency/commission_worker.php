<?php

/*
 * Commission concurrency worker: a separate PHP process (own DB connection)
 * that waits for its start file, then acts through the app's own services
 * and prints one JSON line per action. Started only by
 * tests/Concurrency/CommissionEngineRaceTest.php.
 *
 * Usage: php commission_worker.php <run> <start-file> <json spec>
 *   mode buy        each customer in `buyers` in turn buys plan `plan` (`count` purchases in all) through PurchaseService,
 *                   the test-only FakeProvider answering `answer`; prints the purchase id and status
 *   mode recheck    staff member `staff` re-checks purchase `purchase` `count` times, the status lookup answering `answer`
 *   mode reconcile  `count` scheduled re-check runs, the status lookups answering `answer`
 *   mode execute    executes purchase `purchase` `count` times
 *   Each of these four waits until a commission step is in the middle (<run>.paused, see `pause`), then acts and prints
 *   the highest ledger entry id and commission id once its change has committed:
 *   mode freeze     staff member `staff` freezes customer `user`'s Main Wallet
 *   mode disable    staff member `staff` disables customer `user`
 *   mode retype     staff member `staff` makes customer `user` type `type`
 *   mode rate       staff member `staff` sets service `service`'s rate `rate` and cap `cap`
 *   mode credit, debit  `count` adjustments of `amount` kobo to or from customer `user`'s Main Wallet
 *   mode login      `count` writes to customer `user`'s row, as each login records its time (an exclusive row lock)
 *   mode verify     runs commissions:verify `count` times; prints clean, or the problems it reported
 *   mode hold       locks customer `user`'s Main Wallet in an open transaction, touches <run>.locked, keeps it `hold` ms, then commits
 *   mode partner    the other side of a deadlock: waits for <run>.locked, then, in one transaction, makes many row changes
 *                   (so InnoDB picks the commission step as the victim), takes customer `user`'s users row exclusively,
 *                   touches <run>.partner-locked and asks for wallet `wallet` exclusively; rolls back once it has it
 *   Test-only hooks for mode buy, recheck and reconcile (on the query log, so the app code is unchanged):
 *     `pause`        ms to pause right after the commission step reads (and share-locks) the commission setting, touching
 *                    <run>.paused first, so that a change by another worker lands in the middle of the step
 *     `wait_wallet`  wallet id: the first time the commission step locks it, touch <run>.locked and wait for
 *                    <run>.partner-locked (then 400 ms), so that the partner worker can close a deadlock
 *     `die`          true: right after the commission step reads the setting, touch <run>.locked and wait to be killed
 *   Every worker touches <run>.ready.<pid> once booted.
 */

use App\Actions\Admin\Purchases\RecheckPurchase;
use App\Actions\Admin\Referrals\SaveCommissionSetting;
use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Exceptions\Purchases\PurchaseException;
use App\Models\Commission;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Purchases\PurchaseService;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['providers.drivers' => ['fake-provider' => FakeProvider::class]]);

[, $run, $start, $json] = $argv;
$spec = json_decode($json, true, flags: JSON_THROW_ON_ERROR) + ['count' => 1, 'answer' => 'succeeded', 'pause' => 0];
FakeProvider::reset();
FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
FakeProvider::$delayMs = (int) ($spec['provider_ms'] ?? 0);

$waitFor = function (string $file): void {
    $deadline = microtime(true) + 60;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "timeout waiting for {$file}\n");
            exit(2);
        }
        usleep(500);
    }
};

DB::listen(function (QueryExecuted $query) use ($spec, $run, $waitFor) {
    static $waited = false;
    $settingLock = str_contains($query->sql, 'from `commission_settings` where `commission_settings`.`id` = ?'); // its locked read
    if ($settingLock && ($spec['die'] ?? false)) {
        touch($run.'.locked');
        sleep(60); // killed here, in the middle of the commission step
    }
    if ($settingLock && $spec['pause'] > 0) {
        touch($run.'.paused'); // a commission step is now in the middle, holding its locks
        usleep($spec['pause'] * 1000);
    }
    if (! $waited && isset($spec['wait_wallet']) && str_contains($query->sql, '`wallets`') && str_contains($query->sql, 'for update')
        && $query->bindings === [$spec['wait_wallet']]) {
        $waited = true;
        touch($run.'.locked');
        $waitFor($run.'.partner-locked');
        usleep(400_000); // the partner is now waiting for this wallet
    }
});

DB::connection()->getPdo();
touch($run.'.ready.'.getmypid());
$waitFor($start);

$purchases = app(PurchaseService::class);
$staff = fn () => SystemUser::findOrFail((int) $spec['staff']);
$out = fn (array $line) => print json_encode($line)."\n";

try {
    if (in_array($spec['mode'], ['freeze', 'disable', 'retype', 'rate'], true)) {
        $waitFor($run.'.paused');
        usleep(20_000);
        match ($spec['mode']) {
            'freeze' => app(SetWalletStatus::class)->handle(User::findOrFail((int) $spec['user']), WalletStatus::Frozen, $staff()),
            'disable' => app(ChangeCustomerStatus::class)->handle(User::findOrFail((int) $spec['user']), UserStatus::Disabled, $staff()),
            'retype' => app(ChangeUserType::class)->handle(User::findOrFail((int) $spec['user']), UserType::from($spec['type']), $staff()),
            'rate' => app(SaveCommissionSetting::class)->handle($service = Service::where('slug', $spec['service'])->sole(), (int) $spec['rate'],
                (int) $spec['cap'], 'Rate changed during a concurrency test.', $staff(), SaveCommissionSetting::fingerprint($service)),
        };
        $out(['result' => $spec['mode'], 'max_entry_id' => (int) WalletLedgerEntry::max('id'), 'max_commission_id' => (int) Commission::max('id')]);
        exit(0);
    }

    if ($spec['mode'] === 'hold') {
        DB::transaction(function () use ($spec, $run) {
            DB::table('wallets')->where('user_id', (int) $spec['user'])->lockForUpdate()->first();
            touch($run.'.locked');
            usleep((int) $spec['hold'] * 1000);
        });
        $out(['result' => 'held']);
        exit(0);
    }

    if ($spec['mode'] === 'partner') {
        $waitFor($run.'.locked');
        DB::beginTransaction();
        for ($i = 0; $i < 300; $i++) {
            DB::table('cache')->insert(['key' => "deadlock-partner-{$i}", 'value' => 'x', 'expiration' => 0]);
        }
        DB::table('users')->where('id', (int) $spec['user'])->update(['updated_at' => now()]);
        touch($run.'.partner-locked');
        $from = microtime(true);
        DB::table('wallets')->where('id', (int) $spec['wallet'])->lockForUpdate()->first();
        DB::rollBack();
        $out(['result' => 'partner', 'waited_ms' => (int) ((microtime(true) - $from) * 1000)]);
        exit(0);
    }

    for ($i = 0; $i < (int) $spec['count']; $i++) {
        $answers = explode(',', $spec['answer']);
        try {
            switch ($spec['mode']) {
                case 'buy':
                    FakeProvider::$purchaseScript = $answers;
                    $buyer = User::findOrFail((int) $spec['buyers'][$i % count($spec['buyers'])]);
                    $purchase = $purchases->purchase($buyer, Plan::findOrFail((int) $spec['plan']), '08012345678', null, (string) Str::uuid());
                    $out(['result' => 'ok', 'purchase' => $purchase->id, 'buyer' => $buyer->id, 'status' => $purchase->status->value]);
                    break;
                case 'recheck':
                    FakeProvider::$queryScript = $answers;
                    $purchase = app(RecheckPurchase::class)->handle(Purchase::findOrFail((int) $spec['purchase']), $staff());
                    $out(['result' => 'ok', 'purchase' => $purchase->id, 'status' => $purchase->status->value]);
                    break;
                case 'reconcile':
                    FakeProvider::$queryScript = $answers;
                    $out(['result' => 'ok', 'stats' => $purchases->reconcile()]);
                    break;
                case 'execute':
                    $purchase = $purchases->execute(Purchase::findOrFail((int) $spec['purchase']));
                    $out(['result' => 'ok', 'purchase' => $purchase->id, 'status' => $purchase->status->value]);
                    break;
                case 'credit':
                case 'debit':
                    $wallets = app(WalletService::class);
                    $wallet = $wallets->walletFor(User::findOrFail((int) $spec['user']));
                    $spec['mode'] === 'credit'
                        ? $wallets->credit($wallet, (int) $spec['amount'], LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Concurrency test credit')
                        : $wallets->debit($wallet, (int) $spec['amount'], LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Concurrency test debit');
                    $out(['result' => $spec['mode']]);
                    break;
                case 'login':
                    DB::table('users')->where('id', (int) $spec['user'])->update(['last_login_at' => now()]);
                    usleep(5_000);
                    $out(['result' => 'login']);
                    break;
                case 'verify':
                    $code = Artisan::call('commissions:verify');
                    $out($code === 0 ? ['result' => 'clean'] : ['result' => 'problems', 'output' => trim(Artisan::output())]);
                    break;
            }
        } catch (PurchaseException $e) {
            $out(['result' => 'refused', 'message' => $e->getMessage()]);
        }
    }
} catch (Throwable $e) {
    $out(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]);
}
