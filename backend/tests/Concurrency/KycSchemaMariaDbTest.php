<?php

use App\Models\KycRequirement;
use App\Models\KycSubmission;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Kyc\KycStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a on real MariaDB: the CHECK rules, the composite keys and the unique slot and decision that repeat the model guards,
 * and the triggers that keep history append-only. Each refusal writes its row through the query builder, so only the database can
 * refuse it. A raw approval that races a requirement another session writes waits for that write and then sees it: one test takes its
 * snapshot before the write commits, and one runs the approval in a separate process while the write is still open. Synthetic data only:
 * the identity values are placeholders, and no test holds a real number.
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

/** The names in text order, so two lists of names compare as sets. */
function kycSchemaSorted(array $names): array
{
    sort($names, SORT_STRING);

    return $names;
}

/** A submission row for the customer, valid unless overridden. */
function kycSchemaSubmissionRow(User $customer, array $overrides = []): array
{
    return $overrides + ['reference' => 'KYC-'.strtoupper((string) Str::ulid()), 'user_id' => $customer->id, 'kind' => 'initial',
        'user_type' => 'subscriber', 'form_key' => (string) Str::uuid(), 'submitted_at' => now(), 'created_at' => now()];
}

/** A submission for the customer with no snapshot, identity, document or decision. Returns its id. */
function kycSchemaRawSubmission(User $customer): int
{
    return DB::table('kyc_submissions')->insertGetId(kycSchemaSubmissionRow($customer));
}

/** A requirement snapshot row for a submission, valid unless overridden. Only the phone row carries a fingerprint. */
function kycSchemaSnapshotRow(int $submissionId, string $key, array $overrides = []): array
{
    $requirement = KycRequirement::where('key', $key)->sole();

    return $overrides + ['kyc_submission_id' => $submissionId, 'kyc_requirement_id' => $requirement->id, 'requirement_key' => $key,
        'requirement_type' => $requirement->type, 'label' => $requirement->label,
        'phone_fingerprint' => $key === 'phone' ? str_repeat('a', 64) : null, 'created_at' => now()];
}

/** A new submission for the customer that holds one snapshot of the requirement, so an identity or document can name it. Returns its id. */
function kycSchemaSnapshotted(User $customer, string $key): int
{
    $submissionId = kycSchemaRawSubmission($customer);
    DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submissionId, $key));

    return $submissionId;
}

/** An identity row for a submission, valid unless overridden. The ciphertext is a placeholder. */
function kycSchemaIdentityRow(int $submissionId, string $key, array $overrides = []): array
{
    return $overrides + ['kyc_submission_id' => $submissionId, 'requirement_key' => $key, 'ciphertext' => 'placeholder-ciphertext',
        'masked_value' => '•••••••2222', 'lookup_hash' => str_repeat('a', 64), 'created_at' => now()];
}

/** A document row for a submission, valid unless overridden. */
function kycSchemaDocumentRow(int $submissionId, array $overrides = []): array
{
    return $overrides + ['kyc_submission_id' => $submissionId, 'requirement_key' => 'document',
        'storage_name' => strtoupper((string) Str::ulid()).'.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024,
        'sha256' => str_repeat('b', 64), 'uploaded_at' => now(), 'created_at' => now()];
}

/** A decision row for a submission, valid unless overridden: a rejection with a reason. */
function kycSchemaReviewRow(int $submissionId, int $staffId, array $overrides = []): array
{
    return $overrides + ['kyc_submission_id' => $submissionId, 'decision' => 'rejected', 'reason' => 'The document is not the one requested.',
        'reviewed_by' => $staffId, 'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now()];
}

/** An audit event about a customer's submission, valid unless overridden. */
function kycSchemaAuditRow(int $customerId, int $submissionId, array $overrides = []): array
{
    return $overrides + ['event' => 'submission.opened', 'user_id' => $customerId, 'kyc_submission_id' => $submissionId,
        'kyc_document_id' => null, 'system_user_id' => null, 'metadata' => json_encode(['kind' => 'initial', 'requirement_count' => 2]),
        'created_at' => now()];
}

/** A customer with an open submission (BVN and NIN, through the ledger), a document snapshot and a document, and a manager. */
function kycSchemaBase(): array
{
    $customer = kycCustomer();
    $submission = kycOpen($customer);
    DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission->id, 'document'));
    $document = DB::table('kyc_documents')->insertGetId(kycSchemaDocumentRow($submission->id));

    return ['customer' => $customer, 'submission' => $submission->id, 'staff' => kycStaff(SystemRole::Manager), 'document' => $document];
}

