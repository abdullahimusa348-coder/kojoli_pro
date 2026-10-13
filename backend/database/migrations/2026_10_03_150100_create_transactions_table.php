<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer-facing record of one financial operation (Phase 8: admin
        // adjustments). Owns its wallet ledger entries. Purchases, payments and
        // provider attempts get their own tables in later phases.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 12)->index();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('description', 255);
            $table->foreignId('created_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
