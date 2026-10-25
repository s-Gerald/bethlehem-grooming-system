<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grooming_completion_sms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id')->unique();
            $table->unsignedInteger('user_id');
            $table->string('status', 20)->default('pending');
            $table->string('provider_message_id', 100)->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grooming_completion_sms');
    }
};
