<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only price history: written once per change, never edited or deleted.
        Schema::create('plan_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_price_id')->constrained('plan_prices')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('user_type', 20);
            $table->unsignedBigInteger('old_price_kobo')->nullable();
            $table->unsignedBigInteger('new_price_kobo')->nullable();
            $table->unsignedSmallInteger('old_discount_bps')->nullable();
            $table->unsignedSmallInteger('new_discount_bps')->nullable();
            $table->unsignedBigInteger('old_fee_kobo')->nullable();
            $table->unsignedBigInteger('new_fee_kobo')->nullable();
            $table->boolean('old_is_active')->nullable();
            $table->boolean('new_is_active');
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_price_changes');
    }
};
