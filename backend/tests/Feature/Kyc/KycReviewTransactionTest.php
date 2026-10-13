<?php

use App\Models\KycReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 13 CP2a: a review is recorded inside a transaction, so its checks and its row commit together. A direct save outside a
 * transaction is refused before any statement runs. Feature tests run inside RefreshDatabase's transaction, so the refused case first
 * leaves it. Nothing has been written at that point, and the test database is in-memory SQLite, so nothing outside the test is touched.
 */

/**
 * A review for a submission that does not exist. The transaction rule runs before any read: outside a transaction it is the rule that
 * refuses the save, and inside one the save reaches the submission check and stops there.
 */
function kycReviewWithoutSubmission(): KycReview
{
    return (new KycReview)->forceFill(['kyc_submission_id' => 0, 'decision' => 'rejected',
        'reason' => 'The document is not the one requested.', 'reviewed_by' => 1, 'decided_at' => now(),
        'form_key' => (string) Str::uuid()]);
}

it('passes the transaction rule inside a transaction, and stops at the missing submission', function () {
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    expect(fn () => kycReviewWithoutSubmission()->save())
        ->toThrow(LogicException::class, 'A KYC review belongs to a submission.');
});

it('refuses a direct review save outside a transaction, with the transaction message, and writes nothing', function () {
    // RefreshDatabase opened a transaction for this test, and the rule applies outside any transaction, so leave it. Nothing has been
    // written yet, so nothing that the test needs is undone.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $counts = fn () => [DB::table('kyc_reviews')->count(), DB::table('kyc_audit_events')->count(),
        DB::table('kyc_open_submissions')->count(), DB::table('kyc_submissions')->count()];
    $before = $counts();

    DB::enableQueryLog();
    expect(fn () => kycReviewWithoutSubmission()->save())
        ->toThrow(LogicException::class, 'A KYC review is recorded inside a transaction, so its checks and its row commit together.');
    $writes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1)->values()->all();
    DB::disableQueryLog();

    expect($writes)->toBe([])
        ->and($counts())->toBe($before);
});
