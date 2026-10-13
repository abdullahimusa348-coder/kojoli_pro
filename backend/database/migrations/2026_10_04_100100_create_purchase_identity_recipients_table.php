<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 CP1: the NIN or BVN of a NIN/BVN purchase, one row per purchase
 * (unique purchase_id), written in the same transaction as the purchase and
 * its debit, never updated or deleted. Phone purchases have no row.
 * - encrypted_value: the 11 digits, encrypted with the app key;
 * - masked_value: the only displayable form (seven dots and the last four digits);
 * - lookup_hash: keyed hash over type and number, for exact-match search;
 * - keyed_fingerprint: keyed hash over plan, type, number and amount, for
 *   repeated requests;
 * - consented_at: when the customer consented.
 * The plain number is never stored. Rolling back is refused while any row
 * exists, before any schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_identity_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->restrictOnDelete();
            $table->text('encrypted_value');
            $table->string('masked_value', 20);
            $table->char('lookup_hash', 64)->index();
            $table->char('keyed_fingerprint', 64);
            $table->timestamp('consented_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_identity_recipients') && DB::table('purchase_identity_recipients')->exists()) {
            throw new RuntimeException('Refusing to roll back: NIN/BVN purchases have identity recipients, which would be lost. Nothing was changed.');
        }

        Schema::dropIfExists('purchase_identity_recipients');
    }
};
