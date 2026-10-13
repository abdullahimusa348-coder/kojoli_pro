<?php

use App\Models\KycAuditEvent;
use App\Models\KycOpenSubmission;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Support\Enums\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a on real MariaDB: two sends of one customer's verification, and two decisions on one submission, released together from a barrier,
 * as separate PHP processes. The customer row serialises the writes, so exactly one send or decision is recorded. The other is
 * refused, or, when it repeats the same form, returns the row that form already made; either way it writes nothing. A document written
 * while a decision is in progress waits for that decision, and is then refused.
 * Run with: php artisan test -c phpunit.concurrency.xml
 */

beforeEach(function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Concurrency tests need MariaDB: run with -c phpunit.concurrency.xml.');
    }
    expect(DB::connection()->getDatabaseName())->toBe('nadabo_concurrency_test');
    Artisan::call('migrate:fresh', ['--force' => true]);
    kycSeed();
    kycEnable('bvn');
    kycEnable('nin');
});

/**
 * Starts the workers together and returns their JSON lines.
 *
 * @param  list<array{0: string, 1: int, 2: int, 3: array<string, mixed>, 4: int}>  $workers  [mode, id, actor id, argument, delayMs]
 * @return list<array<string, mixed>>
 */
function kycSubmissionRace(array $workers): array
{
    $barrier = kycSubmissionBarrier();

    $pool = Process::pool(function ($pool) use ($workers, $barrier) {
        foreach ($workers as $w) {
            $pool->path(base_path())->env(kycSubmissionWorkerEnv())->timeout(120)->command(kycSubmissionWorkerCommand($barrier, $w));
        }
    })->start();

    try {
        $deadline = microtime(true) + 60;
        while (count(glob($barrier.'.ready.*')) < count($workers) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(count(glob($barrier.'.ready.*')))->toBe(count($workers));
        touch($barrier);
        $results = $pool->wait();
    } finally {
        kycSubmissionCleanup($barrier);
    }

    $lines = [];
    foreach ($results->collect() as $result) {
        expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput());
        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $lines[] = json_decode($line, true);
        }
    }

    return $lines;
}

/** A fresh barrier path for one set of workers. The workers wait for it, and the test touches it to start them. */
function kycSubmissionBarrier(): string
{
    return sys_get_temp_dir().'/kyc-submission-race-'.uniqid();
}

/** The environment every worker runs with: the MariaDB concurrency database, the testing app and the array cache. */
function kycSubmissionWorkerEnv(): array
{
    return ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];
}

/** The command that runs one worker, [mode, id, actor id, argument, delayMs], against a barrier. */
function kycSubmissionWorkerCommand(string $barrier, array $worker): array
{
    return [PHP_BINARY, base_path('tests/Concurrency/kyc_submission_worker.php'), $barrier, $worker[0], (string) $worker[1],
        (string) $worker[2], json_encode($worker[3], JSON_THROW_ON_ERROR), (string) $worker[4]];
}

/** Removes a barrier and the markers its workers left behind. */
function kycSubmissionCleanup(string $barrier): void
{
    array_map('unlink', array_filter([$barrier, $barrier.'.paused', ...glob($barrier.'.ready.*')], 'file_exists'));
}

/** Polls until the condition holds, and fails the test when it does not hold within a minute. */
function kycSubmissionWait(Closure $condition): void
{
    $deadline = microtime(true) + 60;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('A KYC worker did not reach its checkpoint in time.');
        }
        usleep(10_000);
    }
}

/** The arguments of one send: the kind, the identity numbers of the requested identities, and the form's key. */
function kycSubmissionSend(string $formKey): array
{
    return ['kind' => 'initial', 'numbers' => ['bvn' => KYC_TEST_BVN, 'nin' => KYC_TEST_NIN], 'form_key' => $formKey];
}

it('lets one of two sends of one customer\'s verification win, and refuses the other as already waiting for review', function () {
    $customer = kycCustomer();

    $results = collect(kycSubmissionRace([
        ['open', $customer->id, 0, kycSubmissionSend((string) Str::uuid()), 300],
        ['open', $customer->id, 0, kycSubmissionSend((string) Str::uuid()), 300],
    ]));

    $winner = $results->firstWhere('result', 'ok');
    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'ok'))->toHaveCount(1)
        ->and($results->where('result', 'refused')->pluck('message')->all())->toBe(['A verification submission is already waiting for review.'])
        ->and($results->whereNotNull('wrote'))->toHaveCount(1) // only the winner reached the write
        ->and(KycSubmission::where('user_id', $customer->id)->pluck('id')->all())->toBe([$winner['id']])
        ->and(KycOpenSubmission::whereKey($customer->id)->value('kyc_submission_id'))->toBe($winner['id'])
        ->and(KycAuditEvent::where('event', 'submission.opened')->count())->toBe(1)
        ->and(DB::table('kyc_profiles')->where('user_id', $customer->id)->value('status'))->toBe('pending_review');
});

