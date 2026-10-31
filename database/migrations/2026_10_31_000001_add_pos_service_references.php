<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            // Null retains the immutable legacy POS-{primary key} representation.
            $table->string('reference', 40)->nullable()->unique();
        });
        Schema::create('pos_sale_sequences', function (Blueprint $table) {
            $table->date('service_date')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        // Forward only: removing persisted references would renumber issued invoices.
        throw new LogicException('POS service references must be preserved.');
    }
};
