<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // External providers (system infrastructure, independent of the catalog).
        // No secrets here: credentials live encrypted in provider_credentials.
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Generated from the name on create and never changed afterwards.
            $table->string('code', 100)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('inactive')->index();
            // Identifier of a future integration adapter; no adapter exists in Phase 7.
            $table->string('driver', 50)->nullable();
            // Non-secret settings only (validated keys).
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
