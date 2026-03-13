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
        Schema::create('vehicle_sub_categories', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('vehicle_category_uuid')->index();
            $table->string('name');
            $table->string('key')->index();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_uuid')->nullable();
            $table->integer('fare_multiplier')->default(100); // 100 = 1.0x, 150 = 1.5x
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vehicle_category_uuid')
                ->references('uuid')
                ->on('vehicle_categories')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_sub_categories');
    }
};
