<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'clinic_appointments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dateTime('registration_closes_at')->nullable();
                $blueprint->dateTime('registration_no_show_exempt_at')->nullable();
            });
        }
        Schema::table('bookings', fn (Blueprint $blueprint) => $blueprint->index(['status', 'booking_date'], 'bookings_registration_status_day_idx'));
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $blueprint) => $blueprint->dropIndex('bookings_registration_status_day_idx'));
        foreach (['bookings', 'clinic_appointments'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(['registration_closes_at', 'registration_no_show_exempt_at']));
        }
    }
};
