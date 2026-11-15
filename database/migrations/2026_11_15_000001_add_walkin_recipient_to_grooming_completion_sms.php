<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grooming_completion_sms', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('walkin_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('grooming_completion_sms')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Cannot restore required user IDs while walk-in completion SMS events exist.');
        }

        Schema::table('grooming_completion_sms', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable(false)->change();
            $table->dropColumn('walkin_id');
        });
    }
};
