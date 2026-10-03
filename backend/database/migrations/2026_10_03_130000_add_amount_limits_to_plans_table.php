<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Face-value limits (kobo) for variable-amount plans: the range of
        // amounts a customer may enter. They are not prices; fixed plans leave
        // them empty.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('min_amount_kobo')->nullable()->after('data_volume_mb');
            $table->unsignedBigInteger('max_amount_kobo')->nullable()->after('min_amount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['min_amount_kobo', 'max_amount_kobo']);
        });
    }
};
