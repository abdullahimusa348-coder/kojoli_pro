<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP2a: document metadata and the KYC audit trail. No file is uploaded, served or stored by this migration.
 * - kyc_documents: the metadata of a document a submission will carry, with a private storage name (a server-generated ULID
 *   and an extension, never the client's file name), the verified type, the size (at most 5 MiB), a SHA-256 of the content, and
 *   the document requirement it answers. A foreign key makes that requirement one of the submission's snapshot requirements. On
 *   MySQL and MariaDB, CHECK constraints also limit the type and size, the storage name and digest lengths, and the requirement key
 *   to 'document'. SQLite checks none of those CHECK rules; KycDocument checks them when it creates a row.
 * - kyc_audit_events: the append-only trail of submissions, decisions and, once they exist, document registrations and accesses.
 *   The database enforces these on every driver: the foreign keys (the customer and any named staff member must exist; a submission
 *   must belong to the event's customer, and a document to the event's submission; a key is checked only when its columns are set,
 *   so the submission column may be null in the table), and append-only changes, through the triggers in migration 2026_10_09_100400.
 *   SQLite applies foreign keys only while its foreign key setting is on, which is the default. On MySQL and MariaDB only, the
 *   database also checks the event name and that metadata is valid JSON; SQLite stores metadata as unchecked text.
 *   The database does not check the metadata keys, the values or the two counts. KycAuditEvent checks them when it creates a row: the
 *   exact keys its event kind allows, the allowed text values of each key, whole-number counts (type and sign), two ties (requirement_count
 *   must equal the number of requirement rows of the event's submission, and size_bytes must equal the stored size of the event's
 *   document), a submission on every event, and the staff and document rules of each event kind. A row written outside KycAuditEvent,
 *   such as a raw insert, skips all of these checks; the database applies only the rules described above.
 * Rolling back is refused while either table holds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->restrictOnDelete();
            $table->string('requirement_key', 40);
            $table->string('storage_name', 40)->unique('kyc_documents_storage_unique');
            $table->string('mime', 30);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('uploaded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'kyc_submission_id'], 'kyc_documents_submission_unique');
            $table->index(['kyc_submission_id', 'requirement_key'], 'kyc_documents_requirement_index');
            $table->foreign(['kyc_submission_id', 'requirement_key'], 'kyc_documents_snapshot_fk')
                ->references(['kyc_submission_id', 'requirement_key'])->on('kyc_submission_requirements')->restrictOnDelete();
        });

        Schema::create('kyc_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('kyc_submission_id')->nullable();
            $table->unsignedBigInteger('kyc_document_id')->nullable();
            $table->foreignId('system_user_id')->nullable()->constrained('system_users')->restrictOnDelete();
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id'], 'kyc_audit_events_user_index');
            $table->index(['kyc_submission_id', 'id'], 'kyc_audit_events_submission_index');
            $table->foreign(['user_id', 'kyc_submission_id'], 'kyc_audit_events_owner_fk')
                ->references(['user_id', 'id'])->on('kyc_submissions')->restrictOnDelete();
            $table->foreign(['kyc_document_id', 'kyc_submission_id'], 'kyc_audit_events_document_fk')
                ->references(['id', 'kyc_submission_id'])->on('kyc_documents')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                ['kyc_documents', 'kyc_documents_mime_rule', "mime IN ('application/pdf', 'image/jpeg', 'image/png')"],
                ['kyc_documents', 'kyc_documents_size_rule', 'size_bytes BETWEEN 1 AND 5242880'],
                ['kyc_documents', 'kyc_documents_storage_rule', 'CHAR_LENGTH(storage_name) = 30'],
                ['kyc_documents', 'kyc_documents_requirement_rule', "requirement_key = 'document'"],
                ['kyc_documents', 'kyc_documents_sha256_rule', 'CHAR_LENGTH(sha256) = 64'],
                ['kyc_audit_events', 'kyc_audit_events_event_rule',
                    "`event` IN ('submission.opened', 'review.decided', 'document.registered', 'document.accessed')"],
            ] as [$table, $name, $rule]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        foreach (['kyc_audit_events', 'kyc_documents'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
            }
        }

        Schema::dropIfExists('kyc_audit_events');
        Schema::dropIfExists('kyc_documents');
    }
};
