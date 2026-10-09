<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only route history (priority, code, active flag, cost): never edited or deleted.
        Schema::create('plan_provider_route_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_provider_route_id')->constrained('plan_provider_routes')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->string('event', 20);
            $table->unsignedSmallInteger('old_priority')->nullable();
            $table->unsignedSmallInteger('new_priority');
            $table->string('old_provider_plan_code', 100)->nullable();
            $table->string('new_provider_plan_code', 100)->nullable();
            $table->boolean('old_is_active')->nullable();
            $table->boolean('new_is_active');
            $table->string('old_cost_type', 10)->nullable();
            $table->string('new_cost_type', 10)->nullable();
            $table->unsignedBigInteger('old_cost_kobo')->nullable();
            $table->unsignedBigInteger('new_cost_kobo')->nullable();
            $table->unsignedSmallInteger('old_cost_discount_bps')->nullable();
            $table->unsignedSmallInteger('new_cost_discount_bps')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_provider_route_changes');
    }
};
