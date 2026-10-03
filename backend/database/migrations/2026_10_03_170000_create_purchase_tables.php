<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One customer purchase of one plan (Phase 10). It holds snapshots of
        // what was sold and charged, so later catalog, price or route changes
        // never rewrite history. Money moves only through WalletService: the
        // debit and an eventual refund each link to exactly one wallet
        // transaction (both unique). (user_id, idempotency_key) is the durable
        // duplicate-request guard. Never deleted.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->string('service_name', 150);
            $table->string('product_name', 150);
            $table->string('plan_name', 150);
            $table->string('network', 10)->nullable();
            $table->string('user_type', 20);
            $table->string('amount_type', 10);
            $table->string('recipient', 20);
            $table->unsignedBigInteger('face_value_kobo')->nullable();
            $table->unsignedBigInteger('discount_kobo')->nullable();
            $table->unsignedBigInteger('fee_kobo')->nullable();
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 12)->index();
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->foreignId('debit_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->unsignedBigInteger('successful_attempt_id')->nullable()->unique();
            $table->unsignedBigInteger('cost_kobo')->nullable();
            $table->bigInteger('margin_kobo')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('check_count')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'next_check_at']);
            $table->index('created_at');
        });

        // One call to one provider route for a purchase. A route is tried at
        // most once per purchase (unique purchase + route); re-checks query the
        // same attempt and never create a new one. request_reference is our
        // unique id for the call. Route data is snapshotted. Never deleted.
        Schema::create('purchase_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->foreignId('plan_provider_route_id')->constrained('plan_provider_routes')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('providers')->restrictOnDelete();
            $table->unsignedSmallInteger('route_priority');
            $table->string('provider_plan_code', 100)->nullable();
            $table->string('cost_type', 10)->nullable();
            $table->unsignedBigInteger('cost_kobo')->nullable();
            $table->unsignedSmallInteger('cost_discount_bps')->nullable();
            $table->string('request_reference', 40)->unique();
            $table->string('provider_reference', 100)->nullable();
            $table->string('status', 16)->index();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['purchase_id', 'attempt_number']);
            $table->unique(['purchase_id', 'plan_provider_route_id']);
            $table->unique(['provider_id', 'provider_reference']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign('successful_attempt_id')->references('id')->on('purchase_attempts')->restrictOnDelete();
        });

        // Append-only purchase status history.
        Schema::create('purchase_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->string('old_status', 12)->nullable();
            $table->string('new_status', 12);
            $table->string('source', 12);
            $table->foreignId('changed_by')->nullable()->constrained('system_users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['purchase_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_status_changes');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['successful_attempt_id']);
        });
        Schema::dropIfExists('purchase_attempts');
        Schema::dropIfExists('purchases');
    }
};
