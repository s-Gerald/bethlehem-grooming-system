<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerMiddleNameRemovalMigrationTest extends TestCase
{
    public function test_migration_removes_and_restores_the_nullable_columns_without_changing_owner_records(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->increments('user_id'));
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
        });
        foreach ([
            '2026_06_28_200718_create_walkins_table.php',
            '2026_08_10_223000_create_unregistered_customers_table.php',
            '2026_08_10_233000_add_unregistered_customer_links_to_walkins_and_pets.php',
        ] as $filename) {
            (require database_path('migrations/'.$filename))->up();
        }

        DB::table('users')->insert(['user_id' => 1]);
        $customer = [
            'id' => 42, 'first_name' => 'Maria', 'last_name' => 'Santos', 'phone' => '09171234567',
            'created_by_user_id' => 1, 'created_at' => '2026-11-07 09:30:00', 'updated_at' => '2026-11-07 09:30:00',
        ];
        $walkin = [
            'id' => 12, 'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'unregistered_customer_id' => 42, 'created_at' => '2026-11-07 09:30:00', 'updated_at' => '2026-11-07 09:30:00',
        ];
        $pet = ['id' => 17, 'unregistered_customer_id' => 42];
        DB::table('unregistered_customers')->insert($customer);
        DB::table('walkins')->insert($walkin);
        DB::table('pets')->insert($pet);

        $customerIndexes = Schema::getIndexes('unregistered_customers');
        $walkinForeignKeys = Schema::getForeignKeys('walkins');
        $path = database_path('migrations/2026_11_08_000001_remove_customer_middle_name_columns.php');
        $this->assertFileExists($path);
        $migration = require $path;

        $migration->up();
        $migration->up();
        $this->assertFalse(Schema::hasColumn('unregistered_customers', 'middle_name'));
        $this->assertFalse(Schema::hasColumn('walkins', 'mname'));
        $this->assertSame($customerIndexes, Schema::getIndexes('unregistered_customers'));
        $this->assertSame($walkinForeignKeys, Schema::getForeignKeys('walkins'));
        $this->assertDatabaseHas('unregistered_customers', $customer);
        $this->assertDatabaseHas('walkins', $walkin);
        $this->assertDatabaseHas('pets', $pet);

        $migration->down();
        $this->assertDatabaseHas('unregistered_customers', [...$customer, 'middle_name' => null]);
        $this->assertDatabaseHas('walkins', [...$walkin, 'mname' => null]);
        $this->assertDatabaseHas('pets', $pet);
    }
}
