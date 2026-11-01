<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerTermsAcceptanceMigrationTest extends TestCase
{
    public function test_partial_migration_can_resume_without_backfilling_or_losing_accounts(): void
    {
        foreach (['users', 'pending_customer_registrations'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('email');
            });
            DB::table($name)->insert(['email' => 'existing@example.com']);
        }
        Schema::table('users', fn (Blueprint $table) => $table->string('terms_accepted_version', 32)->nullable());
        $migration = require database_path('migrations/2026_11_01_000001_add_customer_terms_acceptance.php');
        $migration->up();
        $migration->up();

        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertDatabaseHas($name, ['email' => 'existing@example.com',
                'terms_accepted_version' => null, 'terms_accepted_at' => null]);
            DB::table($name)->insert(['email' => 'accepted@example.com',
                'terms_accepted_version' => '2026-11-01', 'terms_accepted_at' => '2026-11-01 10:00:00']);
        }
        $migration->up();
        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertDatabaseHas($name, ['email' => 'accepted@example.com',
                'terms_accepted_version' => '2026-11-01', 'terms_accepted_at' => '2026-11-01 10:00:00']);
        }
        $migration->down();
        foreach (['users', 'pending_customer_registrations'] as $name) {
            $this->assertDatabaseCount($name, 2);
            $this->assertFalse(Schema::hasColumn($name, 'terms_accepted_version'));
            $this->assertFalse(Schema::hasColumn($name, 'terms_accepted_at'));
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pending_customer_registrations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }
}
