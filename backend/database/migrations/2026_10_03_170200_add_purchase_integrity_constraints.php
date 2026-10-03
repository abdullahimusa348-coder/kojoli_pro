<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP1 hardening: database-level relationship integrity for purchases, in
 * addition to the model guards.
 * - The delivering attempt belongs to the same purchase.
 * - The debit and refund transactions belong to the purchase's wallet.
 * - An attempt's provider is its route's provider.
 * - An attempt's route belongs to the purchase's plan (attempts carry the
 *   purchase's plan_id, checked against both the purchase and the route).
 * The extra unique indexes on existing tables only cover columns that are
 * already unique through their primary key; they exist so composite foreign
 * keys can reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', fn (Blueprint $table) => $table->unique(['id', 'wallet_id'], 'transactions_id_wallet_unique'));
        Schema::table('plan_provider_routes', function (Blueprint $table) {
            $table->unique(['id', 'provider_id'], 'plan_provider_routes_id_provider_unique');
            $table->unique(['id', 'plan_id'], 'plan_provider_routes_id_plan_unique');
        });
        Schema::table('purchases', fn (Blueprint $table) => $table->unique(['id', 'plan_id'], 'purchases_id_plan_unique'));

        Schema::table('purchase_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_id')->nullable()->after('purchase_id');
            $table->unique(['id', 'purchase_id'], 'purchase_attempts_id_purchase_unique');
            $table->foreign(['plan_provider_route_id', 'provider_id'], 'purchase_attempts_route_provider_fk')
                ->references(['id', 'provider_id'])->on('plan_provider_routes')->restrictOnDelete();
            $table->foreign(['plan_provider_route_id', 'plan_id'], 'purchase_attempts_route_plan_fk')
                ->references(['id', 'plan_id'])->on('plan_provider_routes')->restrictOnDelete();
            $table->foreign(['purchase_id', 'plan_id'], 'purchase_attempts_purchase_plan_fk')
                ->references(['id', 'plan_id'])->on('purchases')->restrictOnDelete();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreign(['successful_attempt_id', 'id'], 'purchases_delivering_attempt_fk')
                ->references(['id', 'purchase_id'])->on('purchase_attempts')->restrictOnDelete();
            $table->foreign(['debit_transaction_id', 'wallet_id'], 'purchases_debit_wallet_fk')
                ->references(['id', 'wallet_id'])->on('transactions')->restrictOnDelete();
            $table->foreign(['refund_transaction_id', 'wallet_id'], 'purchases_refund_wallet_fk')
                ->references(['id', 'wallet_id'])->on('transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign('purchases_delivering_attempt_fk');
            $table->dropForeign('purchases_debit_wallet_fk');
            $table->dropForeign('purchases_refund_wallet_fk');
        });
        Schema::table('purchase_attempts', function (Blueprint $table) {
            $table->dropForeign('purchase_attempts_route_provider_fk');
            $table->dropForeign('purchase_attempts_route_plan_fk');
            $table->dropForeign('purchase_attempts_purchase_plan_fk');
            $table->dropUnique('purchase_attempts_id_purchase_unique');
            $table->dropColumn('plan_id');
        });
        Schema::table('purchases', fn (Blueprint $table) => $table->dropUnique('purchases_id_plan_unique'));
        Schema::table('plan_provider_routes', function (Blueprint $table) {
            $table->dropUnique('plan_provider_routes_id_provider_unique');
            $table->dropUnique('plan_provider_routes_id_plan_unique');
        });
        Schema::table('transactions', fn (Blueprint $table) => $table->dropUnique('transactions_id_wallet_unique'));
    }
};
