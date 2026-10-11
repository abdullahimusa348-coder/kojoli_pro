<?php

/*
 * KYC submission concurrency worker (Phase 13 CP2a): a separate PHP process with its own database connection. It waits at a start
 * barrier, then either sends a verification submission for a customer or records a staff decision on one, through the ledger, and
 * prints one JSON line. The write pauses <delay-ms> after the ledger has taken its locks and before the row is written, which makes
 * overlap likely. Nothing asserts that overlap happened. The pause creates <barrier>.paused first, so a test can wait until the locks are
 * held. Started only by
 * tests/Concurrency/KycSubmissionRaceTest.php.
 *
 * Usage: php kyc_submission_worker.php <barrier> <mode> <id> <actor-id> <argument-json> <delay-ms>
 *   mode: open   (<id> is the customer; the argument holds the kind, the identity numbers and the form key)
 *       | decide (<id> is the submission and <actor-id> the reviewer; the argument holds the decision, the reason and the form key)
 *   prints: {"result":"ok", ...} when the ledger returned or wrote a row; {"result":"refused","message":...} for a KYC refusal (no
 *           identity number is ever in a message); {"result":"error", ...} for anything else. "wrote" is the time the write paused,
 *           or null when this worker never reached the write.
 */

use App\Exceptions\Kyc\KycException;
use App\Models\KycOpenSubmission;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Kyc\KycSubmissionLedger;
use App\Support\Kyc\KycStatus;
use App\Support\Kyc\KycSubmissionKind;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $barrier, $mode, $id, $actorId, $argumentJson, $delay] = $argv;
$argument = json_decode($argumentJson, true, 512, JSON_THROW_ON_ERROR);
$wrote = null;
$pause = function () use (&$wrote, $delay, $barrier) {
    $wrote = microtime(true);
    touch($barrier.'.paused');
    usleep((int) $delay * 1000);
};

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
    $ledger = app(KycSubmissionLedger::class);
    if ($mode === 'open') {
        KycOpenSubmission::creating($pause);
        $submission = $ledger->open(User::findOrFail((int) $id), KycSubmissionKind::from($argument['kind']), $argument['numbers'],
            $argument['form_key']);
        $row = ['id' => $submission->id, 'reference' => $submission->reference];
    } else {
        KycReview::creating($pause);
        $review = $ledger->decide(KycSubmission::findOrFail((int) $id), SystemUser::findOrFail((int) $actorId),
            KycStatus::from($argument['decision']), $argument['reason'], $argument['form_key']);
        $row = ['id' => $review->id, 'decision' => $review->decision->value];
    }
    echo json_encode(['result' => 'ok', 'wrote' => $wrote] + $row), "\n";
} catch (KycException $e) {
    echo json_encode(['result' => 'refused', 'message' => $e->getMessage(), 'wrote' => $wrote]), "\n";
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage(), 'wrote' => $wrote]), "\n";
}
