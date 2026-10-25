<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('replaced_at')->nullable();
        });

        // Preserve the newest existing session per account and its expiry.
        // A derived table also supports MySQL's same-table update restriction.
        $newest = DB::table('personal_access_tokens')->selectRaw('MAX(id) as id')
            ->groupBy('tokenable_type', 'tokenable_id');
        DB::table('personal_access_tokens')->whereNotIn('id',
            DB::query()->fromSub($newest, 'newest_sessions')->select('id')
        )->update(['replaced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('replaced_at'));
    }
};
