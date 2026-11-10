<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits separately: allow a retry after an interrupted backfill.
        if (! Schema::hasColumn('inventory_items', 'deactivated_at')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dateTime('deactivated_at')->nullable();
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $archiveDates = DB::table('activity_logs')->select('subject_id')->selectRaw('MAX(occurred_at) AS archived_at')
            ->where('subject_type', 'inventory')->where('action', 'inventory_archived')->where('result', 'success')
            ->groupBy('subject_id');
        $latestArchive = DB::query()->fromSub($archiveDates, 'archives')->select('archived_at')
            ->whereColumn('archives.subject_id', DB::raw('CAST(inventory_items.item_id AS CHAR)'));

        // Aggregate history once in a database update; unknown dates stay NULL and existing dates survive retries.
        DB::table('inventory_items')->where('is_active', false)->whereNull('deactivated_at')
            ->update(['deactivated_at' => $latestArchive]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
