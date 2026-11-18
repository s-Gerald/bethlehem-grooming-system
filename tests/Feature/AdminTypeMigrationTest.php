<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminTypeMigrationTest extends TestCase
{
    public function test_repeatable_migration_backfills_existing_admins_without_promoting_managers_or_staff(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('role');
        });
        try {
            DB::table('users')->insert([['role' => 'admin'], ['role' => 'staff'], ['role' => 'customer']]);
            $this->assertTrue(User::findOrFail(1)->isOwner(), 'Legacy admin identity remains Owner before backfill.');
            $migration = require database_path('migrations/2026_11_18_000002_add_admin_type_to_users.php');
            $migration->up();
            DB::table('users')->insert(['role' => 'admin', 'admin_type' => 'manager']);
            $migration->up();
            $migration->down();
            $this->assertSame('owner', User::findOrFail(1)->admin_type);
            $this->assertNull(User::findOrFail(2)->admin_type);
            $this->assertNull(User::findOrFail(3)->admin_type);
            $this->assertTrue(User::findOrFail(4)->isManager());
            $this->assertFalse(User::findOrFail(4)->isOwner());
        } finally {
            Schema::dropIfExists('users');
        }
    }
}
