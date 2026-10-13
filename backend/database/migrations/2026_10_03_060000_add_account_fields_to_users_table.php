<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable so staff accounts can exist without a phone number; registration requires it.
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('user_type', 20)->default('subscriber')->index()->after('password');
            $table->string('status', 20)->default('active')->index()->after('user_type');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropIndex(['user_type']);
            $table->dropIndex(['status']);
            $table->dropColumn(['phone', 'user_type', 'status', 'last_login_at', 'last_login_ip']);
        });
    }
};
