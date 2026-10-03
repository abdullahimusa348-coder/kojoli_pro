<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Application settings managed from the admin area (App\Services\Settings\SettingsStore).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group', 50)->index();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            // Readable outside the admin area later (e.g. a public config endpoint). Never true for secrets.
            $table->boolean('is_public')->default(false);
            // Stored encrypted with APP_KEY and never shown back in the admin UI (for future API keys, passwords).
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
