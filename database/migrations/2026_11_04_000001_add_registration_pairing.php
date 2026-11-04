<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->text('confirmation_code_ciphertext')->nullable();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->unsignedTinyInteger('confirmation_attempts')->default(0);
            $table->timestamp('registration_approved_at')->nullable();
            $table->timestamp('registration_completed_at')->nullable();
            $table->text('registration_session_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_customer_registrations', fn (Blueprint $table) => $table->dropColumn([
            'confirmation_code_ciphertext', 'confirmation_expires_at', 'confirmation_attempts',
            'registration_approved_at', 'registration_completed_at', 'registration_session_ciphertext',
        ]));
    }
};
