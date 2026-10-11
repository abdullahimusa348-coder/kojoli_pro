<?php

use App\Models\KycReview;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Model approval concurrency worker (Phase 13 CP2a, finding F5): a separate PHP process with its own database connection. It records one
 * approval through the review model inside a transaction, writes a marker file once the review row is in, holds the transaction open for
 * the given seconds, then commits and prints one JSON line with the commit time. Started only by tests/Concurrency/KycApprovalPhoneRaceTest.php.
 *
 * Usage: php kyc_model_approval_worker.php <submission-id> <reviewer-id> <hold-seconds> <marker-file>
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $submission, $reviewer, $hold, $marker] = $argv;

try {
    DB::transaction(function () use ($submission, $reviewer, $hold, $marker) {
        (new KycReview)->forceFill(['kyc_submission_id' => (int) $submission, 'decision' => 'approved', 'reason' => null,
            'reviewed_by' => (int) $reviewer, 'decided_at' => now(), 'form_key' => (string) Str::uuid()])->save();
        file_put_contents($marker, 'written');
        usleep((int) ((float) $hold * 1_000_000));
    });
    echo json_encode(['result' => 'approved', 'committed_at' => microtime(true)], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR), "\n";
}
