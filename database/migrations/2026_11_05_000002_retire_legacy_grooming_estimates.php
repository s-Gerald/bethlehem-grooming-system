<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('services', 'legacy_complete_estimates')) {
            Schema::table('services', fn (Blueprint $table) => $table->dropColumn('legacy_complete_estimates'));
        }
    }

    public function down(): void
    {
        // Restores schema compatibility only; retired complete estimates are not reconstructed.
        Schema::table('services', fn (Blueprint $table) => $table->json('legacy_complete_estimates')->nullable());
    }
};