it('keeps every CHECK rule the model guards repeat, under the names the migrations give them', function () {
    $checks = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
        ->whereIn('TABLE_NAME', ['kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities', 'kyc_reviews',
            'kyc_documents', 'kyc_audit_events'])
        ->pluck('CONSTRAINT_NAME')->all();

    // MariaDB also keeps the JSON-validity check that Laravel's json column adds, named after the column (metadata).
    expect(kycSchemaSorted($checks))->toBe(kycSchemaSorted([
        'kyc_submissions_reference_rule', 'kyc_submissions_kind_rule', 'kyc_submissions_user_type_rule', 'kyc_submissions_form_key_rule',
        'kyc_submission_requirements_label_length', 'kyc_submission_requirements_phone_rule',
        'kyc_submission_identities_key_rule', 'kyc_submission_identities_mask_length', 'kyc_submission_identities_hash_length',
        'kyc_reviews_decision_rule', 'kyc_reviews_reason_rule', 'kyc_reviews_form_key_rule',
        'kyc_documents_mime_rule', 'kyc_documents_size_rule', 'kyc_documents_storage_rule', 'kyc_documents_requirement_rule',
        'kyc_documents_sha256_rule',
        'kyc_audit_events_event_rule', 'metadata',
    ]));
});

it('refuses a row that breaks a CHECK rule, even though the model guards are bypassed', function (string $constraint, Closure $write) {
    expect(fn () => $write(kycSchemaBase()))->toThrow(QueryException::class, "CONSTRAINT `{$constraint}` failed");
})->with([
    'a reference without the KYC- prefix' => ['kyc_submissions_reference_rule', fn (array $b) => DB::table('kyc_submissions')
        ->insert(kycSchemaSubmissionRow($b['customer'], ['reference' => 'PUR-'.strtoupper((string) Str::ulid())]))],
    'a kind that is not allowed' => ['kyc_submissions_kind_rule', fn (array $b) => DB::table('kyc_submissions')
        ->insert(kycSchemaSubmissionRow($b['customer'], ['kind' => 'refund']))],
    'a customer type that is not allowed' => ['kyc_submissions_user_type_rule', fn (array $b) => DB::table('kyc_submissions')
        ->insert(kycSchemaSubmissionRow($b['customer'], ['user_type' => 'admin']))],
    'a form key that is not 36 characters' => ['kyc_submissions_form_key_rule', fn (array $b) => DB::table('kyc_submissions')
        ->insert(kycSchemaSubmissionRow($b['customer'], ['form_key' => 'not-a-uuid']))],
    'a requirement label of one character' => ['kyc_submission_requirements_label_length', fn (array $b) => DB::table('kyc_submission_requirements')
        ->insert(kycSchemaSnapshotRow($b['submission'], 'phone', ['label' => 'A']))],
    'a phone requirement with no fingerprint' => ['kyc_submission_requirements_phone_rule', fn (array $b) => DB::table('kyc_submission_requirements')
        ->insert(kycSchemaSnapshotRow($b['submission'], 'phone', ['phone_fingerprint' => null]))],
    'an identity that is not a BVN or NIN' => ['kyc_submission_identities_key_rule', fn (array $b) => DB::table('kyc_submission_identities')
        ->insert(kycSchemaIdentityRow(kycSchemaSnapshotted($b['customer'], 'phone'), 'phone'))],
    'a mask that is not 11 characters' => ['kyc_submission_identities_mask_length', fn (array $b) => DB::table('kyc_submission_identities')
        ->insert(kycSchemaIdentityRow(kycSchemaSnapshotted($b['customer'], 'bvn'), 'bvn', ['masked_value' => '•••••••222']))],
    'a lookup hash that is not 64 characters' => ['kyc_submission_identities_hash_length', fn (array $b) => DB::table('kyc_submission_identities')
        ->insert(kycSchemaIdentityRow(kycSchemaSnapshotted($b['customer'], 'bvn'), 'bvn', ['lookup_hash' => str_repeat('a', 63)]))],
    'a decision that is not allowed' => ['kyc_reviews_decision_rule', fn (array $b) => DB::table('kyc_reviews')
        ->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id, ['decision' => 'bogus']))],
    'a rejection with no reason' => ['kyc_reviews_reason_rule', fn (array $b) => DB::table('kyc_reviews')
        ->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id, ['reason' => null]))],
    'a decision form key that is not 36 characters' => ['kyc_reviews_form_key_rule', fn (array $b) => DB::table('kyc_reviews')
        ->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id, ['form_key' => 'short']))],
    'a document type that is not allowed' => ['kyc_documents_mime_rule', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow($b['submission'], ['mime' => 'image/gif']))],
    'an empty document' => ['kyc_documents_size_rule', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow($b['submission'], ['size_bytes' => 0]))],
    'a storage name of the wrong length' => ['kyc_documents_storage_rule', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow($b['submission'], ['storage_name' => 'SHORT.pdf']))],
    'a document for a requirement that is not the document' => ['kyc_documents_requirement_rule', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow($b['submission'], ['requirement_key' => 'bvn']))],
    'a checksum that is not 64 characters' => ['kyc_documents_sha256_rule', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow($b['submission'], ['sha256' => str_repeat('b', 63)]))],
    'an audit event outside the trail' => ['kyc_audit_events_event_rule', fn (array $b) => DB::table('kyc_audit_events')
        ->insert(kycSchemaAuditRow($b['customer']->id, $b['submission'], ['event' => 'submission.deleted']))],
    // MariaDB names the JSON-validity check of a json column by its table and column in the error, and by the column alone in information_schema.
    'metadata that is not JSON' => ['kyc_audit_events.metadata', fn (array $b) => DB::table('kyc_audit_events')
        ->insert(kycSchemaAuditRow($b['customer']->id, $b['submission'], ['metadata' => 'not json']))],
]);

