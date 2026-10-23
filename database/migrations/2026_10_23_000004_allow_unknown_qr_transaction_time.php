<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_payments', function (Blueprint $table) {
            $table->dateTime('transaction_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only: restoring NOT NULL would require inventing missing receipt times.
    }
};
