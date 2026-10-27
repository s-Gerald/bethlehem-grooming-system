<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_vitals', function (Blueprint $table) {
            $table->json('verification_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_vitals', fn (Blueprint $table) => $table->dropColumn('verification_history'));
    }
};