it('refuses a link to a row of another submission, customer or requirement, even though the model guards are bypassed', function (string $constraint, Closure $write) {
    expect(fn () => $write(kycSchemaBase()))->toThrow(QueryException::class, "CONSTRAINT `{$constraint}` FOREIGN KEY");
})->with([
    'an open slot for a submission of another customer' => ['kyc_open_submissions_owner_fk', fn (array $b) => DB::table('kyc_open_submissions')
        ->insert(['user_id' => User::factory()->create()->id, 'kyc_submission_id' => kycSchemaRawSubmission($b['customer']), 'created_at' => now()])],
    'a snapshot that names another requirement' => ['kyc_submission_requirements_definition_fk', fn (array $b) => DB::table('kyc_submission_requirements')
        ->insert(kycSchemaSnapshotRow(kycSchemaRawSubmission($b['customer']), 'bvn', ['kyc_requirement_id' => KycRequirement::where('key', 'nin')->value('id')]))],
    'an identity for a requirement the submission did not take' => ['kyc_submission_identities_snapshot_fk', fn (array $b) => DB::table('kyc_submission_identities')
        ->insert(kycSchemaIdentityRow(kycSchemaRawSubmission($b['customer']), 'bvn'))],
    'a document for a requirement the submission did not take' => ['kyc_documents_snapshot_fk', fn (array $b) => DB::table('kyc_documents')
        ->insert(kycSchemaDocumentRow(kycSchemaRawSubmission($b['customer'])))],
    'an audit event that names another customer' => ['kyc_audit_events_owner_fk', fn (array $b) => DB::table('kyc_audit_events')
        ->insert(kycSchemaAuditRow(User::factory()->create()->id, kycSchemaRawSubmission($b['customer'])))],
    'an audit event that names a document of another submission' => ['kyc_audit_events_document_fk', fn (array $b) => DB::table('kyc_audit_events')
        ->insert(kycSchemaAuditRow($b['customer']->id, kycSchemaRawSubmission($b['customer']),
            ['event' => 'document.registered', 'kyc_document_id' => $b['document']]))],
]);

