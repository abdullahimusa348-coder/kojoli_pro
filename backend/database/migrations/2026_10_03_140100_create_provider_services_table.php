<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provider capabilities: which catalog services a provider supports.
        Schema::create('provider_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_plan_code')->default(true);
            $table->string('provider_service_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_services');
    }
};
