<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Historical receipts keep their SI accessor; do not backfill identifiers.
            $table->string('stock_in_reference', 32)->nullable()->unique();
            $table->string('batch_unavailable_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropUnique(['stock_in_reference']);
            $table->dropColumn(['stock_in_reference', 'batch_unavailable_reason']);
        });
    }
};
