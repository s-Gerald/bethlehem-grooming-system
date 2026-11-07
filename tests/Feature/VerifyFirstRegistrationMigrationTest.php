<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerifyFirstRegistrationMigrationTest extends TestCase
{
    private const TABLE = 'pending_customer_registrations';

    public static function partialSchemas(): array
    {
        return [['token_indexes_missing'], ['column_missing'], ['unique_email'], ['complete']];
    }

    #[DataProvider('partialSchemas')]
    public function test_rerun_repairs_incomplete_schema_and_preserves_complete_attempts(string $state): void
    {
        $this->exerciseMigration($state);
    }

    public function test_clean_mysql_install_and_partial_schema_recovery(): void
    {
        if (getenv('REGISTRATION_MIGRATION_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Opt in with REGISTRATION_MIGRATION_MYSQL_TEST=1 for an owned disposable local MySQL schema.');
        }
        require_once __DIR__.'/../Support/CapacityMysqlHarness.php';
        $settings = \Tests\Support\capacityMysqlSettings(getenv());
        $server = new \PDO("mysql:host={$settings['host']};port={$settings['port']};charset=utf8mb4",
            $settings['username'], $settings['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
        $identity = $server->query('SELECT @@hostname AS hostname, @@port AS port')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(strtolower(explode('.', gethostname())[0]), strtolower(explode('.', $identity['hostname'])[0]));
        $this->assertSame((int) $settings['port'], (int) $identity['port']);
        $database = 'bethlehem_reg_schema_'.bin2hex(random_bytes(6));
        $this->assertMatchesRegularExpression('/\Abethlehem_reg_schema_[a-f0-9]{12}\z/', $database);
        $schema = Schema::getFacadeRoot();
        $created = false;
        try {
            $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;
            config(['database.connections.registration_schema_mysql' => [
                'driver' => 'mysql', 'host' => $settings['host'], 'port' => $settings['port'],
                'database' => $database, 'username' => $settings['username'], 'password' => $settings['password'],
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]]);
            $connection = DB::connection('registration_schema_mysql');
            $this->assertSame($database, $connection->selectOne('SELECT DATABASE() AS name')->name);
            Schema::swap($connection->getSchemaBuilder());
            // Clean installation must execute real MySQL DDL, including every index.
            $this->migration()->up();
            $this->assertVerificationSchema();
            foreach (self::partialSchemas() as [$state]) $this->exerciseMigration($state);
        } finally {
            Schema::swap($schema);
            DB::purge('registration_schema_mysql');
            if ($created) {
                $server->exec("DROP DATABASE `{$database}`");
                $check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
                $check->execute([$database]);
                $this->assertSame(0, (int) $check->fetchColumn());
            }
        }
    }

    private function exerciseMigration(string $state): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $table) use ($state) {
            $table->id();
            $table->string('email', 150)->index('pending_reg_email_idx');
            $table->string('registration_edit_token_hash', 64);
            $table->string('registration_browser_token_hash', 64);
            $table->string('email_verification_token', 64)->nullable();
            $table->timestamp('email_verification_expires_at')->nullable();
            $table->timestamp('email_verification_last_sent_at')->nullable();
            $table->timestamp('registration_approved_at')->nullable();
            if ($state !== 'column_missing') $table->timestamp('registration_completed_at')->nullable();
            $table->unsignedBigInteger('completed_session_id')->nullable();
            $table->timestamps();
            $table->index('created_at', 'pending_registration_created_idx');
            if ($state !== 'token_indexes_missing') {
                $table->unique('registration_edit_token_hash', 'pending_reg_edit_token_uq');
                $table->unique('email_verification_token', 'pending_reg_email_token_uq');
            }
            if ($state === 'unique_email') $table->unique('email', 'obsolete_email_reservation_uq');
        });
        $connection = Schema::getConnection();
        $connection->table(self::TABLE)->insert([
            'email' => 'attempt@gmail.com', 'registration_edit_token_hash' => str_repeat('a', 64),
            'registration_browser_token_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->migration()->up();
        $this->assertVerificationSchema();
        $this->assertSame($state === 'complete' ? 1 : 0, $connection->table(self::TABLE)->count());
        // Completed schemas are idempotent; independent same-email attempts remain valid.
        $connection->table(self::TABLE)->insert([
            'email' => 'attempt@gmail.com', 'registration_edit_token_hash' => str_repeat('c', 64),
            'registration_browser_token_hash' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $connection->table(self::TABLE)->orderBy('id')->get()->toArray();
        $this->migration()->up();
        $this->assertEquals($before, $connection->table(self::TABLE)->orderBy('id')->get()->toArray());
        Schema::dropIfExists(self::TABLE);
    }

    private function assertVerificationSchema(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'email', 'registration_edit_token_hash', 'registration_browser_token_hash',
            'email_verification_token', 'email_verification_expires_at', 'email_verification_last_sent_at',
            'registration_approved_at', 'registration_completed_at', 'completed_session_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing(self::TABLE));
        $indexes = Schema::getIndexes(self::TABLE);
        $this->assertCount(5, $indexes);
        foreach ($indexes as $index) $this->assertLessThanOrEqual(64, strlen($index['name']));
        foreach ([['id', true], ['email', false], ['registration_edit_token_hash', true],
            ['email_verification_token', true], ['created_at', false]] as [$column, $unique]) {
            $matching = array_values(array_filter($indexes, fn ($index) => $index['columns'] === [$column]));
            $this->assertCount(1, $matching, $column.' must have exactly one intended index');
            $this->assertSame($unique, $matching[0]['unique']);
        }
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_11_07_000001_make_customer_registration_verify_first.php');
    }
}
