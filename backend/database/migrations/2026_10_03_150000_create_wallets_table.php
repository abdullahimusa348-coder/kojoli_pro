<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One wallet per customer per wallet type (Phase 8: "main" only).
        // balance_kobo is a cache of the ledger, updated only by WalletService
        // under a row lock in the same database transaction as each ledger
        // entry. Unsigned: a negative balance is impossible at database level.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->char('currency', 3)->default('NGN');
            $table->unsignedBigInteger('balance_kobo')->default(0);
            $table->string('status', 10)->default('active')->index();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
