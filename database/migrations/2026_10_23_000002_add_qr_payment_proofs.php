<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_payments', function (Blueprint $table) {
            $table->string('paying_app', 100)->nullable();
            $table->string('proof_path')->nullable()->unique();
            $table->string('proof_name', 150)->nullable();
            $table->string('proof_mime', 40)->nullable();
            $table->unsignedInteger('proof_size')->nullable();
            $table->char('proof_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('qr_payments', function (Blueprint $table) {
            $table->dropUnique(['proof_path']);
            $table->dropColumn(['paying_app', 'proof_path', 'proof_name', 'proof_mime', 'proof_size', 'proof_hash']);
        });
    }
};
