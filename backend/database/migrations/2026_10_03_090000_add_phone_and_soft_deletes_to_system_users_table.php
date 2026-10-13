<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_users', function (Blueprint $table) {
            // Optional staff contact number, same format as customer phones (0XXXXXXXXXX).
            $table->string('phone', 20)->nullable()->unique()->after('email');
            // Deleted staff accounts are kept (soft deleted) for history; they cannot sign in.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('system_users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone');
            $table->dropSoftDeletes();
        });
    }
};
