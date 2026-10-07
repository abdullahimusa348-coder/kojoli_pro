<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 CP1: the referral and commission tables. Structure only: no row
 * is inserted here, and nothing in the app writes them yet.
 * - referral_codes: one permanent, system-generated code per customer
 *   (8 upper-case characters, unique).
 * - referrals: the permanent signup link between a referrer and the customer
 *   they referred: one referrer at most per customer, never themselves.
 * - commission_settings: the current rate (basis points, 0–9999) and cap
 *   (kobo) of each qualifying service; commission_setting_changes: the
 *   permanent history of every change, with its reason and staff member.
 * - commissions: one credited commission per successful purchase, with the
 *   base amount, rate and cap it used. Its credit transaction is on its own
 *   wallet (compound foreign key). Never changed: its status comes from its
 *   action.
 * - commission_actions: the one staff action a commission can ever have: a
 *   reversal (with its separate debit, on the same wallet) or a cancellation
 *   (with none).
 * - failed_commission_attempts: a payable commission that could not be
 *   credited: the purchase, the referrer and a fixed reason code. No money.
 * Every foreign key restricts deletes. On MariaDB/MySQL, CHECK constraints
 * repeat the model guards (SQLite, used only by tests, cannot add them).
 * Rolling back is refused while any of these tables holds a row, before any
 * schema change.
 */
return new class extends Migration
{
    /** In the order they are dropped: tables that reference others first. */
    private const TABLES = ['failed_commission_attempts', 'commission_actions', 'commissions', 'commission_setting_changes',
        'commission_settings', 'referrals', 'referral_codes'];

    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('code', 8)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['referrer_id', 'id']);
        });

        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('cap_kobo');
            $table->foreignId('updated_by')->constrained('system_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_setting_id')->constrained('commission_settings')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('old_rate_bps')->nullable();
            $table->unsignedSmallInteger('new_rate_bps');
            $table->unsignedBigInteger('old_cap_kobo')->nullable();
            $table->unsignedBigInteger('new_cap_kobo');
            $table->string('reason', 500);
            $table->foreignId('changed_by')->constrained('system_users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_id', 'id']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->restrictOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('credit_transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->unsignedBigInteger('base_amount_kobo');
            $table->unsignedSmallInteger('rate_bps');
            $table->unsignedBigInteger('cap_kobo');
            $table->unsignedBigInteger('amount_kobo');
            $table->timestamp('credited_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'wallet_id'], 'commissions_id_wallet_unique');
            $table->index(['referrer_id', 'credited_at']);
            $table->index('credited_at');
            $table->foreign(['credit_transaction_id', 'wallet_id'], 'commissions_credit_wallet_fk')
                ->references(['id', 'wallet_id'])->on('transactions')->restrictOnDelete();
        });

        Schema::create('commission_actions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('commission_id')->unique()->constrained('commissions')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('type', 12);
            $table->foreignId('reversal_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->string('reason', 500);
            $table->char('idempotency_key', 36)->unique();
            $table->foreignId('acted_by')->constrained('system_users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['commission_id', 'wallet_id'], 'commission_actions_commission_wallet_fk')
                ->references(['id', 'wallet_id'])->on('commissions')->restrictOnDelete();
            $table->foreign(['reversal_transaction_id', 'wallet_id'], 'commission_actions_reversal_wallet_fk')
                ->references(['id', 'wallet_id'])->on('transactions')->restrictOnDelete();
        });

        Schema::create('failed_commission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->restrictOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->timestamp('created_at')->useCurrent();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ([
                'referral_codes' => ['referral_codes_code_length', 'CHAR_LENGTH(code) = 8'],
                'referrals' => ['referrals_not_self', 'referrer_id <> referred_user_id'],
                'commission_settings' => ['commission_settings_rate_range', 'rate_bps <= 9999'],
                'commission_setting_changes' => ['commission_setting_changes_values', 'new_rate_bps <= 9999 AND (old_rate_bps IS NULL OR old_rate_bps <= 9999)'
                    .' AND (old_rate_bps IS NULL) = (old_cap_kobo IS NULL) AND CHAR_LENGTH(reason) BETWEEN 10 AND 500'],
                'commissions' => ['commissions_amount_rule', 'rate_bps BETWEEN 1 AND 9999 AND amount_kobo >= 1'
                    .' AND amount_kobo = LEAST((base_amount_kobo * rate_bps) DIV 10000, cap_kobo)'],
                'commission_actions' => ['commission_actions_type_rule', "((type = 'reversal' AND reversal_transaction_id IS NOT NULL)"
                    ." OR (type = 'cancellation' AND reversal_transaction_id IS NULL)) AND CHAR_LENGTH(reason) BETWEEN 10 AND 500"],
                'failed_commission_attempts' => ['failed_commission_attempts_reason_code',
                    "reason_code IN ('wallet_balance_limit', 'wallet_unavailable', 'wallet_refused', 'unexpected_error')"],
            ] as $table => [$name, $rule]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} holds referral or commission records, which would be lost. Nothing was changed.");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
