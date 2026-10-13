<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One wallet-funding attempt with one gateway. The payment is not money
        // movement: a verified payment links to exactly one wallet funding
        // transaction (wallet_transaction_id is unique). Never deleted.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('payment_gateway_id')->constrained('payment_gateways')->restrictOnDelete();
            $table->string('mode', 10);
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 12)->index();
            $table->string('idempotency_key', 100);
            $table->string('gateway_reference', 100)->nullable();
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('verified_amount_kobo')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['payment_gateway_id', 'gateway_reference']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
