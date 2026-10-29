<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'pre_archive_is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                // Null deliberately leaves legacy archives without inferred access.
                $table->boolean('pre_archive_is_active')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pre_archive_is_active')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pre_archive_is_active'));
        }
    }
};
