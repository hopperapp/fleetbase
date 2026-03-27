<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('store_uuid')->nullable()->index();
            $table->string('name');
            $table->string('key')->index();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_uuid')->nullable();
            $table->integer('base_fare')->default(0);
            $table->integer('per_km_fare')->default(0);
            $table->integer('per_min_fare')->default(0);
            $table->integer('min_fare')->default(0);
            $table->integer('max_passengers')->default(4);
            $table->string('currency', 5)->default('YER');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_categories');
    }
};
