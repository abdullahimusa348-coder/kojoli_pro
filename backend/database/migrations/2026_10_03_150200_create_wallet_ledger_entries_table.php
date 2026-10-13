<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only wallet ledger: every balance change is one row, never
        // updated or deleted. Corrections are compensating "reversal" rows
        // (reverses_entry_id is unique: an entry can be reversed only once).
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('balance_after_kobo');
            $table->string('entry_type', 30);
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $table->string('description', 255);
            $table->foreignId('created_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
