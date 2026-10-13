<?php

use App\Models\KycRequirement;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/Kyc/helpers.php';

/*
 * Phase 13 CP2a schema on SQLite: the uniqueness, the composite keys and the deletion rules, checked by writing the rows directly, so
 * the database alone has to refuse what it must. SQLite cannot check the CHECK rules (the MariaDB suite does), and the model rules are
 * tested with the models. Synthetic identity numbers only.
 */

beforeEach(fn () => kycSeed());

/** A bare submission row for the customer, written directly: no snapshot, identity value or review. Returns its id. */
function rawSubmission(User $customer, string $kind = 'initial'): int
{
    return DB::table('kyc_submissions')->insertGetId([
        'reference' => 'KYC-'.strtoupper((string) Str::ulid()),
        'user_id' => $customer->id,
        'kind' => $kind,
        'user_type' => $customer->user_type->value,
        'form_key' => (string) Str::uuid(),
        'submitted_at' => now(),
        'created_at' => now(),
    ]);
}

/** A bare requirement snapshot row, written directly. */
function rawSnapshot(int $submissionId, string $key, ?string $fingerprint = null): void
{
    $requirement = KycRequirement::where('key', $key)->sole();
    DB::table('kyc_submission_requirements')->insert([
        'kyc_submission_id' => $submissionId,
        'kyc_requirement_id' => $requirement->id,
        'requirement_key' => $key,
        'requirement_type' => $requirement->type,
        'label' => $requirement->label,
        'phone_fingerprint' => $fingerprint,
        'created_at' => now(),
    ]);
}

it('keeps one open slot per customer, however the rows are written', function () {
    $customer = User::factory()->create();
    $first = rawSubmission($customer);
    $second = rawSubmission($customer);
    DB::table('kyc_open_submissions')->insert(['user_id' => $customer->id, 'kyc_submission_id' => $first, 'created_at' => now()]);

    expect(fn () => DB::table('kyc_open_submissions')->insert(['user_id' => $customer->id, 'kyc_submission_id' => $second, 'created_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(DB::table('kyc_open_submissions')->count())->toBe(1);
});

it('ties an open slot to a submission of the same customer', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $submission = rawSubmission($other);

    expect(fn () => DB::table('kyc_open_submissions')->insert(['user_id' => $owner->id, 'kyc_submission_id' => $submission, 'created_at' => now()]))
        ->toThrow(QueryException::class);
});

it('keeps one decision per submission', function () {
    $customer = User::factory()->create();
    $submission = rawSubmission($customer);
    $staff = kycStaff(SystemRole::Manager);
    $review = fn (string $key) => [
        'kyc_submission_id' => $submission, 'decision' => 'rejected', 'reason' => 'The document is not the one requested.', 'reviewed_by' => $staff->id,
        'decided_at' => now(), 'form_key' => $key, 'created_at' => now(),
    ];
    DB::table('kyc_reviews')->insert($review((string) Str::uuid()));

    expect(fn () => DB::table('kyc_reviews')->insert($review((string) Str::uuid())))->toThrow(UniqueConstraintViolationException::class);
    expect(DB::table('kyc_reviews')->count())->toBe(1);
});

it('keeps one snapshot row per requirement per submission', function () {
    kycEnable('bvn');
    $submission = rawSubmission(User::factory()->create());
    rawSnapshot($submission, 'bvn');

    expect(fn () => rawSnapshot($submission, 'bvn'))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps a requirement snapshot to the key and type of its own requirement', function () {
    kycEnable('bvn');
    $submission = rawSubmission(User::factory()->create());
    $bvn = KycRequirement::where('key', 'bvn')->sole();

    expect(fn () => DB::table('kyc_submission_requirements')->insert([
        'kyc_submission_id' => $submission, 'kyc_requirement_id' => $bvn->id, 'requirement_key' => 'nin',
        'requirement_type' => 'bvn', 'label' => 'BVN', 'phone_fingerprint' => null, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('attaches an identity value only to a requirement its submission asked for', function () {
    $submission = rawSubmission(User::factory()->create());

    expect(fn () => DB::table('kyc_submission_identities')->insert([
        'kyc_submission_id' => $submission, 'requirement_key' => 'bvn', 'ciphertext' => 'not-read-by-this-test',
        'masked_value' => '•••••••2222', 'lookup_hash' => str_repeat('a', 64), 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('attaches a document only to a document requirement its submission asked for', function () {
    $submission = rawSubmission(User::factory()->create());

    expect(fn () => DB::table('kyc_documents')->insert([
        'kyc_submission_id' => $submission, 'requirement_key' => 'document', 'storage_name' => strtoupper((string) Str::ulid()).'.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 1024, 'sha256' => str_repeat('b', 64), 'uploaded_at' => now(), 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps each stored document under a unique storage name', function () {
    kycEnable('document');
    $customer = User::factory()->create();
    $submission = rawSubmission($customer);
    rawSnapshot($submission, 'document');
    $storage = strtoupper((string) Str::ulid()).'.pdf';
    $document = fn () => [
        'kyc_submission_id' => $submission, 'requirement_key' => 'document', 'storage_name' => $storage,
        'mime' => 'application/pdf', 'size_bytes' => 1024, 'sha256' => str_repeat('b', 64), 'uploaded_at' => now(), 'created_at' => now(),
    ];
    DB::table('kyc_documents')->insert($document());

    expect(fn () => DB::table('kyc_documents')->insert($document()))->toThrow(UniqueConstraintViolationException::class);
});

it('refers an audit event only to the submission of its own customer', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $submission = rawSubmission($other);

    expect(fn () => DB::table('kyc_audit_events')->insert([
        'event' => 'submission.opened', 'user_id' => $owner->id, 'kyc_submission_id' => $submission, 'kyc_document_id' => null,
        'system_user_id' => null, 'metadata' => json_encode(['kind' => 'initial', 'requirement_count' => 0]), 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a customer who has a submission, so a submission never loses its customer', function () {
    $customer = User::factory()->create();
    rawSubmission($customer);

    expect(fn () => DB::table('users')->where('id', $customer->id)->delete())->toThrow(QueryException::class);
    expect(User::whereKey($customer->id)->exists())->toBeTrue();
});

it('refuses to delete a staff member who decided a submission, so a decision keeps its reviewer', function () {
    $customer = User::factory()->create();
    $submission = rawSubmission($customer);
    $staff = kycStaff(SystemRole::Manager);
    DB::table('kyc_reviews')->insert([
        'kyc_submission_id' => $submission, 'decision' => 'rejected', 'reason' => 'The document is not the one requested.', 'reviewed_by' => $staff->id,
        'decided_at' => now(), 'form_key' => (string) Str::uuid(), 'created_at' => now(),
    ]);

    expect(fn () => DB::table('system_users')->where('id', $staff->id)->delete())->toThrow(QueryException::class);
});
