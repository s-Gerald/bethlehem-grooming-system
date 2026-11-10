<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_confinement_observations', function (Blueprint $table) {
            $table->json('verification_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinic_confinement_observations', fn (Blueprint $table) => $table->dropColumn('verification_history'));
    }
};
