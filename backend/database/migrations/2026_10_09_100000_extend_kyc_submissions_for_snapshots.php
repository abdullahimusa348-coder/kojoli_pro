<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP2a: the KYC submission schema, first part. kyc_submissions gains the kind of submission (initial or correction),
 * the customer type it was sent under and a one-time form key, so a repeated form can never create a second submission.
 * The table is rebuilt rather than altered: SQLite cannot add a NOT NULL column to a table in place. It holds no rows (CP1
 * writes none), and the migration refuses before any change while it does. kyc_requirements gains a unique (id, key, type)
 * index, which the submission snapshot references, so a snapshot can only name a requirement with its own key and type.
 * Rolling back is refused while kyc_submissions holds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kyc_submissions') && DB::table('kyc_submissions')->exists()) {
            throw new RuntimeException('Refusing to extend KYC submissions: kyc_submissions holds records, which would be rebuilt. Nothing was changed.');
        }

        Schema::dropIfExists('kyc_submissions');
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('user_type', 20);
            $table->char('form_key', 36)->unique();
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'id'], 'kyc_submissions_owner_unique');
        });

        Schema::table('kyc_requirements', function (Blueprint $table) {
            $table->unique(['id', 'key', 'type'], 'kyc_requirements_definition_unique');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                ['kyc_submissions_reference_rule', "CHAR_LENGTH(reference) = 30 AND reference LIKE 'KYC-%'"],
                ['kyc_submissions_kind_rule', "kind IN ('initial', 'correction')"],
                ['kyc_submissions_user_type_rule', "user_type IN ('subscriber', 'vendor', 'affiliate', 'api_user')"],
                ['kyc_submissions_form_key_rule', 'CHAR_LENGTH(form_key) = 36'],
            ] as [$name, $rule]) {
                DB::statement("ALTER TABLE kyc_submissions ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kyc_submissions') && DB::table('kyc_submissions')->exists()) {
            throw new RuntimeException('Refusing to roll back: kyc_submissions holds KYC configuration or records, which would be lost. Nothing was changed.');
        }

        Schema::table('kyc_requirements', function (Blueprint $table) {
            $table->dropUnique('kyc_requirements_definition_unique');
        });
        Schema::dropIfExists('kyc_submissions');
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE kyc_submissions ADD CONSTRAINT kyc_submissions_reference_rule CHECK (CHAR_LENGTH(reference) = 30 AND reference LIKE 'KYC-%')");
        }
    }
};
