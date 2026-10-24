<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QrPaymentSettingsTest extends TestCase
{
    private bool $fixtureDatabaseVerified = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Support/QrPaymentMysqlHarness.php';
        \Tests\Support\assertQrPaymentTestDatabase();
        $this->fixtureDatabaseVerified = true;
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method')->default('cash');
            $table->decimal('total_amount', 10, 2)->default(0);
        });
        (require database_path('migrations/2026_10_23_000001_create_qr_payments.php'))->up();
        (require database_path('migrations/2026_10_23_000003_configure_qr_payment_methods.php'))->up();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDatabaseVerified) {
            \Tests\Support\assertQrPaymentTestDatabase();
            foreach (['qr_payments', 'qr_payment_settings', 'payments', 'migrations'] as $table) Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_settings_require_admin_and_collection_requires_grooming_access(): void
    {
        $this->getJson('/api/admin/qr-payments/settings')->assertUnauthorized();
        $this->actor('staff');
        $this->getJson('/api/admin/qr-payments/settings')->assertForbidden();
        $this->postJson('/api/admin/qr-payments/settings', [])->assertForbidden();
        $this->getJson('/api/admin/qr-payments/config')->assertOk()->assertJsonPath('settings.enabled', false);
        $this->getJson('/api/admin/qr-payments/image')->assertNotFound();
        $this->actor('customer');
        $this->getJson('/api/admin/qr-payments/config')->assertForbidden();
        $this->getJson('/api/admin/qr-payments/image')->assertForbidden();
        $this->actor('staff', 'clinic');
        $this->getJson('/api/admin/qr-payments/config')->assertOk();
        $this->getJson('/api/admin/qr-payments/settings')->assertForbidden();
        $this->postJson('/api/admin/qr-payments/settings', [])->assertForbidden();
        auth()->user()->staff_subrole = 'veterinarian';
        $this->getJson('/api/admin/qr-payments/config')->assertForbidden();
    }

    public function test_enabled_settings_need_image_identity_and_explicit_official_qr_acknowledgment(): void
    {
        $this->actor();
        $this->postJson('/api/admin/qr-payments/settings', ['enabled' => true, 'version' => 1, 'accepted_methods' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['image', 'merchant_name', 'accepted_methods']);
        $payload = ['enabled' => true, 'merchant_name' => 'Bethlehem', 'accepted_methods' => ['GCash', 'Maya', 'Bank Transfer'], 'version' => 1];
        $this->postJson('/api/admin/qr-payments/settings', $payload)->assertUnprocessable();
        $this->post('/api/admin/qr-payments/settings', $payload + ['image' => $this->image()], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('qr_payment_settings', ['id' => 1, 'enabled' => false]);
    }

    public function test_incomplete_and_disabled_configuration_can_be_saved_without_becoming_available(): void
    {
        $this->actor();
        $this->getJson('/api/admin/qr-payments/settings')->assertJsonPath('settings.status', 'not_ready');
        $saved = $this->post('/api/admin/qr-payments/settings', [
            'enabled' => false, 'version' => 1, 'accepted_methods' => [], 'image' => $this->image(), 'official_qr_confirmed' => true,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('settings.status', 'not_ready')
            ->assertJsonPath('settings.missing_fields', ['merchant_name', 'accepted_methods']);
        $this->postJson('/api/admin/qr-payments/settings', ['enabled' => true, 'version' => 2, 'merchant_name' => 'Bethlehem', 'accepted_methods' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['accepted_methods']);
        $this->assertDatabaseHas('qr_payment_settings', ['version' => 2, 'enabled' => false]);
        $this->postJson('/api/admin/qr-payments/settings', [
            'enabled' => false, 'version' => 2, 'merchant_name' => 'Bethlehem', 'accepted_methods' => ['GCash'],
        ])->assertOk()->assertJsonPath('settings.status', 'disabled')->assertJsonPath('settings.missing_fields', []);
        $this->actor('staff');
        $this->getJson('/api/admin/qr-payments/config')->assertExactJson(['settings' => ['enabled' => false]]);
        $this->actor();
        $this->postJson('/api/admin/qr-payments/settings', [
            'enabled' => true, 'version' => 3, 'merchant_name' => 'Bethlehem', 'accepted_methods' => ['GCash'],
        ])->assertOk()->assertJsonPath('settings.status', 'enabled')->assertJsonPath('message', 'QR Payments enabled');
    }

    public function test_private_image_and_versioned_settings_survive_reloads_replace_and_disable(): void
    {
        $this->actor();
        $payload = ['enabled' => true, 'merchant_name' => 'Bethlehem', 'accepted_methods' => ['GCash', 'Maya', 'Bank Transfer'], 'version' => 1, 'official_qr_confirmed' => true];
        $saved = $this->post('/api/admin/qr-payments/settings', $payload + ['image' => $this->image()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('settings.version', 2)->assertJsonPath('settings.enabled', true)->json('settings');
        $this->assertArrayNotHasKey('image_path', $saved);
        $oldPath = DB::table('qr_payment_settings')->value('image_path');
        Storage::disk('local')->assertExists($oldPath);
        $this->getJson('/api/admin/qr-payments/settings')->assertOk()->assertJsonPath('settings.merchant_name', 'Bethlehem');
        $this->actor('staff');
        $this->getJson('/api/admin/qr-payments/config')->assertOk()->assertJsonPath('settings.version', 2);
        $this->get('/api/admin/qr-payments/image?version=2')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/admin/qr-payments/image?version=1')->assertStatus(409);
        $this->actor();
        $payload['version'] = 2;
        $this->post('/api/admin/qr-payments/settings', $payload + ['image' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($oldPath);
        $this->postJson('/api/admin/qr-payments/settings', $payload)->assertStatus(409);
        $payload['version'] = 3;
        $payload['enabled'] = false;
        $this->postJson('/api/admin/qr-payments/settings', $payload)->assertOk()->assertJsonPath('settings.enabled', false);
        unset($payload['official_qr_confirmed']);
        $payload['version'] = 4;
        $this->postJson('/api/admin/qr-payments/settings', $payload)->assertOk()->assertJsonPath('settings.version', 5);
        $this->actor('staff');
        $this->getJson('/api/admin/qr-payments/config')->assertJsonPath('settings.enabled', false);
        $this->get('/api/admin/qr-payments/image')->assertNotFound();
    }

    public function test_invalid_image_mime_size_and_dimensions_do_not_change_configuration(): void
    {
        $this->actor();
        $payload = ['version' => 1, 'enabled' => true, 'merchant_name' => 'Bethlehem', 'accepted_methods' => ['GCash'], 'official_qr_confirmed' => true];
        foreach ([UploadedFile::fake()->create('receipt.svg', 2, 'image/svg+xml'),
            UploadedFile::fake()->create('image.png', 3073, 'image/png'), $this->image(20)] as $file) {
            $this->post('/api/admin/qr-payments/settings', $payload + ['image' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseHas('qr_payment_settings', ['id' => 1, 'version' => 1, 'image_path' => null]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_method_defaults_persistence_and_validation(): void
    {
        // Exercise all invalid names without exhausting the endpoint's production rate limit.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actor();
        $this->getJson('/api/admin/qr-payments/settings')->assertOk()
            ->assertJsonPath('settings.accepted_methods', ['GCash', 'Maya', 'Bank Transfer']);
        foreach ([['Other'], ['Cash'], ['qrph'], ['GCash', 'gcash'], ['Maya', ' Maya '], [''], [['bad']], [23], [str_repeat('x', 101)]] as $methods) {
            $this->postJson('/api/admin/qr-payments/settings', ['version' => 1, 'enabled' => false, 'accepted_methods' => $methods])
                ->assertUnprocessable();
        }
        $this->postJson('/api/admin/qr-payments/settings', ['version' => 1, 'enabled' => false,
            'accepted_methods' => ['GCash', '  PayPal  ']])->assertOk()->assertJsonPath('settings.accepted_methods', ['GCash', 'PayPal']);
        $this->getJson('/api/admin/qr-payments/settings')->assertJsonPath('settings.accepted_methods', ['GCash', 'PayPal']);
        $this->postJson('/api/admin/qr-payments/settings', ['version' => 2, 'enabled' => false, 'accepted_methods' => '[]'])
            ->assertOk()->assertJsonPath('settings.accepted_methods', []);
        $this->getJson('/api/admin/qr-payments/settings')->assertJsonPath('settings.accepted_methods', []);
    }

    public function test_method_migration_preserves_historical_financial_and_audit_values_and_can_resume(): void
    {
        $migration = require database_path('migrations/2026_10_23_000003_configure_qr_payment_methods.php');
        $migration->down();
        DB::table('qr_payment_settings')->update(['version' => 7, 'merchant_name' => 'Existing account', 'provider_name' => 'Old bank']);
        DB::table('qr_payments')->insert(['payable_type' => \App\Models\Booking::class, 'payable_id' => 1,
            'external_reference' => 'OLD123456', 'received_amount' => 850, 'transaction_at' => '2026-10-22 09:00:00',
            'verified_at' => '2026-10-22 09:05:00', 'verified_by' => 7, 'verified_by_name' => 'Staff Verifier',
            'verification_source' => 'receiving_history', 'reference_unavailable_reason' => 'Retained audit note.',
            'settings_version' => 6, 'merchant_name' => 'Historical merchant', 'provider_name' => 'Historical bank', 'qr_image_hash' => hash('sha256', 'fixture')]);
        $before = (array) DB::table('qr_payments')->first();
        $migration->up();
        $migration->up();
        $after = (array) DB::table('qr_payments')->first();
        $this->assertNull($after['payment_source']);
        unset($after['payment_source']);
        $this->assertSame($before, $after);
        $this->assertFalse(Schema::hasColumn('qr_payment_settings', 'provider_name'));
        $this->assertDatabaseHas('qr_payment_settings', ['version' => 7, 'merchant_name' => 'Existing account']);
        $this->assertSame(['GCash', 'Maya', 'Bank Transfer'], \App\Models\QrPaymentSettings::findOrFail(1)->acceptedMethods());
        DB::table('qr_payment_settings')->update(['accepted_methods' => json_encode([])]);
        $migration->up();
        $this->assertSame([], \App\Models\QrPaymentSettings::findOrFail(1)->acceptedMethods());
    }

    public function test_proof_forward_migration_preserves_historical_reference_exceptions(): void
    {
        DB::table('qr_payments')->insert(['payable_type' => \App\Models\Booking::class, 'payable_id' => 1,
            'external_reference' => null, 'received_amount' => 850, 'transaction_at' => '2026-10-22 09:00:00',
            'verified_at' => '2026-10-22 09:05:00', 'verified_by' => 7, 'verified_by_name' => 'Staff Verifier',
            'verification_source' => 'receiving_history', 'reference_unavailable_reason' => 'Historical exception retained.',
            'settings_version' => 1, 'merchant_name' => 'Bethlehem', 'provider_name' => 'Old bank', 'qr_image_hash' => hash('sha256', 'fixture')]);
        (require database_path('migrations/2026_10_23_000002_add_qr_payment_proofs.php'))->up();
        $details = \App\Models\QrPayment::firstOrFail()->verificationDetails();
        $this->assertNull($details['external_reference']);
        $this->assertNull($details['proof']);
        $this->assertNull($details['paying_app']);
        $this->assertSame('Historical exception retained.', $details['reference_unavailable_reason']);
        $this->assertSame('2026-10-22 09:00:00', $details['transaction_at']);
    }

    private function actor(string $role = 'admin', string $type = 'grooming'): void
    {
        $user = new User;
        $user->forceFill(['user_id' => 7, 'role' => $role, 'staff_type' => $type,
            'staff_subrole' => $type === 'grooming' ? 'grooming_receptionist' : 'clinic_receptionist']);
        Sanctum::actingAs($user);
    }

    public function test_migration_resumes_partial_mysql_ddl_without_overwriting_settings_or_payments(): void
    {
        // MySQL commits the settings table before a later CREATE TABLE can fail.
        Schema::drop('qr_payments');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['qr_payment_id']);
            $table->dropColumn('qr_payment_id');
        });
        DB::table('qr_payment_settings')->where('id', 1)->update(['merchant_name' => 'Existing account', 'version' => 7]);
        DB::table('payments')->insert([
            ['id' => 11, 'payment_method' => 'cash', 'total_amount' => 850],
            ['id' => 12, 'payment_method' => 'online', 'total_amount' => 600],
        ]);
        $arguments = ['--path' => 'database/migrations/2026_10_23_000001_create_qr_payments.php', '--force' => true];
        $this->artisan('migrate', $arguments)->assertExitCode(0);
        $this->artisan('migrate', $arguments)->assertExitCode(0);
        $this->assertDatabaseHas('migrations', ['migration' => '2026_10_23_000001_create_qr_payments']);
        $this->assertDatabaseHas('qr_payment_settings', ['id' => 1, 'version' => 7, 'merchant_name' => 'Existing account']);
        $this->assertDatabaseCount('qr_payment_settings', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['id' => 11, 'payment_method' => 'cash', 'total_amount' => 850, 'qr_payment_id' => null]);
        $this->assertDatabaseHas('payments', ['id' => 12, 'payment_method' => 'online', 'total_amount' => 600, 'qr_payment_id' => null]);
    }

    public function test_unknown_receipt_time_is_nullable_and_recorded_dates_never_automatically_update(): void
    {
        $values = ['payable_type' => \App\Models\Booking::class, 'payable_id' => 1,
            'external_reference' => 'TIME123456', 'received_amount' => 850,
            'transaction_at' => '2026-10-22 13:14:00', 'verified_at' => '2026-10-23 15:20:30',
            'verification_source' => 'receiving_history', 'verified_by' => 7, 'verified_by_name' => 'Staff Verifier',
            'settings_version' => 1, 'merchant_name' => 'Bethlehem', 'provider_name' => 'Bank', 'qr_image_hash' => hash('sha256', 'fixture')];
        $qr = \App\Models\QrPayment::create($values);
        $before = $qr->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_23_000004_allow_unknown_qr_transaction_time.php');
        $migration->up();
        $this->assertSame($before, $qr->fresh()->getAttributes(), 'Forward migration preserves historical data');
        if (DB::getDriverName() === 'mysql') {
            $columns = collect(DB::select("SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable,
                COLUMN_DEFAULT AS default_value, EXTRA AS extra FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_payments' AND COLUMN_NAME IN ('transaction_at', 'verified_at')"))->keyBy('name');
            foreach (['transaction_at', 'verified_at'] as $field) {
                $this->assertSame('datetime', $columns[$field]->type);
                $this->assertSame($field === 'transaction_at' ? 'YES' : 'NO', $columns[$field]->nullable);
                if ($field === 'transaction_at') $this->assertContains($columns[$field]->default_value, [null, 'NULL']);
                else $this->assertNull($columns[$field]->default_value);
                $this->assertStringNotContainsString('on update', strtolower($columns[$field]->extra));
            }
            $settingsDates = DB::select("SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, EXTRA AS extra
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_payment_settings'
                AND COLUMN_NAME IN ('created_at', 'updated_at')");
            $this->assertCount(2, $settingsDates);
            foreach ($settingsDates as $date) {
                $this->assertSame('YES', $date->nullable);
                $this->assertStringNotContainsString('on update', strtolower($date->extra));
            }
            echo "\nQR MySQL schema proof: ".json_encode([
                'database' => DB::connection()->getDatabaseName(), 'columns' => $columns->values(),
                'session' => DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode, @@GLOBAL.explicit_defaults_for_timestamp AS explicit_defaults'),
            ], JSON_THROW_ON_ERROR).PHP_EOL;
        }
        $incomplete = $values;
        unset($incomplete['verified_at']);
        $incomplete['payable_id'] = 3;
        $incomplete['external_reference'] = 'MISSING123456';
        try {
            DB::table('qr_payments')->insert($incomplete);
            $this->fail('verified_at must be explicitly supplied.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertContains($error->getCode(), ['23000', 'HY000']);
        }
        $unknown = $values;
        unset($unknown['transaction_at']);
        $unknown['payable_id'] = 2;
        $unknown['external_reference'] = 'UNKNOWN123456';
        $unknownQr = \App\Models\QrPayment::create($unknown);
        $migration->down();
        $this->assertNull($unknownQr->fresh()->transaction_at);
        $this->assertNull($unknownQr->fresh()->verificationDetails()['transaction_at']);
        $this->assertSame('2026-10-22 13:14:00', $qr->fresh()->transaction_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 15:20:30', $qr->fresh()->verified_at->format('Y-m-d H:i:s'));
        $qr->update(['verified_by_name' => 'Updated verifier name']);
        $this->assertSame('2026-10-22 13:14:00', $qr->fresh()->transaction_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 15:20:30', $qr->fresh()->verified_at->format('Y-m-d H:i:s'));
        $this->assertNotNull(\App\Models\QrPaymentSettings::findOrFail(1)->created_at);
    }

    private function image(int $size = 256): UploadedFile
    {
        // A controlled valid PNG; this verifies image validation, not bank ownership.
        $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\xff", $size * 3), $size)))
            .$chunk('IEND', '');
        return UploadedFile::fake()->createWithContent('official.png', $bytes);
    }
}
