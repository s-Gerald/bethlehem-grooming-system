<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_security_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id');
            $table->string('kind', 8);
            $table->unsignedBigInteger('session_token_id');
            $table->string('account_fingerprint', 64);
            $table->string('new_email', 150)->nullable();
            $table->string('new_password_hash')->nullable();
            $table->string('code_hash')->nullable();
            $table->string('delivery_receipt', 64)->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->dateTime('last_sent_at');
            $table->dateTime('code_expires_at');
            $table->dateTime('expires_at')->index();
            $table->unique(['user_id', 'kind']);
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_security_changes');
    }
};
