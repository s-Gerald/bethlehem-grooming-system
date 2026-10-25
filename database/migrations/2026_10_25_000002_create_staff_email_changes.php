<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_email_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id')->unique();
            $table->unsignedBigInteger('session_token_id');
            $table->string('current_email', 150);
            $table->string('new_email', 150);
            $table->string('account_fingerprint', 64);
            $table->string('stage', 10);
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->dateTime('code_expires_at');
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_sent_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_email_changes');
    }
};
