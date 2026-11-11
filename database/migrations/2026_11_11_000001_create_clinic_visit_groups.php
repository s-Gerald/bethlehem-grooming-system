<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_visit_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreignId('walkin_id')->nullable()->constrained('walkins')->nullOnDelete();
            $table->string('source', 20);
            $table->date('service_day');
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->unsignedInteger('paid_by_user_id')->nullable();
            $table->foreign('paid_by_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->index();
            $table->unsignedBigInteger('gateway_payment_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->foreignId('clinic_visit_group_id')->nullable()->index()
                ->constrained('clinic_visit_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinic_visit_group_id');
        });
        Schema::dropIfExists('clinic_visit_groups');
    }
};
