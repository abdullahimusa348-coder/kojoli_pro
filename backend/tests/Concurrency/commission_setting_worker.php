<?php

/*
 * Commission settings concurrency worker: a separate PHP process (own DB
 * connection) that waits at a start barrier, then either submits the Rates &
 * caps edit form or holds the locks of a save, printing one JSON line.
 * Started only by tests/Concurrency/CommissionSettingRaceTest.php.
 *
 * Usage: php commission_setting_worker.php <barrier> <mode> <staff-id> <service-slug> <rate> <cap> <fingerprint> <delay-ms>
 *   mode: submit (the edit form, PUT through the HTTP kernel as the signed-in staff member, with its own reason; the
 *                 save pauses <delay-ms> after its checks and before writing, so the workers really overlap; prints
 *                 when it reached the write, or null if it was refused first)
 *         | hold (takes the locks a save of the service takes, its service row and its setting row once there is one,
 *                 until <barrier>.release exists)
 */

use App\Models\CommissionSetting;
use App\Models\Service;
use App\Models\SystemUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $barrier, $mode, $staffId, $slug, $rate, $cap, $fingerprint, $delay] = $argv;
$wrote = null;
CommissionSetting::saving(function () use (&$wrote, $delay) {
    $wrote = microtime(true);
    usleep((int) $delay * 1000);
});

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
touch($barrier.'.ready.'.getmypid());
$waitFor($barrier);

try {
    if ($mode === 'hold') {
        DB::beginTransaction();
        $serviceId = Service::where('slug', $slug)->lockForUpdate()->value('id');
        if (($settingId = CommissionSetting::where('service_id', $serviceId)->value('id')) !== null) {
            CommissionSetting::whereKey($settingId)->lockForUpdate()->first();
        }
        touch($barrier.'.locked');
        $waitFor($barrier.'.release');
        DB::rollBack();
        echo json_encode(['result' => 'released']), "\n";
        exit(0);
    }

    $reason = 'Concurrent change from worker '.getmypid();
    $request = Request::create('/admin/referrals/rates/'.$slug, 'PUT', ['rate' => $rate, 'cap' => $cap, 'reason' => $reason,
        'confirm' => '1', 'fingerprint' => $fingerprint]);
    Auth::guard('admin')->setUser(SystemUser::findOrFail((int) $staffId));
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $errors = $request->hasSession() ? $request->session()->get('errors')?->all() : null;
    $status = $request->hasSession() ? $request->session()->get('status') : null;
    $kernel->terminate($request, $response);

    $result = match (true) {
        $response->getStatusCode() !== 302 => 'error',
        (bool) $errors => 'refused',
        is_string($status) && str_starts_with($status, 'No changes') => 'unchanged',
        is_string($status) => 'saved',
        default => 'error',
    };
    echo json_encode(['result' => $result, 'http' => $response->getStatusCode(), 'message' => $errors ? implode(' ', $errors) : $status,
        'slug' => $slug, 'rate' => $rate, 'cap' => $cap, 'reason' => $reason, 'wrote' => $wrote]), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}
