<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->unique();
        });
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('gateway_payment_id')->nullable()->unique();
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('cash');
            $table->unsignedBigInteger('gateway_payment_id')->nullable()->unique();
        });
        Schema::create('gateway_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_payment_id')->constrained('gateway_payments')->restrictOnDelete();
            $table->unsignedInteger('item_id')->index();
            $table->unsignedInteger('quantity');
            $table->unique(['gateway_payment_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_stock_reservations');
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropUnique(['gateway_payment_id']);
            $table->dropColumn(['payment_method', 'gateway_payment_id']);
        });
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropUnique(['gateway_payment_id']);
            $table->dropColumn('gateway_payment_id');
        });
        Schema::table('gateway_payments', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
        });
    }
};
