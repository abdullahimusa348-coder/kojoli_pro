<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products group plans inside a service (e.g. "MTN SME" under Data).
        // Structure only: no prices or providers here (Phases 6 and 7).
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->string('name', 100);
            // "<service-slug>-<name>", generated on create and never changed afterwards.
            $table->string('code', 150)->unique();
            // Optional mobile network (fixed list: mtn, airtel, glo, 9mobile).
            $table->string('network', 20)->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
