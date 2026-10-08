<?php

/*
 * Commission action concurrency worker (Phase 12 CP5): a separate PHP process
 * (own DB connection) that waits for its start file, then acts through the
 * app itself and prints one JSON line per action. Started only by
 * tests/Concurrency/CommissionActionRaceTest.php.
 *
 * Usage: php commission_action_worker.php <run> <start-file> <json spec>
 *   mode act         staff member `staff` POSTs the `type` form (reversal or cancellation) of commission `commission`
 *                    (its reference) through the HTTP kernel, signed in on the admin guard, with the one-time token from
 *                    COMMISSION_ACTION_TEST_TOKEN (never on the command line) and reason `reason`; prints ok with the
 *                    status message, refused with the form's error, or error with the exception class only
 *   mode debit, credit  `count` adjustments of `amount` kobo from or to customer `user`'s Main Wallet (WalletService)
 *   mode freeze      staff member `staff` freezes customer `user`'s Main Wallet (SetWalletStatus)
 *   mode hold-debit  in one transaction, debits `amount` kobo from customer `user`'s Main Wallet, touches <run>.locked,
 *                    keeps the wallet locked `hold` ms, then commits
 *   mode hold        locks customer `user`'s Main Wallet in an open transaction, touches <run>.locked, keeps it `hold` ms,
 *                    then commits without changing anything
 *   mode partner     the other side of a deadlock: waits for <run>.locked, then, in one transaction, makes many row
 *                    changes (so InnoDB picks the action as the victim), locks wallet `wallet`, touches
 *                    <run>.partner-locked and asks for commission row `commission_id`; rolls back once it has it
 *   `after`          a file suffix: once started, wait for <run>.<after> (then 20 ms) before acting
 *   `lock_wait_timeout`  seconds: this connection's innodb_lock_wait_timeout
 *   Test-only hooks for mode act (on the query log, so the app code is unchanged):
 *     `pause_commission`  ms to pause right after the action reads (and locks) the commission row, touching <run>.paused
 *     `pause_wallet`      ms to pause right after the reversal first locks the wallet, touching <run>.wallet-locked
 *     `wait_commission`   true: right after the commission row is locked, touch <run>.locked and wait for
 *                         <run>.partner-locked (then 400 ms), so that the partner worker can close a deadlock
 *     `die`               true: right after the reversal writes its ledger entry, touch <run>.locked and wait to be killed
 *   Each hook fires once per process. Every worker touches <run>.ready.<pid> once booted.
 */

use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Exceptions\Wallet\WalletException;
use App\Models\Commission;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $run, $start, $json] = $argv;
$spec = json_decode($json, true, flags: JSON_THROW_ON_ERROR) + ['count' => 1];

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
    static $commission = false, $wallet = false, $entry = false;
    if (! $commission && str_contains($query->sql, 'from `commissions` where `commissions`.`id` = ?')) { // with or without its lock
        $commission = true;
        if ($spec['wait_commission'] ?? false) {
            touch($run.'.locked');
            $waitFor($run.'.partner-locked');
            usleep(400_000); // the partner now holds the wallet and is waiting for this commission row
        }
        if (($spec['pause_commission'] ?? 0) > 0) {
            touch($run.'.paused');
            usleep($spec['pause_commission'] * 1000);
        }
    }
    if (! $wallet && str_contains($query->sql, 'from `wallets` where `wallets`.`id` = ?') && str_contains($query->sql, 'for update')
        && ($spec['pause_wallet'] ?? 0) > 0) {
        $wallet = true;
        touch($run.'.wallet-locked');
        usleep($spec['pause_wallet'] * 1000);
    }
    if (! $entry && str_starts_with($query->sql, 'insert into `wallet_ledger_entries`') && ($spec['die'] ?? false)) {
        $entry = true;
        touch($run.'.locked');
        sleep(60); // killed here: the debit is written, the action is not, nothing is committed
    }
});

