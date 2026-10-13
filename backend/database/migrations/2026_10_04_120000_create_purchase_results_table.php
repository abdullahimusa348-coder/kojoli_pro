<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 CP2: the result a provider delivered for a NIN/BVN purchase, one
 * row per purchase (unique purchase_id), written in the database transaction
 * that marks the purchase successful, never updated or deleted. Phone
 * purchases (Data, Airtime) have none.
 * - purchase_attempt_id: the delivering attempt; the compound foreign key
 *   (purchase_attempt_id, purchase_id) -> purchase_attempts (id, purchase_id)
 *   makes it an attempt of the same purchase;
 * - encrypted_fields: the result fields, encrypted with the app key, with the
 *   purchased NIN/BVN already replaced by its mask;
 * - field_count: how many fields (1 to 50; a CHECK on MariaDB/MySQL), so
 *   integrity checks never need the values.
 * Rolling back is refused while any result exists, before any schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->restrictOnDelete();
            $table->foreignId('purchase_attempt_id')->constrained('purchase_attempts')->restrictOnDelete();
            $table->text('encrypted_fields');
            $table->unsignedTinyInteger('field_count');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['purchase_attempt_id', 'purchase_id'], 'purchase_results_attempt_purchase_fk')
                ->references(['id', 'purchase_id'])->on('purchase_attempts')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE purchase_results ADD CONSTRAINT purchase_results_field_count CHECK (field_count BETWEEN 1 AND 50)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_results') && DB::table('purchase_results')->exists()) {
            throw new RuntimeException('Refusing to roll back: NIN/BVN purchases have stored results, which would be lost. Nothing was changed.');
        }

        Schema::dropIfExists('purchase_results');
    }
};
