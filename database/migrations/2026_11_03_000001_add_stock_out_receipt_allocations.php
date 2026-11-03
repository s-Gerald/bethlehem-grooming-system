<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Null means historical/inferred; [] means a recorded untracked removal.
            $table->json('stock_out_allocations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', fn (Blueprint $table) => $table->dropColumn('stock_out_allocations'));
    }
};
