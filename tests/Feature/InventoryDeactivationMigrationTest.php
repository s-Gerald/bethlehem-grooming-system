<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryDeactivationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->increments('item_id');
            $table->boolean('is_active');
            $table->timestamps();
        });
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_10_000003_add_inventory_deactivation_timestamp.php');
    }

    public function test_backfill_uses_latest_successful_inventory_archive_only_and_preserves_unknown_dates(): void
    {
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        foreach ([1 => false, 2 => false, 3 => true, 4 => false] as $id => $active) {
            DB::table('inventory_items')->insert(['item_id' => $id, 'is_active' => $active, 'updated_at' => '2026-11-10 00:00:00']);
        }
        $event = fn ($id, $date, $overrides = []) => DB::table('activity_logs')->insert([
            'actor_display' => 'Admin', 'area' => 'system', 'action' => 'inventory_archived',
            'subject_type' => 'inventory', 'subject_id' => (string) $id, 'context' => 'Product',
            'occurred_at' => $date, 'result' => 'success', ...$overrides,
        ]);
        // Intentionally insert historical events out of timestamp order.
        $event(1, '2026-11-08 06:45:00');
        $event(1, '2026-01-01 00:00:00');
        $event(1, '2026-11-09 00:00:00', ['result' => 'failed']);
        $event(1, '2026-11-10 00:00:00', ['subject_type' => 'account']);
        $event(1, '2026-11-10 00:00:00', ['action' => 'inventory_restored']);
        $event(2, '2026-11-10 00:00:00', ['result' => 'failed']);
        $event(3, '2026-11-08 00:00:00');
        $event(99, '2026-11-10 00:00:00');
        $event('1-unrelated', '2026-11-10 00:00:00');
        $this->migration()->up();
        $this->assertSame('2026-11-08 06:45:00', DB::table('inventory_items')->where('item_id', 1)->value('deactivated_at'));
        foreach ([2, 3, 4] as $id) {
            $this->assertNull(DB::table('inventory_items')->where('item_id', $id)->value('deactivated_at'));
        }
        DB::table('inventory_items')->where('item_id', 1)->update(['deactivated_at' => '2026-11-10 01:00:00']);
        $this->migration()->up();
        $this->assertSame('2026-11-10 01:00:00', DB::table('inventory_items')->where('item_id', 1)->value('deactivated_at'));
        $this->assertSame('2026-11-10 00:00:00', DB::table('inventory_items')->where('item_id', 1)->value('updated_at'));
        $this->assertSame(9, DB::table('activity_logs')->count());
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('inventory_items', 'deactivated_at'));
    }

    public function test_reduced_database_without_activity_history_keeps_legacy_dates_unknown(): void
    {
        DB::table('inventory_items')->insert(['is_active' => false, 'updated_at' => '2026-01-01']);
        $this->migration()->up();
        $this->assertNull(DB::table('inventory_items')->value('deactivated_at'));
        $this->migration()->up();
        $this->assertNull(DB::table('inventory_items')->value('deactivated_at'));
    }
}
