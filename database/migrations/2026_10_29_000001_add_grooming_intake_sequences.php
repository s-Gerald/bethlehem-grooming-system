<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grooming_intake_sequences', function (Blueprint $table) {
            $table->date('queue_date')->primary();
            $table->unsignedInteger('last_booking_number')->default(0);
            $table->unsignedInteger('last_pet_number')->default(0);
        });
        Schema::create('grooming_walkin_submissions', function (Blueprint $table) {
            $table->uuid('submission_key')->primary();
            $table->unsignedInteger('actor_user_id');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('booking_id');
            $table->json('response');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_walkin_submissions');
        Schema::dropIfExists('grooming_intake_sequences');
    }
};
