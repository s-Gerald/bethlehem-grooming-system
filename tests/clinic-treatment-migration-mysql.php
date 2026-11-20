<?php

// Local-only migration smoke test. Never boots the app or reads its .env/database credentials.
require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

$schema = 'bethlehem_treatment_'.bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(16));
$created = false;
$exitCode = 0;
$server = null;
$check = function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};

try {
    $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $server->exec("CREATE TABLE `$schema`.`test_owner` (token CHAR(32) NOT NULL)");
    $owner = $server->prepare("INSERT INTO `$schema`.`test_owner` VALUES (?)");
    $owner->execute([$token]);
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => $schema, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    $container = $capsule->getContainer();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication($container);
    $check(DB::selectOne('SELECT DATABASE() AS name')->name === $schema, 'Wrong test schema.');

    Schema::create('vaccination_records', function (Blueprint $table) {
        $table->id(); $table->unsignedInteger('inventory_item_id')->nullable();
        $table->string('vaccine_name'); $table->date('administered_date');
        $table->decimal('dose_amount', 8, 3); $table->string('dose_unit'); $table->text('notes');
    });
    foreach ([301, null] as $itemId) DB::table('vaccination_records')->insert([
        'inventory_item_id' => $itemId, 'vaccine_name' => 'Original treatment', 'administered_date' => '2020-01-01',
        'dose_amount' => '0.8', 'dose_unit' => 'mL', 'notes' => 'Original notes',
    ]);
    $original = DB::table('vaccination_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $migration = require __DIR__.'/../database/migrations/2026_11_20_000001_add_explicit_treatment_source_and_manual_price.php';
    $migration->up();
    $check(DB::table('vaccination_records')->orderBy('id')->pluck('record_source')->all() === ['inventory', 'historical'], 'Unsafe legacy source backfill.');
    $check(DB::table('vaccination_records')->whereNotNull('manual_price')->count() === 0, 'Legacy prices were changed.');
    foreach ($original as $row) $check(DB::table('vaccination_records')->where($row)->exists(), 'Legacy clinical facts changed.');
    $price = DB::selectOne("SELECT NUMERIC_PRECISION AS p, NUMERIC_SCALE AS s, IS_NULLABLE AS n
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vaccination_records' AND COLUMN_NAME = 'manual_price'", [$schema]);
    $check((int) $price->p === 8 && (int) $price->s === 2 && $price->n === 'YES', 'Incorrect Manual price schema.');
    $migration->down();
    $check(DB::table('vaccination_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() === $original, 'Rollback changed legacy rows.');
    $migration->up();
    DB::table('vaccination_records')->where('id', 2)->update(['record_source' => 'manual', 'manual_price' => '10000.00']);
    $check(DB::table('vaccination_records')->where('id', 2)->value('manual_price') === '10000.00', 'Manual decimal did not round-trip.');
    echo 'Treatment source/price migration, legacy backfill, rollback and decimal snapshot passed on '.$server->query('SELECT VERSION()')->fetchColumn().".\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $exitCode = 1;
} finally {
    if ($created) {
        // Only this process's newly created, token-owned schema may be removed.
        $owned = preg_match('/^bethlehem_treatment_[a-f0-9]{12}$/D', $schema)
            && $server->query("SELECT token FROM `$schema`.`test_owner`")->fetchColumn() === $token;
        if ($owned) {
            $server->exec("DROP DATABASE `$schema`");
            echo "Disposable schema removed.\n";
        } else {
            fwrite(STDERR, "Schema ownership verification failed; cleanup refused.\n");
            $exitCode = 1;
        }
    }
}
exit($exitCode);
