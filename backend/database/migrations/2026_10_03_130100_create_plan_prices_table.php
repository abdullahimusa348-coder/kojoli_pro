<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer selling prices: one row per plan and customer type, all in
        // kobo. Fixed plans use price_kobo; variable plans use discount_bps and
        // fee_kobo on the face value. No cost/provider or commission columns.
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('user_type', 20);
            $table->unsignedBigInteger('price_kobo')->nullable();
            $table->unsignedSmallInteger('discount_bps')->nullable();
            $table->unsignedBigInteger('fee_kobo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
