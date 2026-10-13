<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plans are the unit later phases attach to: customer-type prices
        // (Phase 6) and provider routes with fallbacks (Phase 7), in their own
        // tables keyed by plan id. No money or provider columns here.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('name', 150);
            // "<product-code>-<name>", generated on create and never changed afterwards.
            $table->string('code', 200)->unique();
            $table->string('amount_type', 10)->default('fixed');
            $table->string('validity_period', 10)->nullable()->index();
            $table->unsignedInteger('validity_days')->nullable();
            $table->unsignedInteger('data_volume_mb')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
