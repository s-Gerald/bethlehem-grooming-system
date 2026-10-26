<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pets', function (Blueprint $table) {
            $table->dropColumn(['grooming_estimate_min', 'grooming_estimate_max', 'grooming_estimate_factors']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
        // Keep accepted arrival windows: reminders and expiry still use their snapshots.
    }

    public function down(): void
    {
        throw new RuntimeException('Grooming estimate retirement is forward-only. Restore the database backup with the matching release to roll back.');
    }
};
