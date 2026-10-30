<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BreedCoatMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('pets');
        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id');
            $table->string('species');
            $table->string('breed')->nullable();
            $table->enum('fur_type', ['short', 'medium', 'long', 'wire', 'curl'])->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pets');

        parent::tearDown();
    }

    public function test_migration_expands_and_converts_legacy_fur_types(): void
    {
        $petId = DB::table('pets')->insertGetId([
            'species' => 'Dog',
            'breed' => 'Siberian Husky',
            'fur_type' => 'wire',
        ], 'pet_id');

        $migration = require base_path(
            'database/migrations/2026_07_19_000001_expand_pet_fur_type_for_breed_coats.php',
        );
        $migration->up();

        $this->assertSame(
            'Standard Coat',
            DB::table('pets')->where('pet_id', $petId)->value('fur_type'),
        );

        DB::table('pets')->where('pet_id', $petId)->update(['fur_type' => 'Wooly Coat']);
        $this->assertSame(
            'Wooly Coat',
            DB::table('pets')->where('pet_id', $petId)->value('fur_type'),
        );

        $migration->down();
        $this->assertNull(DB::table('pets')->where('pet_id', $petId)->value('fur_type'));
    }
}
