<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->uuid('email_recovery_id')->nullable()->index());
        Schema::create('staff_email_recoveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('user_id')->index();
            // Null for history; one authorizing or authorized request per account.
            $table->unsignedInteger('active_user_id')->nullable()->unique();
            $table->unsignedInteger('admin_user_id')->index();
            $table->unsignedBigInteger('session_token_id');
            $table->string('current_email', 150);
            $table->string('new_email', 150);
            $table->string('admin_email', 150);
            $table->string('reason', 24);
            $table->string('identity_method', 48);
            $table->dateTime('identity_verified_at');
            $table->string('account_fingerprint', 64);
            $table->string('admin_fingerprint', 64);
            $table->string('status', 16)->index();
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('admin_attempts')->default(0);
            $table->dateTime('code_expires_at');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('last_sent_at');
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('expiration_recorded_at')->nullable();
            $table->index(['status', 'expiration_recorded_at', 'expires_at'], 'staff_recovery_expiration_index');
            $table->timestamps();
        });
        Schema::create('staff_email_recovery_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('recovery_id')->index();
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->string('event', 32);
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_email_recovery_events');
        Schema::dropIfExists('staff_email_recoveries');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['email_recovery_id']);
            $table->dropColumn('email_recovery_id');
        });
    }
};
