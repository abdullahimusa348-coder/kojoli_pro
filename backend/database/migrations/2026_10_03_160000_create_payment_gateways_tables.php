<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-created payment gateway records. The driver names an adapter
        // that exists in code; endpoints come from the adapter, never from here.
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 100)->unique();
            $table->string('driver', 50);
            $table->string('status', 20)->default('inactive')->index();
            $table->string('mode', 10)->default('sandbox');
            $table->unsignedSmallInteger('priority')->unique();
            $table->boolean('wallet_funding')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Encrypted, write-only credentials per gateway and mode (sandbox and live keys are separate).
        Schema::create('payment_gateway_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->restrictOnDelete();
            $table->string('mode', 10);
            $table->string('key', 50);
            $table->text('value');
            $table->string('hint', 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payment_gateway_id', 'mode', 'key']);
        });

        // Append-only credential events; never values or hints.
        Schema::create('payment_gateway_credential_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->restrictOnDelete();
            $table->string('mode', 10);
            $table->string('key', 50);
            $table->string('action', 10);
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_gateway_id', 'created_at'], 'pg_credential_changes_gateway_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_credential_changes');
        Schema::dropIfExists('payment_gateway_credentials');
        Schema::dropIfExists('payment_gateways');
    }
};
