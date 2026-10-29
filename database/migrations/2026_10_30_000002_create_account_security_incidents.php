<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_security_incidents')) return;
        Schema::create('account_security_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->date('incident_day');
            $table->string('type', 48)->default('repeated_otp_failures');
            $table->string('severity', 16)->default('warning');
            $table->timestamp('first_occurred_at')->useCurrent();
            $table->timestamp('last_occurred_at')->useCurrent()->index();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('alert_attempted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'incident_day']);
            $table->index(['user_id', 'reviewed_at', 'last_occurred_at'], 'security_incidents_owner_review');
            $table->index(['user_id', 'last_occurred_at'], 'security_incidents_owner_recent');
            $table->index(['user_id', 'alert_attempted_at'], 'security_incidents_owner_alert');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Forward-only: incident history is not discarded on a code rollback.
    }
};