it('keeps one open slot per customer and one decision per submission, and a decision releases the slot, on the real schema', function () {
    $b = kycSchemaBase();

    expect(fn () => DB::table('kyc_open_submissions')->insert(['user_id' => $b['customer']->id,
        'kyc_submission_id' => kycSchemaRawSubmission($b['customer']), 'created_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('kyc_reviews')->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id));
    expect(fn () => DB::table('kyc_reviews')->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id)))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('kyc_reviews')->count())->toBe(1)
        ->and(DB::table('kyc_open_submissions')->where('user_id', $b['customer']->id)->count())->toBe(0);
});

it('accepts the smallest and largest values the rules allow', function () {
    $b = kycSchemaBase();
    DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($b['submission'], 'phone', ['label' => 'AB']));
    DB::table('kyc_documents')->insert(kycSchemaDocumentRow($b['submission'], ['size_bytes' => 5_242_880]));
    DB::table('kyc_documents')->insert(kycSchemaDocumentRow($b['submission'], ['size_bytes' => 1, 'mime' => 'image/png']));
    DB::table('kyc_reviews')->insert(kycSchemaReviewRow(kycSchemaBackedBvnSubmission(), $b['staff']->id, ['decision' => 'approved', 'reason' => null]));
    DB::table('kyc_reviews')->insert(kycSchemaReviewRow(kycSchemaRawSubmission($b['customer']), $b['staff']->id,
        ['reason' => str_repeat('r', 10)]));
    DB::table('kyc_reviews')->insert(kycSchemaReviewRow(kycSchemaRawSubmission($b['customer']), $b['staff']->id,
        ['reason' => str_repeat('r', 500)]));

    expect(DB::table('kyc_submission_requirements')->where('requirement_key', 'phone')->value('label'))->toBe('AB')
        ->and(DB::table('kyc_documents')->count())->toBe(3)
        ->and(DB::table('kyc_reviews')->count())->toBe(3);
});

it('creates an update and a delete trigger on each history table, on the real MariaDB', function () {
    $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TRIGGER_NAME', 'like', 'kyc%')->pluck('TRIGGER_NAME')->all();
    $expected = collect(['kyc_requirement_changes', 'kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities',
        'kyc_reviews', 'kyc_documents', 'kyc_audit_events'])
        ->flatMap(fn (string $table) => ["{$table}_no_update", "{$table}_no_delete"])
        ->merge(['kyc_submission_requirements_no_insert_after_decision', 'kyc_submission_identities_no_insert_after_decision',
            'kyc_documents_no_insert_after_decision', 'kyc_open_submissions_no_insert_after_decision', 'kyc_reviews_release_open_slot',
            'kyc_reviews_approval_gate'])
        ->all();

    expect(kycSchemaSorted($triggers))->toBe(kycSchemaSorted($expected))
        ->and(count($triggers))->toBe(20);
});

it('refuses every change and removal of a history row on the real MariaDB, past the model guards', function () {
    $b = kycSchemaBase();
    kycDecide(KycSubmission::findOrFail($b['submission']), $b['staff'], KycStatus::Rejected, 'The document is not the one requested.');

    foreach (['kyc_requirement_changes', 'kyc_submissions', 'kyc_submission_requirements', 'kyc_submission_identities', 'kyc_reviews',
        'kyc_documents', 'kyc_audit_events'] as $table) {
        $before = (array) DB::table($table)->orderBy('id')->first();
        expect($before)->not->toBe([]);

        expect(fn () => DB::table($table)->update(['created_at' => now()->addSecond()]))->toThrow(QueryException::class, 'append-only');
        expect(fn () => DB::table($table)->delete())->toThrow(QueryException::class, 'append-only');
        expect((array) DB::table($table)->orderBy('id')->first())->toEqual($before);
    }
});

