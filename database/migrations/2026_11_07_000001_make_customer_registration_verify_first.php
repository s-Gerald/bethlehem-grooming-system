<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only disposable signup attempts are invalidated. Deploy in maintenance.
        if (Schema::hasTable('pending_customer_registrations')
            && Schema::hasColumn('pending_customer_registrations', 'completed_session_id')
            && ! Schema::hasColumn('pending_customer_registrations', 'password_hash')) return;

        Schema::dropIfExists('pending_customer_registrations');
        Schema::create('pending_customer_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->index();
            $table->string('registration_edit_token_hash', 64)->unique();
            $table->string('registration_browser_token_hash', 64);
            $table->string('email_verification_token', 64)->nullable()->unique();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->timestamp('registration_approved_at')->nullable();
            $table->timestamp('registration_completed_at')->nullable();
            $table->unsignedBigInteger('completed_session_id')->nullable();
            $table->timestamps();
            $table->index('created_at', 'pending_registration_created_idx');
        });
    }

    public function down(): void
    {
        // Rollback invalidates attempts too; credentials cannot be reconstructed.
        Schema::dropIfExists('pending_customer_registrations');
        (require __DIR__.'/2026_08_18_000001_create_pending_customer_registrations_table.php')->up();
        Schema::table('pending_customer_registrations', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('email_verification_code_hash')->nullable();
            $table->unsignedTinyInteger('email_verification_attempts')->default(0);
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->string('registration_edit_token_hash', 64)->nullable()->unique('pending_registration_edit_token_unique');
            $table->string('registration_browser_token_hash', 64)->nullable();
            $table->string('registration_recovery_token_hash', 64)->nullable()->unique('pending_reg_recovery_token_uq');
            $table->timestamp('registration_recovery_expires_at')->nullable();
            $table->string('terms_accepted_version', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->index('created_at', 'pending_registration_created_idx');
        });
        (require __DIR__.'/2026_11_04_000001_add_registration_pairing.php')->up();
    }
};
