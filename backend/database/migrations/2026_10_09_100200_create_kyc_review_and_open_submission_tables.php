<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 CP2a: the staff decision on a submission, and the one-open-submission rule.
 * - kyc_reviews: one immutable decision per submission (a unique key), with the staff member, the time, the reason the customer
 *   sees (required for a rejection or a request for more information) and a one-time form key.
 * - kyc_open_submissions: the open slot. Its primary key is the customer, so the database allows at most one open submission per
 *   customer, whatever runs at the same time. A row is created with its submission and removed only in the transaction that
 *   records the decision. Its composite key ties it to a submission of the same customer.
 * Rolling back is refused while either table holds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->restrictOnDelete();
            $table->string('decision', 30);
            $table->string('reason', 500)->nullable();
            $table->foreignId('reviewed_by')->constrained('system_users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->char('form_key', 36);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('kyc_submission_id', 'kyc_reviews_submission_unique');
            $table->unique('form_key', 'kyc_reviews_form_key_unique');
        });

        Schema::create('kyc_open_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('kyc_submission_id')->unique('kyc_open_submissions_submission_unique');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['user_id', 'kyc_submission_id'], 'kyc_open_submissions_owner_fk')
                ->references(['user_id', 'id'])->on('kyc_submissions')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                ['kyc_reviews', 'kyc_reviews_decision_rule', "decision IN ('approved', 'rejected', 'more_info_requested')"],
                ['kyc_reviews', 'kyc_reviews_reason_rule',
                    "(decision = 'approved' AND (reason IS NULL OR CHAR_LENGTH(reason) BETWEEN 1 AND 500)) OR "
                    ."(decision IN ('rejected', 'more_info_requested') AND reason IS NOT NULL AND CHAR_LENGTH(reason) BETWEEN 10 AND 500)"],
                ['kyc_reviews', 'kyc_reviews_form_key_rule', 'CHAR_LENGTH(form_key) = 36'],
            ] as [$table, $name, $rule]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        foreach (['kyc_open_submissions', 'kyc_reviews'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds KYC configuration or records, which would be lost. Nothing was changed.");
            }
        }

        Schema::dropIfExists('kyc_open_submissions');
        Schema::dropIfExists('kyc_reviews');
    }
};