it('refuses a requirement, identity value or document written with raw SQL after a decision, on the real MariaDB', function () {
    $b = kycSchemaBase();
    kycDecide(KycSubmission::findOrFail($b['submission']), $b['staff'], KycStatus::Rejected, 'The document is not the one requested.');
    $counts = fn () => [DB::table('kyc_submission_requirements')->count(), DB::table('kyc_submission_identities')->count(),
        DB::table('kyc_documents')->count()];
    $before = $counts();

    expect(fn () => DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($b['submission'], 'phone')))
        ->toThrow(QueryException::class, 'A KYC submission takes no new requirement once it has a decision.');
    expect(fn () => DB::table('kyc_submission_identities')->insert(kycSchemaIdentityRow($b['submission'], 'nin')))
        ->toThrow(QueryException::class, 'A KYC submission takes no new identity value once it has a decision.');
    expect(fn () => DB::table('kyc_documents')->insert(kycSchemaDocumentRow($b['submission'])))
        ->toThrow(QueryException::class, 'A KYC submission takes no new document once it has a decision.');
    expect($counts())->toBe($before);
});

it('releases the open slot when a review is written with raw SQL, and refuses a raw open slot for a decided submission, on the real MariaDB', function () {
    $b = kycSchemaBase();
    expect(DB::table('kyc_open_submissions')->where('user_id', $b['customer']->id)->count())->toBe(1);

    DB::table('kyc_reviews')->insert(kycSchemaReviewRow($b['submission'], $b['staff']->id));

    expect(DB::table('kyc_open_submissions')->count())->toBe(0);
    expect(fn () => DB::table('kyc_open_submissions')->insert(['user_id' => $b['customer']->id,
        'kyc_submission_id' => $b['submission'], 'created_at' => now()]))
        ->toThrow(QueryException::class, 'A decided KYC submission cannot hold the open slot.');
});

it('refuses a raw approval the submission cannot back, on the real MariaDB, and writes no review', function (array $requirements, array $identities) {
    $submission = kycSchemaRawSubmission(kycCustomer());
    foreach ($requirements as $key) {
        DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, $key));
    }
    foreach ($identities as $key) {
        DB::table('kyc_submission_identities')->insert(kycSchemaIdentityRow($submission, $key));
    }
    $staff = kycStaff(SystemRole::Manager);

    expect(fn () => DB::table('kyc_reviews')->insert(kycSchemaReviewRow($submission, $staff->id, ['decision' => 'approved', 'reason' => null])))
        ->toThrow(QueryException::class, 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.')
        ->and(DB::table('kyc_reviews')->count())->toBe(0);
})->with([
    'no requirement at all' => [[], []],
    'BVN with no identity value' => [['bvn'], []],
    'NIN with no identity value' => [['nin'], []],
    'phone verification' => [['phone'], []],
    'provider document' => [['document'], []],
    'BVN with its identity value and a document' => [['bvn', 'document'], ['bvn']],
]);

it('accepts a raw approval when every requirement is a BVN or a NIN and each has an identity value, on the real MariaDB', function (array $requirements) {
    $submission = kycSchemaRawSubmission(kycCustomer());
    foreach ($requirements as $key) {
        DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, $key));
        DB::table('kyc_submission_identities')->insert(kycSchemaIdentityRow($submission, $key));
    }
    $staff = kycStaff(SystemRole::Manager);

    DB::table('kyc_reviews')->insert(kycSchemaReviewRow($submission, $staff->id, ['decision' => 'approved', 'reason' => null]));

    expect(DB::table('kyc_reviews')->where('kyc_submission_id', $submission)->value('decision'))->toBe('approved');
})->with([
    'BVN' => [['bvn']],
    'NIN' => [['nin']],
    'BVN and NIN' => [['bvn', 'nin']],
]);

it('does not refuse a raw rejection or request for more information on the real MariaDB, whatever the submission asked for', function (string $decision, array $requirements) {
    $submission = kycSchemaRawSubmission(kycCustomer());
    foreach ($requirements as $key) {
        DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, $key));
    }
    $staff = kycStaff(SystemRole::Manager);

    DB::table('kyc_reviews')->insert(kycSchemaReviewRow($submission, $staff->id, ['decision' => $decision]));

    expect(DB::table('kyc_reviews')->where('kyc_submission_id', $submission)->value('decision'))->toBe($decision);
})->with([
    'rejected, no requirement' => ['rejected', []],
    'more information requested, phone verification' => ['more_info_requested', ['phone']],
]);

