<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP1: the KYC configuration foundation. Structure only, plus the
 * database rules that repeat the model guards on MariaDB/MySQL (SQLite, used
 * only by tests, cannot add them).
 * - kyc_requirements: one row per requirement (phone, BVN, NIN, provider
 *   document). Off by default, with the customer types it applies to and the
 *   purposes it will gate later (none is defined yet, so nothing reads them).
 *   Its key and type never change, and no row is deleted.
 * - kyc_requirement_changes: the permanent history of every change, with the
 *   configuration before and after, the reason and the staff member.
 * - kyc_profiles: one KYC status per customer. CP1 writes none.
 * - kyc_submissions: a customer's submission, immutable. CP1 writes none.
 * Nothing here references the Phase 11 NIN/BVN purchase data. Rolling back is
 * refused while any of these tables holds a row, before any schema change.
 */
return new class extends Migration
{
    /** In the order they are dropped: tables that reference others first. */
    private const TABLES = ['kyc_submissions', 'kyc_profiles', 'kyc_requirement_changes', 'kyc_requirements'];

    public function up(): void
    {
        Schema::create('kyc_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('type', 20);
            $table->string('label', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('user_types');
            $table->json('purposes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('kyc_requirement_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_requirement_id')->constrained('kyc_requirements')->restrictOnDelete();
            $table->json('old_state');
            $table->json('new_state');
            $table->string('reason', 500);
            $table->foreignId('changed_by')->constrained('system_users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['kyc_requirement_id', 'id']);
        });

        Schema::create('kyc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('not_started');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                'kyc_requirements' => [
                    ['kyc_requirements_type_rule', "type IN ('phone', 'bvn', 'nin', 'document')"],
                    ['kyc_requirements_label_length', 'CHAR_LENGTH(label) BETWEEN 2 AND 120'],
                ],
                'kyc_requirement_changes' => [
                    ['kyc_requirement_changes_reason_length', 'CHAR_LENGTH(reason) BETWEEN 10 AND 500'],
                ],
                'kyc_profiles' => [
                    ['kyc_profiles_status_rule', "status IN ('not_started', 'pending_review', 'approved', 'rejected', 'more_info_requested')"],
                ],
                'kyc_submissions' => [
                    ['kyc_submissions_reference_rule', "CHAR_LENGTH(reference) = 30 AND reference LIKE 'KYC-%'"],
                ],
            ] as $table => $rules) {
                foreach ($rules as [$name, $rule]) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
