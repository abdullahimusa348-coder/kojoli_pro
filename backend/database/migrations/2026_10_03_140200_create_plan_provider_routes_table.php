<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ordered provider routes per plan (1 = primary). The provider's own
        // plan code and the provider cost live here, never on plans or plan_prices.
        Schema::create('plan_provider_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->unsignedSmallInteger('priority');
            $table->string('provider_plan_code', 100)->nullable();
            $table->boolean('is_active')->default(true);
            // Provider cost (optional): fixed plans use cost_kobo, variable plans cost_discount_bps.
            $table->string('cost_type', 10)->nullable();
            $table->unsignedBigInteger('cost_kobo')->nullable();
            $table->unsignedSmallInteger('cost_discount_bps')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'provider_id']);
            $table->unique(['plan_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_provider_routes');
    }
};
