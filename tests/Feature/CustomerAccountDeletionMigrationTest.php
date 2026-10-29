<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerAccountDeletionMigrationTest extends TestCase
{
    public function test_archive_access_migration_preserves_legacy_rows_without_guessing_state(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_archived')->default(true);
        });
        \Illuminate\Support\Facades\DB::table('users')->insert(['user_id' => 7]);
        $migration = require database_path('migrations/2026_10_29_000001_preserve_customer_archive_access_state.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('users', ['user_id' => 7, 'is_active' => false, 'is_archived' => true, 'pre_archive_is_active' => null]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'pre_archive_is_active'));
        $this->assertDatabaseHas('users', ['user_id' => 7, 'is_active' => false, 'is_archived' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_migration_adds_and_removes_customer_account_tombstones(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('unregistered_customers', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('archived_at')->nullable();
        });

        $migration = require database_path(
            'migrations/2026_08_19_000001_add_account_deleted_at_to_customer_records.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'account_deleted_at'));
        $this->assertTrue(Schema::hasColumn('unregistered_customers', 'account_deleted_at'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'account_deleted_at'));
        $this->assertFalse(Schema::hasColumn('unregistered_customers', 'account_deleted_at'));
    }
}
