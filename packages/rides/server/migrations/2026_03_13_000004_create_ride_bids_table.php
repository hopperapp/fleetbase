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
        Schema::create('ride_bids', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('ride_uuid')->index();
            $table->uuid('driver_uuid')->index();
            $table->uuid('vehicle_uuid')->nullable()->index();

            $table->integer('amount');
            $table->string('currency', 5)->default('YER');
            $table->integer('estimated_arrival_min')->nullable();
            $table->text('note')->nullable();

            $table->string('status', 20)->default('pending')->index();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index(['ride_uuid', 'status']);
            $table->index(['driver_uuid', 'status']);

            // One bid per driver per ride
            $table->unique(['ride_uuid', 'driver_uuid']);

            $table->foreign('ride_uuid')
                ->references('uuid')
                ->on('rides')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_bids');
    }
};
