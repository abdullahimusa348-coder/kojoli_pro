<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP2a: what a KYC submission asked for, and the BVN or NIN it carried.
 * - kyc_submission_requirements: one row per requirement that applied when the submission was sent: its key, type and name at
 *   that moment, and for a phone requirement a keyed fingerprint of the phone number (never the number, and never a verification).
 *   Its key and type must match the requirement's own row, through a composite key.
 * - kyc_submission_identities: at most one BVN and one NIN per submission, each only for a requirement the submission asked for
 *   (a composite key). Only the encrypted value, the mask and a keyed lookup hash are stored.
 * Both are written with their submission and never changed. Rolling back is refused while either holds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_submission_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->restrictOnDelete();
            $table->unsignedBigInteger('kyc_requirement_id');
            $table->string('requirement_key', 40);
            $table->string('requirement_type', 20);
            $table->string('label', 120);
            $table->char('phone_fingerprint', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['kyc_submission_id', 'requirement_key'], 'kyc_submission_requirements_submission_key_unique');
            $table->foreign(['kyc_requirement_id', 'requirement_key', 'requirement_type'], 'kyc_submission_requirements_definition_fk')
                ->references(['id', 'key', 'type'])->on('kyc_requirements')->restrictOnDelete();
        });

        Schema::create('kyc_submission_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->restrictOnDelete();
            $table->string('requirement_key', 40);
            $table->text('ciphertext');
            $table->string('masked_value', 20);
            $table->char('lookup_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['kyc_submission_id', 'requirement_key'], 'kyc_submission_identities_submission_key_unique');
            $table->foreign(['kyc_submission_id', 'requirement_key'], 'kyc_submission_identities_snapshot_fk')
                ->references(['kyc_submission_id', 'requirement_key'])->on('kyc_submission_requirements')->restrictOnDelete();
            $table->index(['requirement_key', 'lookup_hash'], 'kyc_submission_identities_lookup_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                ['kyc_submission_requirements', 'kyc_submission_requirements_label_length', 'CHAR_LENGTH(label) BETWEEN 2 AND 120'],
                ['kyc_submission_requirements', 'kyc_submission_requirements_phone_rule',
                    "(requirement_key = 'phone' AND phone_fingerprint IS NOT NULL) OR (requirement_key <> 'phone' AND phone_fingerprint IS NULL)"],
                ['kyc_submission_identities', 'kyc_submission_identities_key_rule', "requirement_key IN ('bvn', 'nin')"],
                ['kyc_submission_identities', 'kyc_submission_identities_mask_length', 'CHAR_LENGTH(masked_value) = 11'],
                ['kyc_submission_identities', 'kyc_submission_identities_hash_length', 'CHAR_LENGTH(lookup_hash) = 64'],
            ] as [$table, $name, $rule]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        foreach (['kyc_submission_identities', 'kyc_submission_requirements'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
            }
        }

        Schema::dropIfExists('kyc_submission_identities');
        Schema::dropIfExists('kyc_submission_requirements');
    }
};
