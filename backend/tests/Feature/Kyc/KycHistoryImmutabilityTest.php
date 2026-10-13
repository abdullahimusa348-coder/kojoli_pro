<?php

use App\Models\KycAuditEvent;
use App\Models\KycDocument;
use App\Models\KycOpenSubmission;
use App\Models\KycRequirementChange;
use App\Models\KycReview;
use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a history is append-only at two levels: the models refuse a change or a removal, and the database triggers refuse the
 * same through the query builder and raw SQL, which the models never see. Each test starts from one row in every history table, written
 * through the ledger and the document model, so inserts are proven to still work.
 */

beforeEach(fn () => kycSeed());

/** One row in every history table: a requirement change, a submission with its snapshot, identity values, a document and a decision. */
function kycHistoryFixture(): KycSubmission
{
    kycEnable('bvn');
    kycEnable('nin');
    kycEnable('document');
    $customer = kycCustomer();
    $submission = kycOpen($customer, ['bvn' => KYC_TEST_BVN, 'nin' => KYC_TEST_NIN]);

    (new KycDocument)->forceFill([
        'kyc_submission_id' => $submission->id, 'requirement_key' => 'document', 'storage_name' => strtoupper((string) Str::ulid()).'.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 2048, 'sha256' => str_repeat('c', 64), 'uploaded_at' => now(),
    ])->save();

    kycDecide($submission, kycStaff(SystemRole::Manager), KycStatus::Rejected, 'The document is not the one requested.');

    return $submission;
}

it('refuses every change and removal of a history row at the database level, and keeps the row', function (string $table) {
    kycHistoryFixture();
    $before = (array) DB::table($table)->orderBy('id')->first();

    expect(fn () => DB::table($table)->update(['created_at' => now()->addSecond()]))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::table($table)->delete())->toThrow(QueryException::class, 'append-only');
    expect((array) DB::table($table)->orderBy('id')->first())->toEqual($before);
})->with([
    'requirement changes' => ['kyc_requirement_changes'],
    'submissions' => ['kyc_submissions'],
    'requirement snapshots' => ['kyc_submission_requirements'],
    'identity values' => ['kyc_submission_identities'],
    'decisions' => ['kyc_reviews'],
    'documents' => ['kyc_documents'],
    'audit events' => ['kyc_audit_events'],
]);

it('refuses a model change or removal of a history row, and keeps the row', function (string $model) {
    kycHistoryFixture();
    $row = $model::query()->orderBy('id')->firstOrFail();
    $before = (array) DB::table($row->getTable())->where('id', $row->getKey())->first();

    expect(fn () => $row->forceFill(['created_at' => now()->addSecond()])->save())->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect((array) DB::table($row->getTable())->where('id', $row->getKey())->first())->toEqual($before);
})->with([
    'requirement change' => [KycRequirementChange::class],
    'submission' => [KycSubmission::class],
    'requirement snapshot' => [KycSubmissionRequirement::class],
    'identity value' => [KycSubmissionIdentity::class],
    'decision' => [KycReview::class],
    'document' => [KycDocument::class],
    'audit event' => [KycAuditEvent::class],
]);

it('refuses to change or remove an open slot before its submission has a decision', function () {
    kycEnable('bvn');
    kycEnable('nin');
    $customer = kycCustomer();
    kycOpen($customer);
    $slot = KycOpenSubmission::whereKey($customer->id)->sole();

    expect(fn () => $slot->forceFill(['created_at' => now()->addDay()])->save())->toThrow(LogicException::class);
    expect(fn () => $slot->delete())->toThrow(LogicException::class, 'A KYC submission leaves the open slot only once it has a decision.');
    expect(KycOpenSubmission::count())->toBe(1);
});

it('still accepts the new history rows a new submission writes, after the triggers exist', function () {
    $submission = kycHistoryFixture();
    $tables = ['kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities', 'kyc_audit_events'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);

    $again = kycOpen(kycCustomer(), ['bvn' => '44444444444', 'nin' => '55555555555']);

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan($before[$table]);
    }
    expect(KycSubmission::whereKey($submission->id)->exists())->toBeTrue();
    expect(KycAuditEvent::where('kyc_submission_id', $again->id)->count())->toBe(1);
    expect(KycAuditEvent::where('kyc_submission_id', $submission->id)->count())->toBe(2);
});