it('gives two sends of one form the same submission, and records it once', function () {
    $customer = kycCustomer();
    $send = ['open', $customer->id, 0, kycSubmissionSend((string) Str::uuid()), 300];

    $results = collect(kycSubmissionRace([$send, $send]));

    expect($results->where('result', 'ok'))->toHaveCount(2)
        ->and($results->pluck('id')->unique()->all())->toHaveCount(1)
        ->and($results->whereNotNull('wrote'))->toHaveCount(1)
        ->and(KycSubmission::where('user_id', $customer->id)->count())->toBe(1)
        ->and(KycAuditEvent::where('event', 'submission.opened')->count())->toBe(1);
});

it('lets one of two decisions on one submission win, from two reviewers, and refuses the other as already decided', function () {
    $submission = kycOpen(kycCustomer());
    $first = kycStaff(SystemRole::Manager);
    $second = kycStaff(SystemRole::Manager);

    $results = collect(kycSubmissionRace([
        ['decide', $submission->id, $first->id, ['decision' => 'approved', 'reason' => null, 'form_key' => (string) Str::uuid()], 300],
        ['decide', $submission->id, $second->id, ['decision' => 'rejected', 'reason' => 'The document is not the one requested.',
            'form_key' => (string) Str::uuid()], 300],
    ]));

    $winner = $results->firstWhere('result', 'ok');
    expect($results->where('result', 'error')->values()->all())->toBe([])
        ->and($results->where('result', 'ok'))->toHaveCount(1)
        ->and($results->where('result', 'refused')->pluck('message')->all())
        ->toBe(['This submission has already been decided. Reload the page to see its status.'])
        ->and($results->whereNotNull('wrote'))->toHaveCount(1)
        ->and(KycReview::where('kyc_submission_id', $submission->id)->pluck('id')->all())->toBe([$winner['id']])
        ->and(KycOpenSubmission::where('kyc_submission_id', $submission->id)->exists())->toBeFalse()
        ->and(DB::table('kyc_profiles')->where('user_id', $submission->user_id)->value('status'))->toBe($winner['decision'])
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(1);
});

it('gives two decisions from one form the same review, and records it once', function () {
    $submission = kycOpen(kycCustomer());
    $reviewer = kycStaff(SystemRole::Manager);
    $decide = ['decide', $submission->id, $reviewer->id, ['decision' => 'rejected', 'reason' => 'The document is not the one requested.',
        'form_key' => (string) Str::uuid()], 300];

    $results = collect(kycSubmissionRace([$decide, $decide]));

    expect($results->where('result', 'ok'))->toHaveCount(2)
        ->and($results->pluck('id')->unique()->all())->toHaveCount(1)
        ->and(KycReview::count())->toBe(1)
        ->and(KycAuditEvent::where('event', 'review.decided')->count())->toBe(1);
});

it('refuses a document written while a decision on its submission is in progress, and writes nothing', function () {
    kycEnable('document');
    $submission = kycOpen(kycCustomer());
    $reviewer = kycStaff(SystemRole::Manager);
    $barrier = kycSubmissionBarrier();
    $decide = ['decide', $submission->id, $reviewer->id, ['decision' => 'rejected', 'reason' => 'The document is not the one requested.',
        'form_key' => (string) Str::uuid()], 1500];

    $worker = Process::path(base_path())->env(kycSubmissionWorkerEnv())->timeout(120)->start(kycSubmissionWorkerCommand($barrier, $decide));

    try {
        kycSubmissionWait(fn () => count(glob($barrier.'.ready.*')) === 1);
        touch($barrier);
        kycSubmissionWait(fn () => file_exists($barrier.'.paused'));

        // The decision holds its locks and has not written its review yet. The document waits for the decision, then the trigger refuses it.
        expect(DB::table('kyc_reviews')->count())->toBe(0);
        expect(fn () => DB::table('kyc_documents')->insert(['kyc_submission_id' => $submission->id, 'requirement_key' => 'document',
            'storage_name' => strtoupper((string) Str::ulid()).'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 2048,
            'sha256' => str_repeat('d', 64), 'uploaded_at' => now(), 'created_at' => now()]))
            ->toThrow(QueryException::class, 'A KYC submission takes no new document once it has a decision.');
    } finally {
        $result = $worker->wait();
        kycSubmissionCleanup($barrier);
    }

    expect($result->successful())->toBeTrue('worker failed: '.$result->errorOutput())
        ->and(json_decode(trim($result->output()), true)['result'])->toBe('ok')
        ->and(KycReview::where('kyc_submission_id', $submission->id)->count())->toBe(1)
        ->and(DB::table('kyc_documents')->where('kyc_submission_id', $submission->id)->count())->toBe(0);
});
