<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remembered_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            // Deliberately no token FK: idle/absolute session deletion must not delete the device.
            $table->unsignedBigInteger('session_token_id')->nullable()->index();
            // Explicit defaults prevent legacy MySQL implicit ON UPDATE on the absolute deadline.
            $table->timestamp('expires_at')->useCurrent()->index();
            $table->timestamp('last_used_at')->useCurrent()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('remembered_device_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remembered_device_id')->constrained()->cascadeOnDelete();
            $table->string('selector', 32)->unique(); // Random, public lookup identifier.
            $table->string('validator_hash', 64);
            $table->unsignedBigInteger('session_token_id')->nullable();
            $table->index(['remembered_device_id', 'session_token_id'], 'remembered_credential_session');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remembered_device_credentials');
        Schema::dropIfExists('remembered_devices');
    }
};
