<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('unregistered_customers', 'middle_name')) {
            Schema::table('unregistered_customers', fn (Blueprint $table) => $table->dropColumn('middle_name'));
        }

        if (Schema::hasColumn('walkins', 'mname')) {
            Schema::table('walkins', fn (Blueprint $table) => $table->dropColumn('mname'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('unregistered_customers', 'middle_name')) {
            Schema::table('unregistered_customers', fn (Blueprint $table) => $table->string('middle_name', 5)->nullable()->after('last_name'));
        }

        if (! Schema::hasColumn('walkins', 'mname')) {
            Schema::table('walkins', fn (Blueprint $table) => $table->string('mname')->nullable()->after('lname'));
        }
    }
};
