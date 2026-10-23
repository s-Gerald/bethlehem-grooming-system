<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL may partially commit; each addition can safely resume.
        if (! Schema::hasColumn('qr_payment_settings', 'accepted_methods')) {
            Schema::table('qr_payment_settings', fn (Blueprint $table) => $table->json('accepted_methods')->nullable());
        }
        if (! Schema::hasColumn('qr_payments', 'payment_source')) {
            Schema::table('qr_payments', fn (Blueprint $table) => $table->string('payment_source', 100)->nullable());
        }
        // Preserve historical receiving-provider snapshots; new payments no longer collect one.
        Schema::table('qr_payments', fn (Blueprint $table) => $table->string('provider_name', 100)->nullable()->change());
        if (Schema::hasColumn('qr_payment_settings', 'provider_name')) {
            Schema::table('qr_payment_settings', fn (Blueprint $table) => $table->dropColumn('provider_name'));
        }
    }

    public function down(): void
    {
        Schema::table('qr_payment_settings', function (Blueprint $table) {
            $table->string('provider_name', 100)->nullable();
            $table->dropColumn('accepted_methods');
        });
        Schema::table('qr_payments', fn (Blueprint $table) => $table->dropColumn('payment_source'));
    }
};
