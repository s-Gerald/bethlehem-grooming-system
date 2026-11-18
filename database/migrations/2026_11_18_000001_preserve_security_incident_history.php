<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_security_incidents', function (Blueprint $table): void {
            if (! Schema::hasColumn('account_security_incidents', 'recognized_at')) $table->timestamp('recognized_at')->nullable();
            if (! Schema::hasColumn('account_security_incidents', 'resolved_at')) $table->timestamp('resolved_at')->nullable();
        });
        // Add replacement indexes before removing daily uniqueness (also an FK prefix).
        Schema::table('account_security_incidents', function (Blueprint $table): void {
            if (! Schema::hasIndex('account_security_incidents', 'security_incidents_owner_active')) {
                $table->index(['user_id', 'incident_day', 'recognized_at', 'resolved_at', 'reviewed_at'], 'security_incidents_owner_active');
            }
            if (! Schema::hasIndex('account_security_incidents', 'security_incidents_owner_history')) {
                $table->index(['user_id', 'first_occurred_at', 'id'], 'security_incidents_owner_history');
            }
        });
        if (Schema::hasIndex('account_security_incidents', 'account_security_incidents_user_id_incident_day_unique')) {
            Schema::table('account_security_incidents', fn (Blueprint $table) => $table->dropUnique(['user_id', 'incident_day']));
        }
    }

    public function down(): void
    {
        // Forward-only: neither terminal timestamps nor same-day history can be discarded safely.
    }
};
