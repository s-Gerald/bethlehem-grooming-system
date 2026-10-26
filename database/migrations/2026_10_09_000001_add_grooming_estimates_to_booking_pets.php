<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->string('grooming_preference', 32)->nullable();
            $table->unsignedSmallInteger('grooming_estimate_min')->nullable();
            $table->unsignedSmallInteger('grooming_estimate_max')->nullable();
            $table->json('grooming_estimate_factors')->nullable();
        });
        // Duration backfill retired; actual visit data is preserved.
    }

    public function down(): void
    {
        Schema::table('booking_pets', fn (Blueprint $table) => $table->dropColumn([
            'grooming_preference', 'grooming_estimate_min', 'grooming_estimate_max', 'grooming_estimate_factors',
        ]));
    }
};
