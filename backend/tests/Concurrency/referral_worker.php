<?php

/*
 * Referral concurrency worker: a separate PHP process (own DB connection)
 * that waits for its start file, then acts once and prints one JSON line.
 * Started only by tests/Concurrency/ReferralSignupRaceTest.php.
 *
 * Usage: php referral_worker.php <run> <start-file> <mode> <id> <value> <delay-ms>
 *   mode: visit (GET /referrals through the HTTP kernel as customer <id>; the wallet and code inserts each pause
 *                <delay-ms> first, so the visits really overlap; prints the code shown)
 *         | signup (POST /register as applicant number <id> with referral code <value>; the account insert touches
 *                  <run>.locked and pauses <delay-ms>, while the owner's row is share-locked)
 *         | disable (disables customer <id> as staff member <value> through ChangeCustomerStatus; prints the highest
 *                   referral link id once the disable has committed)
 *         | hold-disable (disables customer <id> in an open transaction, touches <run>.locked, and commits once
 *                        <run>.release exists)
 *   Every worker touches <run>.ready.<pid> once booted.
 */

use App\Actions\Customers\ChangeCustomerStatus;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Enums\UserStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $run, $start, $mode, $id, $value, $delay] = $argv;
$pause = fn () => usleep((int) $delay * 1000);

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

DB::connection()->getPdo();
touch($run.'.ready.'.getmypid());
$waitFor($start);

/** Handles $request through the HTTP kernel; returns [status, location, errors]. */
$handle = function (Request $request): array {
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $errors = $request->hasSession() ? $request->session()->get('errors')?->all() : null;
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), (string) $response->headers->get('Location'), $errors, $response->getContent()];
};

try {
    if ($mode === 'visit') {
        Wallet::creating($pause);
        ReferralCode::creating($pause);
        Auth::guard('web')->setUser(User::findOrFail((int) $id));
        [$status, , , $html] = $handle(Request::create('/referrals', 'GET'));
        preg_match('/data-referral-code>([A-Z0-9]+)</', (string) $html, $code);
        echo json_encode(['result' => $status === 200 ? 'ok' : 'error', 'http' => $status, 'code' => $code[1] ?? null]), "\n";
    } elseif ($mode === 'signup') {
        User::creating(function () use ($run, $pause) {
            touch($run.'.locked');
            $pause();
        });
        [$status, $location, $errors] = $handle(Request::create('/register', 'POST', ['name' => "Applicant {$id}", 'email' => "applicant{$id}@example.com",
            'phone' => '0803'.str_pad((string) $id, 7, '0', STR_PAD_LEFT), 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'referral_code' => $value]));
        echo json_encode(['result' => match (true) {
            (bool) $errors => 'refused',
            $status === 302 && str_ends_with($location, '/dashboard') => 'registered',
            default => 'error',
        }, 'http' => $status, 'message' => $errors ? implode(' ', $errors) : null, 'email' => "applicant{$id}@example.com"]), "\n";
    } elseif ($mode === 'disable') {
        app(ChangeCustomerStatus::class)->handle(User::findOrFail((int) $id), UserStatus::Disabled, SystemUser::findOrFail((int) $value));
        echo json_encode(['result' => 'disabled', 'max_referral_id' => (int) Referral::max('id')]), "\n";
    } elseif ($mode === 'hold-disable') {
        DB::beginTransaction();
        DB::table('users')->where('id', (int) $id)->update(['status' => UserStatus::Disabled->value]);
        touch($run.'.locked');
        $waitFor($run.'.release');
        DB::commit();
        echo json_encode(['result' => 'disabled']), "\n";
    }
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}
