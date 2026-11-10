<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedInteger('inventory_transaction_id')->nullable();
            $table->string('context')->nullable();
            $table->unique(['inventory_transaction_id', 'type'], 'notifications_inventory_state_unique');
            $table->foreign('inventory_transaction_id')->references('transaction_id')
                ->on('inventory_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['inventory_transaction_id']);
            $table->dropUnique('notifications_inventory_state_unique');
            $table->dropColumn(['inventory_transaction_id', 'context']);
        });
    }
};
