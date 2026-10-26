<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('booking_pets', 'haircut_requested')) {
            Schema::table('booking_pets', fn (Blueprint $table) => $table->boolean('haircut_requested')->default(false));
        }
    }

    public function down(): void
    {
        // Visit-specific requests must survive application rollbacks.
    }
};
