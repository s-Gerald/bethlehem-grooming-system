<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaccination_records', function (Blueprint $table) {
            // A safe default for legacy writers: only the new entry workflow opts into Manual billing.
            $table->string('record_source', 20)->default('historical');
            $table->decimal('manual_price', 8, 2)->nullable();
        });
        DB::table('vaccination_records')->whereNotNull('inventory_item_id')->update(['record_source' => 'inventory']);
    }

    public function down(): void
    {
        Schema::table('vaccination_records', fn (Blueprint $table) => $table->dropColumn(['record_source', 'manual_price']));
    }
};
