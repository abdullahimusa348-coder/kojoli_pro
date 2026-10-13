<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Raw approval concurrency worker (Phase 13 CP2a): a separate PHP process with its own database connection. It writes one approval for
 * a submission with the query builder, so only the database decides it, and prints one JSON line: {"result":"approved"} when the row
 * was written, {"result":"refused","message":...} when the approval trigger refused it, or {"result":"error","message":...} for anything
 * else. Started only by tests/Concurrency/KycSchemaMariaDbTest.php.
 *
 * Usage: php kyc_raw_approval_worker.php <submission-id> <reviewer-id>
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $submission, $reviewer] = $argv;
$refusal = 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.';

try {
    DB::table('kyc_reviews')->insert(['kyc_submission_id' => (int) $submission, 'decision' => 'approved', 'reason' => null,
        'reviewed_by' => (int) $reviewer, 'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now()]);
    echo json_encode(['result' => 'approved'], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    $refused = str_contains($e->getMessage(), $refusal);
    echo json_encode(['result' => $refused ? 'refused' : 'error', 'message' => $refused ? $refusal : $e->getMessage()], JSON_THROW_ON_ERROR), "\n";
}
