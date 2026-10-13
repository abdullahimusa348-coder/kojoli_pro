<?php

use App\Models\User;
use App\Support\Enums\SystemRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a, finding F5, on real MariaDB: a model approval holds the customer's row lock until its transaction commits, so a phone
 * change that races it waits for the approval and lands after it. The approval runs in a separate process that holds its transaction open
 * for a few seconds after writing the review. Run with: php artisan test -c phpunit.concurrency.xml
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

it('makes a phone change wait for a model approval that is still open, and land after the approval commits', function () {
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    $staff = kycStaff(SystemRole::Manager);
    $marker = sys_get_temp_dir().'/kyc-approval-'.Str::uuid().'.marker';

    $approval = Process::path(base_path())
        ->env(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'])
        ->timeout(120)
        ->start([PHP_BINARY, base_path('tests/Concurrency/kyc_model_approval_worker.php'), (string) $submission->id, (string) $staff->id, '3', $marker]);

    $deadline = microtime(true) + 60;
    while (! file_exists($marker)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The approval did not write its review in time.');
        }
        usleep(10_000);
    }

    $startedAt = microtime(true);
    DB::table('users')->where('id', $customer->id)->update(['phone' => '08012345678']);
    $finishedAt = microtime(true);
    // The update needs the customer's row lock, which the open approval holds until it commits. So once the update returns, the review
    // is committed and visible here. Without the lock, the update returns at once, while the approval is still open. This reads what is
    // committed, because comparing clock readings from two processes is too fine at the moment the lock is released.
    $reviewCommitted = DB::table('kyc_reviews')->where('kyc_submission_id', $submission->id)->exists();

    $result = json_decode(trim($approval->wait()->output()), true, 512, JSON_THROW_ON_ERROR);
    @unlink($marker);

    expect($result['result'] ?? null)->toBe('approved')
        ->and($reviewCommitted)->toBeTrue()
        ->and($finishedAt - $startedAt)->toBeGreaterThan(1.0)
        ->and(User::find($customer->id)->phone)->toBe('08012345678');
});
