<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PetVerificationMigrationTest extends TestCase
{
    private $migration;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('fur_type')->nullable();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
        });

        $this->migration = require base_path(
            'database/migrations/2026_08_09_000001_add_pet_verification_and_notification_linkage.php',
        );
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('pets');
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    public function test_migration_persists_verified_fields_and_links_notifications_to_pets(): void
    {
        $this->assertTrue(Schema::hasColumn('pets', 'clinic_verified_fields'));
        $this->assertTrue(Schema::hasColumn('customer_notifications', 'pet_id'));

        $indexes = collect(DB::select(
            "PRAGMA index_list('customer_notifications')",
        ));
        $this->assertTrue($indexes->contains('name', 'cn_pet_idx'));

        $foreignKey = collect(DB::select(
            "PRAGMA foreign_key_list('customer_notifications')",
        ))->firstWhere('from', 'pet_id');

        $this->assertNotNull($foreignKey);
        $this->assertSame('pets', $foreignKey->table);
        $this->assertSame('pet_id', $foreignKey->to);
        $this->assertSame('SET NULL', $foreignKey->on_delete);

        DB::table('pets')->insert([
            'pet_id' => 10,
            'clinic_verified_fields' => json_encode(
                ['breed', 'weight'],
                JSON_THROW_ON_ERROR,
            ),
        ]);
        DB::table('customer_notifications')->insert([
            'user_id' => 5,
            'pet_id' => 10,
        ]);

        DB::table('pets')->where('pet_id', 10)->delete();

        $this->assertNull(
            DB::table('customer_notifications')->value('pet_id'),
        );
    }

    public function test_migration_can_be_rolled_back(): void
    {
        $this->migration->down();

        $this->assertFalse(
            Schema::hasColumn('pets', 'clinic_verified_fields'),
        );
        $this->assertFalse(
            Schema::hasColumn('customer_notifications', 'pet_id'),
        );
    }

    public function test_customer_lock_migration_preserves_legacy_information_and_rolls_back_only_its_column(): void
    {
        DB::table('pets')->insert(['pet_id' => 10, 'fur_type' => 'short',
            'clinic_verified_fields' => json_encode(['size'])]);
        $migration = require base_path('database/migrations/2026_10_30_000001_add_customer_locked_fields_to_pets.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('pets', 'customer_locked_fields'));
        $this->assertDatabaseHas('pets', ['pet_id' => 10, 'fur_type' => 'short', 'customer_locked_fields' => null]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('pets', 'customer_locked_fields'));
        $this->assertDatabaseHas('pets', ['pet_id' => 10, 'fur_type' => 'short', 'clinic_verified_fields' => json_encode(['size'])]);
    }
}
