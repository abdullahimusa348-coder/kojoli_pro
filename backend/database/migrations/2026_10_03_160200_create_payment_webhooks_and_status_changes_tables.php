<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbound webhook events (also unmatched ones). (gateway, event_key) is
        // unique: a redelivered event is never processed twice. Only a
        // sanitized, allow-listed payload is kept (no headers, no secrets),
        // and it is removed after the retention period; the outcome row stays.
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->restrictOnDelete();
            $table->string('event_key', 191);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->boolean('signature_valid');
            $table->string('outcome', 20)->index();
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['payment_gateway_id', 'event_key']);
            $table->index('received_at');
        });

        // Append-only payment status history.
        Schema::create('payment_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('old_status', 12)->nullable();
            $table->string('new_status', 12);
            $table->string('source', 12);
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_status_changes');
        Schema::dropIfExists('payment_webhooks');
    }
};
