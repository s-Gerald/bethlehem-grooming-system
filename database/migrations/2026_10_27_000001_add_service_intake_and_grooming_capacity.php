<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('grooming_cage_capacity')->default(20);
        });
        Schema::table('clinic_closures', function (Blueprint $table) {
            // Null retains the meaning of historical global stops and blocked dates.
            $table->string('service', 16)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_closures', function (Blueprint $table) {
            $table->dropIndex(['service']);
            $table->dropColumn('service');
        });
        Schema::table('clinic_settings', fn (Blueprint $table) => $table->dropColumn('grooming_cage_capacity'));
    }
};
