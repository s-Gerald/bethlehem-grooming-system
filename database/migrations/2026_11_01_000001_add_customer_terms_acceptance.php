<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'pending_customer_registrations'] as $name) {
            if (! Schema::hasColumn($name, 'terms_accepted_version')) {
                Schema::table($name, fn (Blueprint $table) => $table->string('terms_accepted_version', 32)->nullable());
            }
            if (! Schema::hasColumn($name, 'terms_accepted_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->timestamp('terms_accepted_at')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['terms_accepted_version', 'terms_accepted_at']));
        }
    }
};
