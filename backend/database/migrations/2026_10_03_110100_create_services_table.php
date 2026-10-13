<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-managed catalog services. Catalog only: no plans, pricing,
        // providers or purchasing here (later phases build on these rows).
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('name', 100);
            // Generated from the name on create and never changed afterwards.
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
