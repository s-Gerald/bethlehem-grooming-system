<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'admin_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('admin_type', 20)->nullable();
            });
        }

        DB::table('users')->where('role', 'admin')->whereNull('admin_type')->update(['admin_type' => 'owner']);
        DB::table('users')->where('role', '!=', 'admin')->whereNotNull('admin_type')->update(['admin_type' => null]);
    }

    public function down(): void
    {
        // Retain role metadata on rollback instead of erasing the Owner/Manager distinction.
    }
};
