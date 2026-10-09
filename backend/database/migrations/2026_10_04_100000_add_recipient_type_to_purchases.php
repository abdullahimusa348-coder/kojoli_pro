<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 CP1: typed purchase recipients.
 * - recipient_type: 'phone' (Phase 10 meaning; every existing row reads it
 *   from the default, nothing is rewritten), 'nin' or 'bvn'.
 * - recipient and request_fingerprint may be NULL, for NIN/BVN purchases
 *   only: whenever recipient holds a value it is a canonical phone, and
 *   request_fingerprint is the Phase 10 SHA-256 phone fingerprint. NIN/BVN
 *   data lives only in purchase_identity_recipients.
 * - MariaDB/MySQL: a CHECK ties both columns to the type. Adding it validates
 *   every existing row. SQLite (tests only) cannot add a CHECK to an existing
 *   table; there the Purchase model guard enforces the same rule.
 * No row is inserted, updated or deleted. Rolling back is refused while any
 * purchase is not a phone purchase, before any schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('recipient_type', 12)->default('phone')->after('amount_type');
            $table->string('recipient', 20)->nullable()->change();
            $table->char('request_fingerprint', 64)->nullable()->change();
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_recipient_by_type CHECK (
                (recipient_type = 'phone' AND recipient IS NOT NULL AND request_fingerprint IS NOT NULL)
                OR (recipient_type <> 'phone' AND recipient IS NULL AND request_fingerprint IS NULL))");
        }
    }

    public function down(): void
    {
        $blocking = DB::table('purchases')->where(fn ($q) => $q->where('recipient_type', '<>', 'phone')
            ->orWhereNull('recipient')->orWhereNull('request_fingerprint'))->count();
        if ($blocking > 0) {
            throw new RuntimeException("Refusing to roll back: {$blocking} purchase(s) are not phone purchases (NIN/BVN). "
                .'Phase 10 cannot represent them and purchases are never rewritten or deleted. Nothing was changed.');
        }

        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE purchases DROP CONSTRAINT purchases_recipient_by_type');
        }
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('recipient', 20)->nullable(false)->change();
            $table->char('request_fingerprint', 64)->nullable(false)->change();
        });
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('recipient_type'));
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
