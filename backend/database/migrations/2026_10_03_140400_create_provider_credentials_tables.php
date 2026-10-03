<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Encrypted, write-only provider credentials (Laravel encryption, APP_KEY).
        // Only a last-four-character hint is stored in clear.
        Schema::create('provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->string('key', 30);
            $table->text('value');
            $table->string('hint', 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['provider_id', 'key']);
        });

        // Append-only credential events. Never stores values or hints.
        Schema::create('provider_credential_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->string('key', 30);
            $table->string('action', 10);
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credential_changes');
        Schema::dropIfExists('provider_credentials');
    }
};
