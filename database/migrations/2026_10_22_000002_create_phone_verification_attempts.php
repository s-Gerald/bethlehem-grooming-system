<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verification_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('phone', 20)->index();
            $table->uuid('generation')->unique();
            $table->string('original_phone', 20)->nullable();
            $table->timestamp('original_verified_at')->nullable();
            $table->string('code_fingerprint', 64)->nullable();
            $table->string('status', 24);
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('attempts_reset_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_attempts');
    }
};
