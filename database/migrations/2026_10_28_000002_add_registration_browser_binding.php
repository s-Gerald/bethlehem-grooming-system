<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pending_customer_registrations', 'registration_browser_token_hash')) {
            Schema::table('pending_customer_registrations', fn (Blueprint $table) => $table->string('registration_browser_token_hash', 64)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pending_customer_registrations', 'registration_browser_token_hash')) {
            Schema::table('pending_customer_registrations', fn (Blueprint $table) => $table->dropColumn('registration_browser_token_hash'));
        }
    }
};
