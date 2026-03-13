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
        Schema::create('rides', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique()->index();
            $table->uuid('company_uuid')->index();

            // Store/Network context
            $table->uuid('store_uuid')->nullable()->index();
            $table->uuid('network_uuid')->nullable()->index();

            // Fleetbase Core Links
            $table->uuid('order_uuid')->nullable()->index();
            $table->uuid('customer_uuid')->index();
            $table->uuid('driver_uuid')->nullable()->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->uuid('pickup_place_uuid')->nullable()->index();
            $table->uuid('dropoff_place_uuid')->nullable()->index();

            // Vehicle Selection
            $table->uuid('vehicle_category_uuid')->index();
            $table->uuid('vehicle_sub_category_uuid')->nullable()->index();

            // Pricing
            $table->enum('pricing_method', ['auto', 'fixed', 'bidding'])->default('auto');
            $table->integer('estimated_price')->default(0);
            $table->integer('customer_price')->nullable();
            $table->integer('final_price')->nullable();
            $table->string('currency', 5)->default('YER');
            $table->enum('payment_method', ['cash', 'transfer', 'wallet'])->default('cash');

            // Route
            $table->integer('distance_meters')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('pickup_address')->nullable();
            $table->decimal('dropoff_latitude', 10, 7)->nullable();
            $table->decimal('dropoff_longitude', 10, 7)->nullable();
            $table->string('dropoff_address')->nullable();

            // Status
            $table->string('status', 30)->default('pending')->index();

            // Scheduling
            $table->boolean('is_scheduled')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('canceled_by')->nullable();
            $table->text('cancel_reason')->nullable();

            // Metadata
            $table->integer('passenger_count')->default(1);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index(['status', 'company_uuid']);
            $table->index(['customer_uuid', 'status']);
            $table->index(['driver_uuid', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['pickup_latitude', 'pickup_longitude']);
            $table->index(['store_uuid', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