/** A second session on the same database, at the default isolation level, so a test can hold a transaction open while another one writes. */
function kycSchemaSecondSession(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
    $connection = DB::connection($name);
    $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    return $connection;
}

/** A submission that holds a BVN with its identity value, so an approval would pass unless a later requirement is added. Returns its id. */
function kycSchemaBackedBvnSubmission(): int
{
    $submission = kycSchemaRawSubmission(kycCustomer());
    DB::table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, 'bvn'));
    DB::table('kyc_submission_identities')->insert(kycSchemaIdentityRow($submission, 'bvn'));

    return $submission;
}

it('refuses a raw approval when a phone requirement that another session committed after the approval took its snapshot', function () {
    $submission = kycSchemaBackedBvnSubmission();
    $staff = kycStaff(SystemRole::Manager);
    $approver = kycSchemaSecondSession('kyc_approver');
    $writer = kycSchemaSecondSession('kyc_writer');

    $approver->beginTransaction();
    try {
        // A read first fixes what this session sees, so the requirement below commits after the approval's snapshot was taken.
        $approver->select('SELECT id FROM kyc_submissions WHERE id = ?', [$submission]);
        $writer->table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, 'phone'));

        expect(fn () => $approver->table('kyc_reviews')->insert(kycSchemaReviewRow($submission, $staff->id, ['decision' => 'approved', 'reason' => null])))
            ->toThrow(QueryException::class, 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.');
    } finally {
        $approver->rollBack();
    }

    expect(DB::table('kyc_reviews')->count())->toBe(0);
});

/** The environment a raw approval worker runs with: the MariaDB concurrency database, the testing app and the array cache. */
function kycSchemaWorkerEnv(): array
{
    return ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nadabo_concurrency_test', 'CACHE_STORE' => 'array'];
}

/**
 * Polls until another session is inside an approval: running the gate's locking read, or the review insert it guards. The approval's
 * trigger shows its own statement in the process list, so both are matched. Fails when no approval gets there within half a minute.
 * The list shows another session's statement only to an account with the PROCESS privilege, so without that privilege the poll fails
 * after half a minute rather than passing.
 */
function kycSchemaWaitForApproval(): void
{
    $deadline = microtime(true) + 30;
    while (DB::select('SELECT ID FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND (INFO LIKE ? OR INFO LIKE ?)',
        ['%COUNT(*) INTO locked_rows%', 'insert into `kyc_reviews`%']) === []) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The approval did not reach its locks in time.');
        }
        usleep(10_000);
    }
}

it('refuses a raw approval still running at its review insert when another session commits a phone requirement', function () {
    $submission = kycSchemaBackedBvnSubmission();
    $staff = kycStaff(SystemRole::Manager);
    $writer = kycSchemaSecondSession('kyc_writer');

    $writer->beginTransaction();
    try {
        $writer->table('kyc_submission_requirements')->insert(kycSchemaSnapshotRow($submission, 'phone'));
        $approval = Process::path(base_path())->env(kycSchemaWorkerEnv())->timeout(120)
            ->start([PHP_BINARY, base_path('tests/Concurrency/kyc_raw_approval_worker.php'), (string) $submission, (string) $staff->id]);

        kycSchemaWaitForApproval();
        // The poll saw the approval reach its review insert while the phone requirement was uncommitted. The approval is still running
        // 300 ms later, as a lock wait looks; the test does not time the wait itself. The refusal below needs the committed row, so a read
        // that did not wait would finish and record the approval, which fails this test.
        usleep(300_000);
        expect($approval->running())->toBeTrue();
    } finally {
        $writer->commit();
    }

    $result = json_decode(trim($approval->wait()->output()), true, 512, JSON_THROW_ON_ERROR);
    expect($result)->toBe(['result' => 'refused', 'message' => 'A KYC approval needs a submission that asked only for a BVN or a NIN, with each identity value written.'])
        ->and(DB::table('kyc_reviews')->count())->toBe(0);
});
