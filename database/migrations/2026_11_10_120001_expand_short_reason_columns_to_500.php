<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_closures', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->change();
        });
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('batch_unavailable_reason', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the widened columns so rollback preserves reasons longer than 300 characters.
    }
};
