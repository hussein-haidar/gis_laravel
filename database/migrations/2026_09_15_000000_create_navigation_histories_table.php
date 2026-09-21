<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_name')->nullable();
            $table->double('origin_lat')->nullable();
            $table->double('origin_lng')->nullable();
            $table->string('dest_name');
            $table->double('dest_lat');
            $table->double('dest_lng');
            $table->string('vehicle', 20)->default('mobil');
            $table->string('profile', 20)->default('driving');
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('travel_seconds')->nullable();
            $table->json('route_geometry')->nullable();
            $table->json('steps')->nullable();
            $table->string('status', 20)->default('ongoing');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_histories');
    }
};