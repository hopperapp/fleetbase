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
        Schema::create('ride_reviews', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('public_id')->unique()->index();
            $table->uuid('company_uuid')->index();
            $table->uuid('ride_uuid')->index();
            $table->uuid('reviewer_uuid');
            $table->string('reviewer_type', 20);    // 'customer' or 'driver'
            $table->uuid('reviewee_uuid');
            $table->string('reviewee_type', 20);

            $table->tinyInteger('rating');            // 1-5
            $table->text('comment')->nullable();
            $table->json('tags')->nullable();          // ['clean', 'polite', 'fast']

            $table->timestamps();
            $table->softDeletes();

            // One review per person per ride
            $table->unique(['ride_uuid', 'reviewer_uuid']);

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
        Schema::dropIfExists('ride_reviews');
    }
};
