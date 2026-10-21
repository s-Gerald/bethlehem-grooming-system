<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('phone', 20)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Check both tables before MySQL's non-transactional schema changes.
        foreach (['users', 'pending_customer_registrations'] as $name) {
            if (DB::table($name)->whereNull('phone')->exists()) {
                throw new RuntimeException('Cannot require phone numbers while NULL values exist. Resolve them intentionally before rolling back.');
            }
        }

        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('phone', 20)->nullable(false)->change();
            });
        }
    }
};