DB::connection()->getPdo();
if (isset($spec['lock_wait_timeout'])) {
    DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $spec['lock_wait_timeout']);
}
touch($run.'.ready.'.getmypid());
$waitFor($start);
if (isset($spec['after'])) {
    $waitFor($run.'.'.$spec['after']);
    usleep(20_000);
}

$out = fn (array $line) => print json_encode($line)."\n";
$wallets = app(WalletService::class);

try {
    switch ($spec['mode']) {
        case 'act':
            $staff = SystemUser::findOrFail((int) $spec['staff']);
            $commission = Commission::where('reference', $spec['commission'])->sole();
            $path = '/admin/referrals/commissions/'.$commission->reference.'/'.($spec['type'] === 'reversal' ? 'reverse' : 'cancel');
            $request = Request::create($path, 'POST', ['reason' => $spec['reason'] ?? 'Taken back after a review of the purchase.',
                'confirm' => '1', 'token' => (string) getenv('COMMISSION_ACTION_TEST_TOKEN')]);
            $app->instance('request', $request);
            Auth::guard('admin')->setUser($staff); // signed in, as the staff member's session would be
            $response = $kernel->handle($request);
            $session = $request->hasSession() ? $request->session() : null;
            $error = $session?->get('errors')?->getBag($spec['type'])->first();
            $out(match (true) {
                $response->getStatusCode() === 302 && $session?->get('status') !== null => ['result' => 'ok', 'message' => $session->get('status')],
                $response->getStatusCode() === 302 && $error !== null && $error !== '' => ['result' => 'refused', 'message' => $error],
                default => ['result' => 'error', 'status' => $response->getStatusCode(),
                    'class' => isset($response->exception) ? $response->exception::class : null], // the class only: never a message or trace
            });
            $kernel->terminate($request, $response);
            break;
        case 'debit':
        case 'credit':
            for ($i = 0; $i < (int) $spec['count']; $i++) {
                try {
                    $wallet = $wallets->walletFor(User::findOrFail((int) $spec['user']));
                    $spec['mode'] === 'credit'
                        ? $wallets->credit($wallet, (int) $spec['amount'], LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Concurrency test credit')
                        : $wallets->debit($wallet, (int) $spec['amount'], LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Concurrency test debit');
                    $out(['result' => $spec['mode']]);
                } catch (WalletException $e) {
                    $out(['result' => 'refused', 'class' => $e::class]);
                }
            }
            break;
        case 'freeze':
            app(SetWalletStatus::class)->handle(User::findOrFail((int) $spec['user']), WalletStatus::Frozen, SystemUser::findOrFail((int) $spec['staff']));
            $out(['result' => 'freeze']);
            break;
        case 'hold-debit':
        case 'hold':
            DB::transaction(function () use ($spec, $run, $wallets) {
                $wallet = $wallets->walletFor(User::findOrFail((int) $spec['user']));
                if ($spec['mode'] === 'hold-debit') {
                    $wallets->debit($wallet, (int) $spec['amount'], LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Concurrency test debit');
                } else {
                    DB::table('wallets')->where('id', $wallet->id)->lockForUpdate()->first();
                }
                touch($run.'.locked');
                usleep((int) $spec['hold'] * 1000);
            });
            $out(['result' => $spec['mode']]);
            break;
        case 'partner':
            $waitFor($run.'.locked');
            DB::beginTransaction();
            for ($i = 0; $i < 300; $i++) {
                DB::table('cache')->insert(['key' => "deadlock-partner-{$i}", 'value' => 'x', 'expiration' => 0]);
            }
            DB::table('wallets')->where('id', (int) $spec['wallet'])->lockForUpdate()->first();
            touch($run.'.partner-locked');
            $from = microtime(true);
            DB::table('commissions')->where('id', (int) $spec['commission_id'])->lockForUpdate()->first();
            DB::rollBack();
            $out(['result' => 'partner', 'waited_ms' => (int) ((microtime(true) - $from) * 1000)]);
            break;
    }
} catch (Throwable $e) {
    $out(['result' => 'error', 'class' => $e::class]);
}
