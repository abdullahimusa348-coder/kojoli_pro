<?php

/*
 * KYC requirement concurrency worker: a separate PHP process (own DB connection) that waits at a start barrier, then either
 * submits the edit form of one requirement or holds its row lock, printing one JSON line. Started only by
 * tests/Concurrency/KycRequirementRaceTest.php.
 *
 * Usage: php kyc_requirement_worker.php <barrier> <mode> <staff-id> <key> <form-json> <delay-ms>
 *   mode: submit (the edit form, PUT through the HTTP kernel as the signed-in staff member, with the form's values; the
 *                 save pauses <delay-ms> after its checks and before writing, so the workers really overlap; prints when
 *                 it reached the write, or null if it was refused first)
 *         | hold (locks the requirement's row until <barrier>.release exists)
 */

use App\Models\KycRequirement;
use App\Models\SystemUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $barrier, $mode, $staffId, $key, $formJson, $delay] = $argv;
$form = json_decode($formJson, true, 512, JSON_THROW_ON_ERROR);
$wrote = null;
KycRequirement::saving(function () use (&$wrote, $delay) {
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
        KycRequirement::where('key', $key)->lockForUpdate()->first();
        touch($barrier.'.locked');
        $waitFor($barrier.'.release');
        DB::rollBack();
        echo json_encode(['result' => 'released']), "\n";
        exit(0);
    }

    $request = Request::create('/admin/kyc/requirements/'.$key, 'PUT', $form);
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
        'label' => $form['label'] ?? null, 'reason' => $form['reason'] ?? null, 'wrote' => $wrote]), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]), "\n";
}
