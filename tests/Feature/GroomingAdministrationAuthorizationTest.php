<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WalkinController;
use App\Models\BookingPet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroomingAdministrationAuthorizationTest extends TestCase
{
    use \Tests\Support\CreatesPetGroomers;
    use \Tests\Support\SubmitsSignedGrooming;

    private bool $fixtureDatabaseVerified = false;

    private const FORBIDDEN_RESPONSE = [
        'success' => false,
        'message' => 'Forbidden. You do not have permission to access this resource.',
    ];

    private const GROOMING_ADMIN_ROUTES = [
        ['GET', 'api/admin/dashboard/summary'],
        ['GET', 'api/admin/dashboard/activity'],
        ['GET', 'api/admin/grooming/activity'],
        ['GET', 'api/admin/notifications'],
        ['PATCH', 'api/admin/notifications/read-all'],
        ['PATCH', 'api/admin/notifications/{id}/read'],
        ['GET', 'api/admin/bookings'],
        ['GET', 'api/admin/bookings/archived'],
        ['POST', 'api/admin/bookings/{id}/sedation-consent'],
        ['GET', 'api/admin/bookings/{id}/signed-consent'],
        ['PATCH', 'api/admin/clinic/settings/groomers-on-duty'],
        ['POST', 'api/admin/pet-groomers'],
        ['PATCH', 'api/admin/pet-groomers/{id}'],
        ['POST', 'api/admin/bookings/{id}/check-in'],
        ['PATCH', 'api/admin/bookings/{id}/internal-staff-note'],
        ['PATCH', 'api/admin/bookings/{id}/pets/{bookingPetId}/grooming-visit-notes'],
        ['POST', 'api/admin/bookings/{id}/revert-check-in'],
        ['POST', 'api/admin/bookings/{id}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/revert-start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/start-grooming'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/undo-start'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/cancel'],
        ['POST', 'api/admin/bookings/{id}/pets/{bookingPetId}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/mark-done'],
        ['POST', 'api/admin/bookings/{id}/cancel'],
        ['POST', 'api/admin/bookings/{id}/archive'],
        ['POST', 'api/admin/bookings/{id}/picked-up'],
        ['POST', 'api/admin/bookings/{id}/pay'],
        ['POST', 'api/admin/bookings/{id}/pay-now'],
        ['POST', 'api/admin/bookings/{id}/online-payment'],
        ['GET', 'api/admin/bookings/{id}/online-payment'],
        ['DELETE', 'api/admin/bookings/{id}/online-payment'],
        ['POST', 'api/admin/bookings/{id}/release'],
        ['GET', 'api/admin/transactions'],
        ['POST', 'api/admin/walk-in'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            require_once __DIR__.'/../Support/CapacityMysqlHarness.php';
            \Tests\Support\assertCapacityConnection(DB::connection());
        } elseif (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('This fixture requires in-memory SQLite or the isolated MySQL capacity runner.');
        }
        $this->fixtureDatabaseVerified = true;
        $this->createPetGroomers();

        Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00'));
        if (DB::getDriverName() === 'mysql' && str_starts_with(DB::connection()->getDatabaseName(), 'bethlehem_capacity_')) {
            // This fixture omits inventory tables referenced by the unrelated POS schema.
            Schema::disableForeignKeyConstraints();
        }

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
            $table->string('password_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('account_deleted_at')->nullable();
        });

        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('grooming_cage_capacity')->default(20);
            $table->id();
            $table->unsignedTinyInteger('groomers_on_duty')->default(2);
            $table->time('clinic_open_time')->default('08:00:00');
            $table->time('clinic_close_time')->default('17:00:00');
            $table->time('clinic_prereg_cutoff_time')->default('14:00:00');
            $table->time('grooming_open_time')->default('08:00:00');
            $table->time('grooming_close_time')->default('17:00:00');
            $table->time('grooming_prereg_cutoff_time')->default('14:00:00');
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
        ]);

        Schema::create('clinic_closures', function (Blueprint $table) {
            $table->string('service', 16)->nullable();
            $table->id();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('max_slots')->default(4);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('walkins', function (Blueprint $table) {
            $table->id();
            $table->string('fname');
            $table->string('lname');
            $table->string('mname')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->boolean('sedation_consent')->default(false);
            $table->boolean('terms_agreed')->default(false);
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('appointment_type')->default('grooming');
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->time('arrival_end_time')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('booking_type')->default('online');
            $table->string('status');
            $table->integer('queue_number')->nullable();
            $table->text('special_notes')->nullable();
            $table->text('internal_staff_note')->nullable();
            $table->boolean('sedation_consent')->default(false);
            $table->string('sedation_consent_source', 30)->nullable();
            $table->unsignedInteger('sedation_consent_recorded_by')->nullable();
            $table->dateTime('sedation_consent_recorded_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->boolean('paid')->default(false);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->date('birthdate')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->string('fur_type')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->string('grooming_preference')->nullable();
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('registered_size')->nullable();
            $table->string('confirmed_size')->nullable();
            $table->date('pet_queue_date')->nullable();
            $table->unsignedInteger('pet_queue_number')->nullable();
            $table->text('special_instructions')->nullable();
            $table->unsignedInteger('groomer_id')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20)->default(
                BookingPet::GROOMING_STATE_NOT_STARTED,
            );
            $table->unique(['pet_queue_date', 'pet_queue_number']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price', 8, 2)->default(0);
            $table->decimal('price_small', 8, 2)->nullable();
            $table->decimal('price_medium', 8, 2)->nullable();
            $table->decimal('price_large', 8, 2)->nullable();
            $table->decimal('price_extra_large', 8, 2)->nullable();
            $table->json('starting_price_sizes')->nullable();
            $table->json('range_price_maximums')->nullable();
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->boolean('is_starting_price')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->unsignedInteger('addon_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
            $table->decimal('price_min_at_booking', 8, 2)->nullable();
            $table->decimal('price_max_at_booking', 8, 2)->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status');
            $table->text('notes')->nullable();
            $table->unsignedInteger('processed_by')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('grooming_payment_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedInteger('item_id');
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('price_at_sale', 8, 2);
            $table->decimal('subtotal', 10, 2);
        });

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount')->default(0);
            $table->decimal('cash_received')->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedInteger('paid_by_user_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->text('notes')->nullable();
        });

        (require database_path('migrations/2026_07_05_100002_create_pos_transactions_table.php'))->up();
        (require database_path('migrations/2026_07_05_100003_create_pos_transaction_items_table.php'))->up();
        (require database_path('migrations/2026_10_07_100000_add_product_snapshot_to_pos_items.php'))->up();

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type');
            $table->unsignedInteger('booking_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        DB::table('services')->insert(['service_id' => 90, 'service_name' => 'Partial Grooming', 'slug' => 'partial_grooming',
            'base_price' => 250, 'price_small' => 250, 'price_medium' => 250, 'price_large' => 250, 'price_extra_large' => 250]);

        DB::table('services')->insert([
            'service_id' => 1,
            'service_name' => 'Basic Grooming',
            'slug' => 'basic-grooming',
            'base_price' => 500,
            'is_active' => true,
        ]);
        (require database_path('migrations/2026_10_16_000001_add_grooming_cancellation_audit.php'))->up();
        (require database_path('migrations/2026_10_16_000005_create_grooming_consents.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_haircut_requested_to_booking_pets.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (! $this->fixtureDatabaseVerified) { parent::tearDown(); return; }
        Schema::dropIfExists('pos_transaction_items');
        Schema::dropIfExists('pos_transactions');

        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('grooming_payment_products');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('grooming_consents');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('users');

        Schema::dropIfExists('pet_groomers');
        parent::tearDown();
    }

    #[DataProvider('unauthenticatedGroomingRoutes')]
    public function test_unauthenticated_visitors_cannot_access_grooming_administration(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->json($method, $uri, $payload)
            ->assertUnauthorized();
    }

    public static function unauthenticatedGroomingRoutes(): array
    {
        return [
            'dashboard summary' => ['GET', '/api/admin/dashboard/summary'],
            'dashboard activity' => ['GET', '/api/admin/dashboard/activity'],
            'sidebar activity' => ['GET', '/api/admin/grooming/activity'],
            'queue listing' => ['GET', '/api/admin/bookings'],
            'add pet groomer' => ['POST', '/api/admin/pet-groomers', ['first_name' => 'Anna']],
            'change pet groomer' => ['PATCH', '/api/admin/pet-groomers/1', ['on_duty' => true]],
            'edit internal staff note' => ['PATCH', '/api/admin/bookings/1/internal-staff-note', ['internal_staff_note' => 'private']],
            'edit grooming visit notes' => ['PATCH', '/api/admin/bookings/1/pets/1/grooming-visit-notes', ['grooming_visit_notes' => 'private']],
            'record sedation consent' => ['POST', '/api/admin/bookings/1/sedation-consent'],
            'signed agreement' => ['GET', '/api/admin/bookings/1/signed-consent'],
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'undo pet start' => ['POST', '/api/admin/bookings/1/pets/1/undo-start'],
            'cancel pet' => ['POST', '/api/admin/bookings/1/pets/1/cancel'],
            'start grooming' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish grooming' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
            'record grooming payment' => ['POST', '/api/admin/bookings/1/pay', [
                'final_price' => 500,
                'amount_paid' => 500,
            ]],
        ];
    }

    #[DataProvider('customerForbiddenGroomingRoutes')]
    public function test_customers_receive_the_standard_forbidden_response_for_every_grooming_admin_route(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->authenticateAs('customer');

        $this->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public static function customerForbiddenGroomingRoutes(): array
    {
        return [
            'dashboard summary' => ['GET', '/api/admin/dashboard/summary'],
            'dashboard activity' => ['GET', '/api/admin/dashboard/activity'],
            'sidebar activity' => ['GET', '/api/admin/grooming/activity'],
            'staff notifications' => ['GET', '/api/admin/notifications'],
            'mark all staff notifications read' => ['PATCH', '/api/admin/notifications/read-all'],
            'mark staff notification read' => ['PATCH', '/api/admin/notifications/1/read'],
            'queue and incoming listing' => ['GET', '/api/admin/bookings'],
            'archived grooming listing' => ['GET', '/api/admin/bookings/archived'],
            'check in booking' => ['POST', '/api/admin/bookings/1/check-in'],
            'edit internal staff note' => ['PATCH', '/api/admin/bookings/1/internal-staff-note', ['internal_staff_note' => 'private']],
            'edit grooming visit notes' => ['PATCH', '/api/admin/bookings/1/pets/1/grooming-visit-notes', ['grooming_visit_notes' => 'private']],
            'record sedation consent' => ['POST', '/api/admin/bookings/1/sedation-consent'],
            'signed agreement' => ['GET', '/api/admin/bookings/1/signed-consent'],
            'revert check in' => ['POST', '/api/admin/bookings/1/revert-check-in'],
            'start whole booking' => ['POST', '/api/admin/bookings/1/start-grooming'],
            'revert grooming start' => ['POST', '/api/admin/bookings/1/revert-start-grooming'],
            'undo pet start' => ['POST', '/api/admin/bookings/1/pets/1/undo-start'],
            'cancel pet' => ['POST', '/api/admin/bookings/1/pets/1/cancel'],
            'start one pet' => ['POST', '/api/admin/bookings/1/pets/1/start-grooming'],
            'finish one pet' => ['POST', '/api/admin/bookings/1/pets/1/mark-done'],
            'finish whole booking' => ['POST', '/api/admin/bookings/1/mark-done'],
            'staff cancellation' => ['POST', '/api/admin/bookings/1/cancel'],
            'archive booking' => ['POST', '/api/admin/bookings/1/archive'],
            'mark picked up' => ['POST', '/api/admin/bookings/1/picked-up'],
            'record final payment' => ['POST', '/api/admin/bookings/1/pay'],
            'record early payment' => ['POST', '/api/admin/bookings/1/pay-now'],
            'release paid booking' => ['POST', '/api/admin/bookings/1/release'],
            'grooming transaction listing' => ['GET', '/api/admin/transactions'],
            'create grooming walk-in' => ['POST', '/api/admin/walk-in'],
        ];
    }

    #[DataProvider('customerSedationConsentCases')]
    public function test_customer_grooming_pre_registration_saves_optional_sedation_consent(
        bool $accepted,
        ?string $expectedSource,
        bool $expectsRecordedAt,
        bool $phoneEnforced = false,
    ): void {
        DB::table('services')->insert(['slug' => 'cat_full_grooming', 'service_name' => 'Cat Full Grooming', 'base_price' => 500]);
        $this->authenticateAs('customer');
        if ($phoneEnforced) {
            config(['services.sms_verification.available' => true, 'services.sms_verification.enforced' => true]);
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable());
            DB::table('users')->insert(['user_id' => 3, 'role' => 'customer', 'phone' => '09171234567', 'phone_verified_at' => now()]);
            Sanctum::actingAs(User::findOrFail(3));
        }
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $response = $this->submitSignedGrooming('/api/booking/store', [
            'number_of_pets' => 1,
            'sedation_consent' => $accepted,
            'pets' => [[
                'pet_name' => 'Mochi',
                'species' => 'cat', 'services' => ['package' => 'cat_full_grooming'],
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'booking_id' => $response->json('booking.booking_id'),
            'sedation_consent' => $accepted,
            'sedation_consent_source' => $expectedSource,
            'sedation_consent_recorded_by' => null,
        ]);
        $recordedAt = DB::table('bookings')->value('sedation_consent_recorded_at');
        if ($expectsRecordedAt) {
            $this->assertNotNull($recordedAt);
        } else {
            $this->assertNull($recordedAt);
        }
    }

    #[DataProvider('haircutSubmissionCases')]
    public function test_haircut_instructions_and_visit_flag_are_enforced_in_both_flows(bool $walkin, string $package, ?string $preference, bool $haircut, ?string $notes, ?string $error): void
    {
        $this->authenticateAs($walkin ? 'staff' : 'customer');
        DB::table('services')->insert(['slug' => $package, 'service_name' => $package, 'base_price' => 500]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11 AM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $pet = ['pet_name' => 'Helio', 'species' => $package === 'cat_full_grooming' ? 'cat' : 'dog',
            'size' => 'small', 'groomer_id' => 1, 'grooming_preference' => $preference,
            'haircut_requested' => $haircut, 'special_instructions' => $notes,
            'services' => $walkin ? [['service_slug' => $package]] : ['package' => $package]];
        $response = $this->submitSignedGrooming($walkin ? '/api/admin/walk-in' : '/api/booking/store',
            $walkin ? ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567', 'pets' => [$pet]]
                : ['booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1, 'pets' => [$pet]]);
        if ($error) {
            $response->assertUnprocessable()->assertJsonValidationErrors('pets.0.'.$error);
            $this->assertDatabaseCount('bookings', 0);
        } else {
            $response->assertCreated();
            $this->assertDatabaseHas('booking_pets', ['haircut_requested' => $haircut]);
            $saved = BookingPet::firstOrFail();
            $this->assertSame($haircut, $saved->haircut_requested);
            $this->assertSame($preference, $saved->grooming_preference);
            $this->assertSame($haircut, $response->json($walkin ? 'pets.0.haircut_requested' : 'booking.pets.0.haircut_requested'));
        }
    }

    public static function haircutSubmissionCases(): array
    {
        $cases = [];
        foreach ([false, true] as $walkin) {
            foreach ([null, '', '   ', "\t\n", "\u{00a0}"] as $notes) {
                $cases[] = [$walkin, 'deluxe_dog_grooming', 'custom_hairstyle', false, $notes, 'special_instructions'];
                $cases[] = [$walkin, 'cat_full_grooming', null, true, $notes, 'special_instructions'];
            }
            $cases[] = [$walkin, 'cat_full_grooming', null, true, 'Trim the body, leave the tail fluffy.', null];
            $cases[] = [$walkin, 'cat_full_grooming', null, false, 'Sensitive skin', null];
            $cases[] = [$walkin, 'deluxe_dog_grooming', 'puppy_cut', false, null, null];
            $cases[] = [$walkin, 'deluxe_dog_grooming', 'custom_hairstyle', false, 'Short body, rounded face', null];
            $cases[] = [$walkin, 'deluxe_dog_grooming', 'puppy_cut', true, 'Some notes', 'haircut_requested'];
        }
        return $cases;
    }

    public static function customerSedationConsentCases(): array
    {
        return [
            'unchecked' => [false, null, false],
            'checked' => [true, 'customer_online', true],
            'verified phone unchecked' => [false, null, false, true],
            'verified phone checked' => [true, 'customer_online', true, true],
        ];
    }

    public function test_pre_registration_reevaluates_profile_size_from_valid_weight(): void
    {
        $this->authenticateAs('customer');
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 3,
            'user_id' => 3,
            'pet_name' => 'Rigby',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('services')->insert([
            'service_id' => 3,
            'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming',
            'base_price' => 0,
        ]);

        $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_id' => 3,
                'pet_name' => 'Rigby',
                'species' => 'dog',
                'weight' => 12,
                'size' => 'medium',
                'grooming_preference' => 'regular_trim',
                'services' => ['package' => 'regular_dog_grooming'],
            ]],
        ])->assertCreated();

        $this->assertDatabaseHas('pets', ['pet_id' => 3, 'size' => 'medium', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', ['pet_id' => 3, 'registered_size' => 'medium']);
        $this->assertDatabaseHas('booking_services', ['service_id' => 3, 'price_at_booking' => 650]);
    }

    #[DataProvider('decimalPetWeights')]
    public function test_booking_and_walkin_persist_the_shared_decimal_size(string $species, mixed $weight, string $size): void
    {
        DB::table('services')->insert(['service_id' => 900, 'service_name' => 'Nail Clipping', 'slug' => 'nail_clipping', 'base_price' => 75]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $pet = ['pet_name' => 'Decimal pet', 'species' => $species, 'weight' => $weight,
            'size' => $size === 'small' ? 'medium' : 'small'];
        $this->authenticateAs('customer');
        $bookingId = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [[...$pet, 'services' => ['ala_carte' => ['nail_clipping']]]],
        ])->assertCreated()->json('booking.booking_id');
        $this->assertDatabaseHas('pets', ['pet_name' => 'Decimal pet', 'weight' => $weight, 'size' => $size]);
        $this->assertDatabaseHas('booking_services', ['booking_id' => $bookingId, 'service_id' => 900, 'price_at_booking' => 0]);
        $this->authenticateAs('staff');
        $walkinId = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [[...$pet, 'pet_name' => 'Walkin decimal', 'groomer_id' => 1, 'services' => [['service_slug' => 'nail_clipping']]]],
            'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');
        $this->assertDatabaseHas('pets', ['pet_name' => 'Walkin decimal', 'weight' => $weight, 'size' => $size]);
        $this->assertDatabaseHas('booking_services', ['booking_id' => $walkinId, 'service_id' => 900, 'price_at_booking' => 0]);
    }

    public static function decimalPetWeights(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/weight-rules.json'), true)['pets'];
    }

    public function test_invalid_weight_text_is_rejected_by_booking_and_grooming_walkin(): void
    {
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11 AM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $invalid = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true)['invalid_weights'];
        foreach ($invalid as $weight) {
            $pet = ['pet_name' => 'Invalid decimal', 'species' => 'Dog', 'weight' => $weight];
            $this->authenticateAs('customer');
            $this->submitSignedGrooming('/api/booking/store', ['booking_date' => now()->toDateString(), 'window_id' => 1,
                'number_of_pets' => 1, 'pets' => [$pet]])->assertUnprocessable()->assertJsonValidationErrors('pets.0.weight');
            foreach (['admin', 'staff'] as $role) {
                $this->authenticateAs($role);
                $this->submitSignedGrooming('/api/admin/walk-in', ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
                    'pets' => [[...$pet, 'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']]]], 'sedation_consent' => false, 'terms_agreed' => true])
                    ->assertUnprocessable()->assertJsonValidationErrors('pets.0.weight');
            }
        }
        $this->assertDatabaseCount('pets', 0);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_unchanged_historical_weight_is_usable_for_customer_booking(): void
    {
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 1, 'pet_name' => 'Historical cat',
            'species' => 'Cat', 'weight' => 4.999, 'size' => 'small', 'is_archived' => false]);
        DB::table('services')->insert(['service_id' => 900, 'service_name' => 'Nail Clipping', 'slug' => 'nail_clipping', 'base_price' => 75]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11 AM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $pet = ['pet_id' => 101, 'pet_name' => 'Historical cat', 'species' => 'Cat', 'size' => 'small',
            'services' => ['ala_carte' => ['nail_clipping']]];
        $this->authenticateAs('customer');
        $this->submitSignedGrooming('/api/booking/store', ['booking_date' => now()->toDateString(), 'window_id' => 1,
            'number_of_pets' => 1, 'pets' => [$pet]])->assertCreated();
        $this->assertDatabaseHas('pets', ['pet_id' => 101, 'weight' => 4.999, 'size' => 'small']);
    }

    public function test_grooming_walkin_can_select_a_historical_pet_without_changing_its_weight(): void
    {
        DB::table('users')->insert(['user_id' => 10, 'first_name' => 'Maria', 'last_name' => 'Santos',
            'phone' => '09171234567', 'role' => 'customer']);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Historical cat',
            'species' => 'Cat', 'weight' => 4.999, 'size' => 'small', 'is_archived' => false]);
        DB::table('services')->insert(['service_id' => 900, 'service_name' => 'Nail Clipping', 'slug' => 'nail_clipping', 'base_price' => 75]);
        $this->authenticateAs('staff');
        $this->submitSignedGrooming('/api/admin/walk-in', ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'owner_record_type' => 'registered', 'customer_user_id' => 10,
            'pets' => [['pet_id' => 101, 'pet_name' => 'Historical cat', 'species' => 'Cat', 'size' => 'small',
                'groomer_id' => 1, 'services' => [['service_slug' => 'nail_clipping']]]], 'sedation_consent' => false, 'terms_agreed' => true])->assertCreated();
        $this->assertDatabaseHas('pets', ['pet_id' => 101, 'weight' => 4.999, 'size' => 'small']);
    }

    public function test_customer_and_walk_in_range_prices_stay_unresolved_until_payment(): void
    {
        DB::table('services')->insert([
            'service_id' => 4,
            'service_name' => 'Nail Clipping',
            'slug' => 'nail_clipping',
            'base_price' => 75,
            'price_min' => 50,
            'price_max' => 100,
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->authenticateAs('customer');
        $customerBookingId = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_name' => 'Mochi',
                'species' => 'cat',
                'weight' => 4,
                'services' => ['ala_carte' => ['nail_clipping']],
            ]],
        ])->assertCreated()->json('booking.booking_id');

        $this->authenticateAs('staff');
        $walkinBookingId = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'weight' => 8,
                'groomer_id' => 1, 'services' => [['service_slug' => 'nail_clipping']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');

        foreach ([$customerBookingId, $walkinBookingId] as $bookingId) {
            $this->assertDatabaseHas('booking_services', [
                'booking_id' => $bookingId,
                'price_at_booking' => 0,
                'price_min_at_booking' => 50,
                'price_max_at_booking' => 100,
            ]);
        }

        $listing = $this->getJson('/api/admin/bookings')->assertOk();
        $incoming = collect($listing->json('incomingList'))->firstWhere('id', $customerBookingId);
        $queued = collect($listing->json('queuedList'))->firstWhere('id', $walkinBookingId);
        $this->assertEquals(50, $incoming['services'][0]['priceMinAtBooking']);
        $this->assertEquals(100, $incoming['services'][0]['priceMaxAtBooking']);
        $this->assertEquals(0, $incoming['services'][0]['priceAtBooking']);
        $this->assertEquals(50, $queued['services'][0]['priceMinAtBooking']);
        $this->assertEquals(100, $queued['services'][0]['priceMaxAtBooking']);
        $this->assertEquals(0, $queued['services'][0]['priceAtBooking']);

        DB::table('services')->where('service_id', 4)->update(['price_min' => 60, 'price_max' => 70]);
        $walkinLineId = DB::table('booking_services')->where('booking_id', $walkinBookingId)->value('booking_service_id');
        $this->postJson("/api/admin/bookings/{$walkinBookingId}/pay-now", [
            'final_price' => 80,
            'amount_paid' => 80,
            'service_prices' => [['booking_service_id' => $walkinLineId, 'amount' => 80]],
        ])->assertOk();
        $this->assertDatabaseHas('booking_services', [
            'booking_service_id' => $walkinLineId,
            'price_at_booking' => 80,
            'price_min_at_booking' => 50,
            'price_max_at_booking' => 100,
        ]);
        $this->assertDatabaseHas('payments', ['booking_id' => $walkinBookingId, 'total_amount' => 80]);

        DB::table('bookings')->where('booking_id', $customerBookingId)->update(['status' => 'for_payment']);
        DB::table('booking_pets')->where('booking_id', $customerBookingId)->update([
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            'grooming_end_time' => now(),
        ]);
        $customerLineId = DB::table('booking_services')->where('booking_id', $customerBookingId)->value('booking_service_id');
        $this->postJson("/api/admin/bookings/{$customerBookingId}/pay", [
            'final_price' => 101,
            'amount_paid' => 101,
            'service_prices' => [['booking_service_id' => $customerLineId, 'amount' => 101]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        $this->assertDatabaseHas('booking_services', ['booking_service_id' => $customerLineId, 'price_at_booking' => 0]);

        $this->postJson("/api/admin/bookings/{$customerBookingId}/pay", [
            'final_price' => 100,
            'amount_paid' => 100,
            'service_prices' => [['booking_service_id' => $customerLineId, 'amount' => 100]],
        ])->assertOk();
        $this->assertDatabaseHas('booking_services', [
            'booking_service_id' => $customerLineId,
            'price_at_booking' => 100,
            'price_min_at_booking' => 50,
            'price_max_at_booking' => 100,
        ]);

        $paidListing = $this->getJson('/api/admin/bookings')->assertOk();
        $this->assertEquals(80, collect($paidListing->json('queuedList'))->firstWhere('id', $walkinBookingId)['services'][0]['priceAtBooking']);
        $this->assertEquals(100, collect($paidListing->json('releasedList'))->firstWhere('id', $customerBookingId)['services'][0]['priceAtBooking']);
        $transactions = collect($this->getJson('/api/admin/transactions')->assertOk()->json('transactions'));
        $this->assertEquals(80, $transactions->firstWhere('bookingId', $walkinBookingId)['finalPrice']);
        $this->assertEquals(100, $transactions->firstWhere('bookingId', $customerBookingId)['finalPrice']);
    }

    #[DataProvider('livePricingModes')]
    public function test_updated_catalogue_propagates_to_new_customer_and_walk_in_snapshots(string $type, int $amount, ?int $maximum): void
    {
        DB::table('services')->insert(['service_id' => 4, 'service_name' => 'Nail Clipping',
            'slug' => 'nail_clipping', 'base_price' => 75, 'price_min' => 50, 'price_max' => 100]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $this->authenticateAs('admin');
        $this->patchJson('/api/admin/grooming/services/4/pricing', [
            'pricing_type' => $type, 'amount' => (string) $amount, 'maximum' => $maximum,
        ])->assertOk();
        $this->authenticateAs('customer');
        $customerId = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Mochi', 'species' => 'cat', 'weight' => 4,
                'services' => ['ala_carte' => ['nail_clipping']]]],
        ])->assertCreated()->json('booking.booking_id');
        $this->authenticateAs('staff');
        $walkinId = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Bantay', 'species' => 'dog', 'weight' => 8,
                'groomer_id' => 1, 'services' => [['service_slug' => 'nail_clipping', 'price' => 1]]]],
            'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');
        $this->assertSame(
            (array) DB::table('booking_pets')->where('booking_id', $customerId)->first(['grooming_preference']),
            (array) DB::table('booking_pets')->where('booking_id', $walkinId)->first(['grooming_preference']),
        );
        foreach ([$customerId, $walkinId] as $id) {
            $this->assertDatabaseHas('booking_services', ['booking_id' => $id,
                'price_at_booking' => $type === 'range' ? 0 : $amount,
                'price_min_at_booking' => $amount,
                'price_max_at_booking' => $type === 'fixed' ? $amount : $maximum]);
        }
        $before = DB::table('booking_services')->get()->toArray();
        $this->authenticateAs('admin');
        $this->patchJson('/api/admin/grooming/services/4/pricing', ['pricing_type' => 'fixed', 'amount' => '300'])->assertOk();
        $this->assertEquals($before, DB::table('booking_services')->get()->toArray());
        $line = DB::table('booking_services')->where('booking_id', $walkinId)->first();
        $invalid = $type === 'fixed' ? $amount + 1 : ($type === 'range' ? $maximum + 1 : $amount + 201);
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $invalid, 'amount_paid' => $invalid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $invalid]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $amount, 'amount_paid' => $amount,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $amount]],
        ])->assertOk();
    }

    public static function livePricingModes(): array
    {
        return [['fixed', 200, 200], ['range', 65, 125], ['starting_at', 175, null]];
    }

    #[DataProvider('updatedPackageModes')]
    public function test_updated_package_prices_propagate_and_payment_enforces_size_rules(string $size, int $weight, string $type, int $amount, ?int $threshold, ?int $maximum = null): void
    {
        DB::table('services')->insert(['service_id' => 4, 'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming', 'base_price' => 650]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $this->authenticateAs('admin');
        $sizes = [];
        foreach (['small' => 600, 'medium' => 700, 'large' => 950, 'extra_large' => 1150] as $key => $value) {
            $sizes[$key] = ['pricing_type' => in_array($key, ['large', 'extra_large']) ? 'starting_at' : 'fixed', 'amount' => (string) $value];
        }
        $sizes[$size]['pricing_type'] = $type;
        if ($type === 'range') $sizes[$size]['maximum'] = (string) $maximum;
        $this->patchJson('/api/admin/grooming/services/4/pricing', ['sizes' => $sizes])->assertOk();
        $this->authenticateAs('customer');
        $customerId = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Rigby', 'species' => 'dog', 'weight' => $weight,
                'grooming_preference' => 'regular_trim',
                'services' => ['package' => 'regular_dog_grooming']]],
        ])->assertCreated()->json('booking.booking_id');
        $this->authenticateAs('staff');
        $walkinId = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Bantay', 'species' => 'dog', 'weight' => $weight,
                'grooming_preference' => 'regular_trim',
                    'groomer_id' => 1, 'services' => [['service_slug' => 'regular_dog_grooming', 'price' => 1]]]],
            'sedation_consent' => false, 'terms_agreed' => true,
        ])->assertCreated()->json('booking_id');
        $this->assertSame(
            (array) DB::table('booking_pets')->where('booking_id', $customerId)->first(['grooming_preference']),
            (array) DB::table('booking_pets')->where('booking_id', $walkinId)->first(['grooming_preference']),
        );
        foreach ([$customerId, $walkinId] as $id) {
            $this->assertDatabaseHas('booking_services', ['booking_id' => $id, 'price_at_booking' => $type === 'range' ? 0 : $amount,
                'price_min_at_booking' => $amount, 'price_max_at_booking' => $type === 'fixed' ? $amount : $maximum]);
        }
        if ($type === 'range') {
            $before = DB::table('booking_services')->get()->toArray();
            $this->authenticateAs('admin');
            $sizes[$size]['maximum'] = (string) ($maximum + 100);
            $this->patchJson('/api/admin/grooming/services/4/pricing', ['sizes' => $sizes])->assertOk();
            $this->assertEquals($before, DB::table('booking_services')->get()->toArray());
        }
        $line = DB::table('booking_services')->where('booking_id', $walkinId)->first();
        $invalid = $type === 'fixed' ? $amount + 1 : ($type === 'range' ? $maximum + 1 : $amount + 501);
        $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $invalid, 'amount_paid' => $invalid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $invalid]],
        ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        if ($type === 'range') {
            $below = $amount - 1;
            $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $below, 'amount_paid' => $below,
                'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $below]],
            ])->assertUnprocessable()->assertJsonValidationErrors('service_prices');
        }
        $valid = $maximum ?? $threshold ?? $amount;
        $response = $this->postJson("/api/admin/bookings/$walkinId/pay-now", ['final_price' => $valid, 'amount_paid' => $valid,
            'service_prices' => [['booking_service_id' => $line->booking_service_id, 'amount' => $valid]],
        ])->assertOk();
        if ($threshold !== null) $response->assertJsonCount(1, 'service_price_warnings');
        else $response->assertJsonCount(0, 'service_price_warnings');
    }

    public static function updatedPackageModes(): array
    {
        return [['small', 8, 'fixed', 600, null], ['small', 8, 'starting_at', 600, null],
            ['large', 30, 'starting_at', 950, 1150],
            ['small', 8, 'range', 600, null, 680],
            ['medium', 15, 'range', 700, null, 850],
            ['large', 30, 'range', 950, null, 1100],
            ['extra_large', 60, 'range', 1150, null, 1800]];
    }

    public function test_staff_can_record_in_person_sedation_consent_before_grooming(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'SEDATION-CONSENT-1',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'booking_type' => 'online',
            'status' => 'waiting_to_arrive',
            'sedation_consent' => false,
        ]);
        $this->authenticateAs('staff');

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('incomingList.0.sedationConsent', false)
            ->assertJsonPath('incomingList.0.sedationConsentRecorded', false)
            ->assertJsonPath('incomingList.0.canRecordSedationConsent', true);

        $this->postJson('/api/admin/bookings/1/sedation-consent', [
            'customer_understood_and_agreed' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_understood_and_agreed');

        $this->postJson('/api/admin/bookings/1/sedation-consent', [
            'customer_understood_and_agreed' => true,
        ])->assertOk()
            ->assertJsonPath('sedation_consent.accepted', true)
            ->assertJsonPath('sedation_consent.source', 'staff_in_person');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'sedation_consent' => true,
            'sedation_consent_source' => 'staff_in_person',
            'sedation_consent_recorded_by' => 2,
        ]);
        $this->assertNotNull(DB::table('bookings')->value('sedation_consent_recorded_at'));
        $this->getJson('/api/admin/bookings')->assertOk()
            ->assertJsonPath('incomingList.0.sedationConsent', true)
            ->assertJsonPath('incomingList.0.sedationConsentRecorded', true);

        DB::table('bookings')->insert([
            'booking_id' => 2,
            'booking_reference' => 'SEDATION-CONSENT-STARTED',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'booking_type' => 'online',
            'status' => 'in_progress',
            'sedation_consent' => false,
        ]);

        $this->postJson('/api/admin/bookings/2/sedation-consent', [
            'customer_understood_and_agreed' => true,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Sedation consent can only be recorded before grooming starts.',
            );
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 2,
            'sedation_consent' => false,
            'sedation_consent_source' => null,
        ]);
    }

    public function test_pre_registration_internal_note_updates_one_booking_and_locks_at_payment(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'STAFF-NOTE-1',
            'booking_date' => now()->toDateString(),
            'status' => 'waiting_to_arrive',
            'special_notes' => 'Customer supplied note',
        ]);
        $this->authenticateAs('staff');

        foreach (['waiting_to_arrive', 'checked_in', 'in_progress'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/internal-staff-note', [
                'internal_staff_note' => 'Call owner before extra services',
            ])->assertOk()->assertJsonPath('internal_staff_note', 'Call owner before extra services');
        }

        foreach (['for_payment', 'for_pickup', 'archived'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/internal-staff-note', [
                'internal_staff_note' => 'Changed too late',
            ])->assertUnprocessable();
        }

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'special_notes' => 'Customer supplied note',
            'internal_staff_note' => 'Call owner before extra services',
        ]);
    }

    public function test_walk_in_visit_notes_update_only_the_selected_pet_and_lock_at_payment(): void
    {
        DB::table('walkins')->insert([
            'id' => 1, 'fname' => 'Walk', 'lname' => 'In', 'phone' => '09170000000',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'WALK-NOTE-1',
            'walkin_id' => 1,
            'booking_date' => now()->toDateString(),
            'status' => 'checked_in',
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'special_instructions' => 'Short trim'],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'special_instructions' => 'Avoid perfume'],
        ]);
        $this->authenticateAs('staff');

        $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
            'grooming_visit_notes' => 'Nervous around dryers',
        ])->assertOk()->assertJsonPath('grooming_visit_notes', 'Nervous around dryers');

        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'in_progress']);
        $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
            'grooming_visit_notes' => 'Short trim, nervous around dryers',
        ])->assertOk();

        foreach (['for_payment', 'for_pickup', 'archived'] as $status) {
            DB::table('bookings')->where('booking_id', 1)->update(['status' => $status]);
            $this->patchJson('/api/admin/bookings/1/pets/1/grooming-visit-notes', [
                'grooming_visit_notes' => 'Changed too late',
            ])->assertUnprocessable();
        }

        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'special_instructions' => 'Short trim, nervous around dryers']);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 2, 'special_instructions' => 'Avoid perfume']);
    }

    public function test_revert_check_in_restores_admin_and_customer_state_and_allows_check_in_again(): void
    {
        DB::table('users')->insert([
            'user_id' => 100,
            'first_name' => 'Queue',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 100,
            'booking_reference' => 'REVERT-CHECK-IN-100',
            'user_id' => 100,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'waiting_to_arrive',
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 100, 'user_id' => 100, 'pet_name' => 'Alpha', 'species' => 'dog', 'size' => 'small'],
            ['pet_id' => 101, 'user_id' => 100, 'pet_name' => 'Beta', 'species' => 'cat', 'size' => 'small'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 100, 'booking_id' => 100, 'pet_id' => 100, ],
            ['booking_pet_id' => 101, 'booking_id' => 100, 'pet_id' => 101, ],
        ]);

        $this->authenticateAs('admin');

        $this->postJson('/api/admin/bookings/100/check-in')
            ->assertOk()
            ->assertJsonPath('queue_number', 1);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 100,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 100)->value('dropped_off_at'));
        $this->assertSame(
            [1, 2],
            DB::table('booking_pets')
                ->where('booking_id', 100)
                ->orderBy('booking_pet_id')
                ->pluck('pet_queue_number')
                ->map(fn ($number) => (int) $number)
                ->all(),
        );

        $this->postJson('/api/admin/bookings/100/revert-check-in')
            ->assertOk()
            ->assertJsonPath('status', 'waiting_to_arrive');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 100,
            'status' => 'waiting_to_arrive',
            'queue_number' => null,
            'dropped_off_at' => null,
        ]);
        $this->assertSame(0, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_date')->count());
        $this->assertSame(0, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_number')->count());

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('incomingList.0.id', 100)
            ->assertJsonCount(0, 'queuedList')
            ->assertJsonPath('capacity.current', 0);

        Sanctum::actingAs(User::query()->findOrFail(100), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.status', 'waiting_to_arrive')
            ->assertJsonPath('bookings.0.dropped_off_at', null)
            ->assertJsonPath('bookings.0.pets.0.grooming_status', 'waiting_to_arrive');
        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.active', 0)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.waiting', 1);

        $this->authenticateAs('admin');

        $this->postJson('/api/admin/bookings/100/check-in')
            ->assertOk()
            ->assertJsonPath('queue_number', 1);
        $this->assertSame(2, DB::table('booking_pets')->where('booking_id', 100)->whereNotNull('pet_queue_number')->count());
    }

    public function test_check_in_confirms_size_independently_of_weight_and_notifies_owner(): void
    {
        DB::table('users')->insert([
            'user_id' => 120,
            'first_name' => 'Rigby',
            'last_name' => 'Owner',
            'role' => 'customer',
        ]);
        DB::table('pets')->insert([
            'pet_id' => 120,
            'user_id' => 120,
            'pet_name' => 'Rigby',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'medium',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 120,
            'booking_reference' => 'SIZE-120',
            'user_id' => 120,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 120,
            'booking_id' => 120,
            'pet_id' => 120,
            'registered_size' => 'medium',
            ]);

        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/120/check-in', [
            'pet_sizes' => [['booking_pet_id' => 120, 'size' => 'small']],
            'internal_staff_note' => 'Call owner before extra services',
        ])->assertOk();

        $this->assertDatabaseHas('bookings', ['booking_id' => 120, 'internal_staff_note' => 'Call owner before extra services']);
        $this->assertDatabaseHas('pets', ['pet_id' => 120, 'size' => 'small', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 120,
            'registered_size' => 'medium',
            'confirmed_size' => 'small',
        ]);
        $this->assertSame(['size'], json_decode(DB::table('pets')->where('pet_id', 120)->value('clinic_verified_fields'), true));
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 120,
            'pet_id' => 120,
            'type' => 'pet_information_updated',
        ]);
    }

    public function test_single_pet_legacy_undo_restores_queue_without_deleting_notification_history(): void
    {
        DB::table('users')->insert([
            'user_id' => 110,
            'first_name' => 'Started',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 110,
            'booking_reference' => 'REVERT-START-110',
            'user_id' => 110,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'checked_in',
            'queue_number' => 1,
            'dropped_off_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 110,
            'user_id' => 110,
            'pet_name' => 'Gamma',
            'species' => 'dog',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 110,
            'booking_id' => 110,
            'pet_id' => 110,
            'pet_queue_date' => now()->toDateString(),
            'pet_queue_number' => 1,
        ]);

        $this->authenticateAs('staff');

        $this->postJson('/api/admin/bookings/110/start-grooming')
            ->assertOk();
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 110,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 110,
            'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'booking_id' => 110,
            'type' => 'grooming_started',
        ]);

        $this->postJson('/api/admin/bookings/110/revert-start-grooming')
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 110,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => null,
        ]);
        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 110,
            'pet_queue_number' => 1,
            'grooming_start_time' => null,
            'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'booking_id' => 110,
            'type' => 'grooming_started',
        ]);

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('queuedList.0.id', 110)
            ->assertJsonCount(0, 'inProgressList');

        Sanctum::actingAs(User::query()->findOrFail(110), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonPath('bookings.0.status', 'checked_in')
            ->assertJsonPath('bookings.0.grooming_started_at', null)
            ->assertJsonPath('bookings.0.pets.0.grooming_status', 'checked_in');
        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('queue.queued', 1)
            ->assertJsonPath('queue.in_progress', 0);

        $this->authenticateAs('staff');

        $this->postJson('/api/admin/bookings/110/start-grooming')
            ->assertOk();
        $this->assertDatabaseCount('customer_notifications', 2);
    }

    public function test_revert_endpoints_refuse_to_erase_later_grooming_activity(): void
    {
        $this->authenticateAs('admin');
        $this->seedMultiPetBooking();

        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')->assertOk();

        $this->postJson('/api/admin/bookings/1/revert-check-in')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Check-in cannot be reverted after grooming activity has started.');

        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson('/api/admin/bookings/1/revert-start-grooming')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Use Undo Start for a specific pet.');
        $this->postJson('/api/admin/bookings/1/pets/1/undo-start')->assertUnprocessable();

        $this->assertDatabaseHas('booking_pets', [
            'booking_pet_id' => 1,
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);
    }

    public function test_undo_and_pet_cancel_buttons_use_persisted_api_handlers(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        $dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));

        $this->assertStringContainsString('async function adminRevertCheckIn(bookingId)', $api);
        $this->assertStringContainsString('async function adminUndoPetStart(bookingId, bookingPetId)', $api);
        $this->assertStringContainsString('await API.adminRevertCheckIn(booking.id)', $dashboard);
        $this->assertStringContainsString('await API.adminUndoPetStart(booking.id,', $dashboard);
        $this->assertStringContainsString('await API.adminCancelPet(booking.id,', $dashboard);
        $this->assertStringNotContainsString('revertQueuedBookingFrontendOnly', $dashboard);
        $this->assertStringNotContainsString('revertInProgressBookingFrontendOnly', $dashboard);
        $this->assertStringNotContainsString('booking-reverted-ui-only', $dashboard);
    }

    public function test_archive_payload_has_constant_query_count_and_keeps_the_page_contract(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        DB::table('unregistered_customers')->insert([
            'id' => 30,
            'first_name' => 'Taylor',
            'last_name' => 'Reyes',
            'phone' => 'd123456789012345678',
            'is_archived' => true,
            'account_deleted_at' => now(),
        ]);
        DB::table('walkins')->insert([
            'id' => 20,
            'fname' => 'Taylor',
            'lname' => 'Reyes',
            'phone' => '09981234567',
            'unregistered_customer_id' => 30,
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '9:00 AM - 10:00 AM',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $bookings = [];
        $pets = [];
        $bookingPets = [];
        $bookingServices = [];
        $payments = [];

        for ($id = 1; $id <= 24; $id++) {
            $isWalkin = $id % 2 === 0;
            $bookings[] = [
                'booking_id' => $id,
                'booking_reference' => "ARCHIVE-{$id}",
                'user_id' => $isWalkin ? null : 10,
                'walkin_id' => $isWalkin ? 20 : null,
                'window_id' => 1,
                'booking_date' => '2026-07-24',
                'number_of_pets' => 1,
                'status' => 'archived',
                'queue_number' => $id,
                'special_notes' => 'Use sensitive shampoo.',
                'internal_staff_note' => $isWalkin ? null : 'Call before extra services',
                'total_amount' => 500,
                'paid' => true,
                'archived_at' => Carbon::parse('2026-07-24 12:00:00')->addMinutes($id),
                'dropped_off_at' => '2026-07-24 08:45:00',
                'grooming_started_at' => '2026-07-24 09:00:00',
                'grooming_finished_at' => '2026-07-24 10:00:00',
            ];
            $pets[] = [
                'pet_id' => $id,
                'user_id' => $isWalkin ? null : 10,
                'pet_name' => "Pet {$id}",
                'species' => 'dog',
                'breed' => 'Poodle',
                'weight' => 8.5,
                'size' => 'small',
                'fur_type' => 'curly',
                'medical_conditions' => 'None',
            ];
            $bookingPets[] = [
                'booking_pet_id' => $id,
                'booking_id' => $id,
                'pet_id' => $id,
                'pet_queue_date' => '2026-07-24',
                'pet_queue_number' => $id,
                'special_instructions' => 'Trim nails.',
                'grooming_start_time' => '2026-07-24 09:00:00',
                'grooming_end_time' => '2026-07-24 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ];
            $bookingServices[] = [
                'booking_service_id' => $id,
                'booking_id' => $id,
                'booking_pet_id' => $id,
                'service_id' => 1,
                'price_at_booking' => 500,
            ];
            $payments[] = [
                'payment_id' => $id,
                'booking_id' => $id,
                'total_amount' => 500,
                'amount_tendered' => 500,
                'change_amount' => 0,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'paid_at' => '2026-07-24 10:05:00',
            ];
        }

        $queryCount = 0;
        $qrQueryCount = 0;
        DB::listen(function ($query) use (&$queryCount, &$qrQueryCount): void {
            $queryCount++;
            if (str_contains($query->sql, 'qr_payments') || in_array('qr_payments', $query->bindings, true)) {
                $qrQueryCount++;
            }
        });

        $fixtures = [
            'bookings' => $bookings, 'pets' => $pets, 'booking_pets' => $bookingPets,
            'booking_services' => $bookingServices, 'payments' => $payments,
        ];
        $queryCounts = [];
        $previousCount = 0;
        foreach ([2, 12, 24] as $bookingCount) {
            foreach ($fixtures as $table => $rows) {
                DB::table($table)->insert(array_slice($rows, $previousCount, $bookingCount - $previousCount));
            }
            $previousCount = $bookingCount;
            $queryCount = $qrQueryCount = 0;
            $currentResponse = $this->getJson('/api/admin/bookings/archived')
                ->assertOk()->assertJsonPath('total', $bookingCount)->assertJsonCount($bookingCount, 'archived');

            // Disabled legacy QR must add neither schema probes nor relationship queries.
            $this->assertSame(0, $qrQueryCount, "Unexpected legacy QR query for {$bookingCount} bookings.");
            $this->assertLessThanOrEqual(
                13,
                $queryCount,
                "Archive query budget exceeded for {$bookingCount} bookings.",
            );
            $queryCounts[$bookingCount] = $queryCount;
            if ($bookingCount === 12) $response = $currentResponse;
        }
        $this->assertSame($queryCounts[2], $queryCounts[12], 'Archive queries must not grow from 2 to 12 bookings.');
        $this->assertSame($queryCounts[2], $queryCounts[24], 'Archive queries must not grow from 2 to 24 bookings.');
        $response->assertJsonStructure([
            'success',
            'total',
            'archived' => [
                '*' => [
                    'id',
                    'queueNumber',
                    'ownerName',
                    'contactNumber',
                    'ownerAccountDeleted',
                    'owner_account_deleted',
                    'petName',
                    'petType',
                    'breed',
                    'petSize',
                    'size',
                    'serviceLabel',
                    'appointmentDate',
                    'appointmentTime',
                    'dropOffTime',
                    'startedAt',
                    'completedAt',
                    'clientNotified',
                    'paid',
                    'status',
                    'bookingReference',
                    'specialNotes',
                    'numberOfPets',
                    'paidAmount',
                    'paid_amount',
                    'paymentReady',
                    'payment_ready',
                    'paymentBlockedReason',
                    'payment_blocked_reason',
                    'finalPaymentTotal',
                    'final_payment_total',
                    'paymentSummary' => [
                        'payment_ready',
                        'payment_blocked_reason',
                        'final_booking_total',
                        'pets',
                    ],
                    'payment_summary' => [
                        'payment_ready',
                        'payment_blocked_reason',
                        'final_booking_total',
                        'pets',
                    ],
                    'payment' => [
                        'id',
                        'finalPrice',
                        'final_price',
                        'amountPaid',
                        'amount_paid',
                        'paymentMethod',
                        'payment_method',
                        'paidAt',
                        'paid_at',
                    ],
                    'pets' => [
                        '*' => [
                            'id',
                            'bookingPetId',
                            'booking_pet_id',
                            'petId',
                            'pet_id',
                            'pet_name',
                            'name',
                            'petType',
                            'pet_type',
                            'petSize',
                            'pet_size',
                            'petName',
                            'species',
                            'breed',
                            'size',
                            'furType',
                            'weight',
                            'medicalConditions',
                            'specialInstructions',
                            'petQueueNumber',
                            'groomingStartedAt',
                            'isGroomingStarted',
                            'groomingStartedAtIso',
                            'groomingFinishedAt',
                            'groomingFinishedAtIso',
                            'isGroomingFinished',
                            'groomingState',
                            'grooming_state',
                            'groomingStateLabel',
                            'grooming_state_label',
                        ],
                    ],
                    'services' => [
                        '*' => [
                            'id',
                            'bookingServiceId',
                            'booking_service_id',
                            'bookingPetId',
                            'booking_pet_id',
                            'petId',
                            'pet_id',
                            'petName',
                            'pet_name',
                            'serviceId',
                            'service_id',
                            'slug',
                            'serviceSlug',
                            'service_slug',
                            'serviceName',
                            'service_name',
                            'description',
                            'name',
                            'priceAtBooking',
                            'price_at_booking',
                            'paidPrice',
                            'paid_price',
                            'paidPriceSource',
                            'paid_price_source',
                            'paymentTotal',
                            'payment_total',
                        ],
                    ],
                    'archivedAt',
                ],
            ],
        ]);
        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 12)
            ->assertJsonPath('archived.0.id', 12)
            ->assertJsonPath('archived.0.ownerName', 'Taylor Reyes')
            ->assertJsonPath('archived.0.contactNumber', '—')
            ->assertJsonPath('archived.0.ownerAccountDeleted', true)
            ->assertJsonPath('archived.1.ownerName', 'Jamie Santos')
            ->assertJsonPath('archived.1.contactNumber', '09171234567')
            ->assertJsonPath('archived.1.ownerAccountDeleted', false)
            ->assertJsonPath('archived.0.bookingReference', 'ARCHIVE-12')
            ->assertJsonPath('archived.0.petName', 'Pet 12')
            ->assertJsonPath('archived.0.petType', 'Dog')
            ->assertJsonPath('archived.0.breed', 'Poodle')
            ->assertJsonPath('archived.0.appointmentDate', '2026-07-24')
            ->assertJsonPath('archived.0.appointmentTime', '9:00 AM - 10:00 AM')
            ->assertJsonPath('archived.0.dropOffTime', '8:45 AM')
            ->assertJsonPath('archived.0.startedAt', '9:00 AM')
            ->assertJsonPath('archived.0.completedAt', '10:00 AM')
            ->assertJsonPath('archived.0.archivedAt', 'Jul 24, 2026 12:12 PM')
            ->assertJsonPath('archived.0.serviceLabel', 'Basic Grooming')
            ->assertJsonPath('archived.0.numberOfPets', 1)
            ->assertJsonPath('archived.0.specialNotes', 'Use sensitive shampoo.')
            ->assertJsonPath('archived.0.internalStaffNote', null)
            ->assertJsonPath('archived.1.internalStaffNote', 'Call before extra services')
            ->assertJsonPath('archived.0.paidAmount', 500)
            ->assertJsonPath('archived.0.paid_amount', 500)
            ->assertJsonPath('archived.0.paid', true)
            ->assertJsonPath('archived.0.status', 'archived')
            ->assertJsonPath('archived.0.paymentReady', true)
            ->assertJsonPath('archived.0.payment_ready', true)
            ->assertJsonPath('archived.0.finalPaymentTotal', '500.00')
            ->assertJsonPath('archived.0.final_payment_total', '500.00')
            ->assertJsonPath('archived.0.paymentSummary.payment_ready', true)
            ->assertJsonPath('archived.0.paymentSummary.final_booking_total', '500.00')
            ->assertJsonPath('archived.0.payment.finalPrice', 500)
            ->assertJsonPath('archived.0.payment.final_price', 500)
            ->assertJsonPath('archived.0.payment.id', 12)
            ->assertJsonPath('archived.0.payment.amountPaid', 500)
            ->assertJsonPath('archived.0.payment.paymentMethod', 'cash')
            ->assertJsonPath('archived.0.pets.0.petName', 'Pet 12')
            ->assertJsonPath('archived.0.pets.0.bookingPetId', 12)
            ->assertJsonPath('archived.0.pets.0.petId', 12)
            ->assertJsonPath('archived.0.pets.0.species', 'Dog')
            ->assertJsonPath('archived.0.pets.0.breed', 'Poodle')
            ->assertJsonPath('archived.0.pets.0.size', 'small')
            ->assertJsonPath('archived.0.pets.0.furType', 'curly')
            ->assertJsonPath('archived.0.pets.0.weight', '8.5 kg')
            ->assertJsonPath('archived.0.pets.0.medicalConditions', 'None')
            ->assertJsonPath('archived.0.pets.0.specialInstructions', 'Trim nails.')
            ->assertJsonPath('archived.0.pets.0.groomingState', BookingPet::GROOMING_STATE_FINISHED)
            ->assertJsonPath('archived.0.pets.0.groomingStateLabel', 'Finished')
            ->assertJsonPath('archived.0.services.0.name', 'Basic Grooming')
            ->assertJsonPath('archived.0.services.0.bookingServiceId', 12)
            ->assertJsonPath('archived.0.services.0.serviceId', 1)
            ->assertJsonPath('archived.0.services.0.serviceSlug', 'basic-grooming')
            ->assertJsonPath('archived.0.services.0.paidPrice', 500)
            ->assertJsonPath('archived.0.services.0.paid_price', 500)
            ->assertJsonPath('archived.0.services.0.paidPriceSource', 'payment_total')
            ->assertJsonPath('archived.0.services.0.paymentTotal', 500);

    }

    public function test_grooming_history_keeps_repeat_visits_and_transaction_snapshots_independent(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('users')->insert(['user_id' => 10, 'first_name' => 'Jamie', 'last_name' => 'Santos', 'role' => 'customer']);
        DB::table('walkins')->insert(['id' => 20, 'fname' => 'Jamie', 'lname' => 'Santos', 'phone' => '09171234567']);
        DB::table('bookings')->where('booking_id', 1)->update([
            'user_id' => 10, 'status' => 'archived', 'paid' => true, 'total_amount' => 725,
            'archived_at' => '2026-07-24 10:30:00', 'grooming_started_at' => '2026-07-24 09:00:00',
            'grooming_finished_at' => '2026-07-24 10:00:00', 'special_notes' => 'First visit customer note',
            'internal_staff_note' => 'First visit staff note',
        ]);
        DB::table('booking_pets')->where('booking_id', 1)->update([
            'registered_size' => 'small', 'confirmed_size' => 'medium',
            'grooming_start_time' => '2026-07-24 09:00:00', 'grooming_end_time' => '2026-07-24 10:00:00',
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED, 'special_instructions' => 'First visit instructions',
        ]);
        DB::table('booking_services')->insert([
            'booking_service_id' => 3, 'booking_id' => 1, 'booking_pet_id' => 1,
            'service_id' => 1, 'price_at_booking' => 75,
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 2, 'booking_reference' => 'WI-REPEAT-VISIT', 'walkin_id' => 20,
            'booking_date' => '2026-07-25', 'status' => 'archived', 'paid' => true, 'total_amount' => 400,
            'archived_at' => '2026-07-26 01:05:00', 'grooming_started_at' => '2026-07-25 23:30:00',
            'grooming_finished_at' => '2026-07-26 01:00:00', 'special_notes' => 'Repeat walk-in note',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 3, 'booking_id' => 2, 'pet_id' => 1, 'confirmed_size' => 'large',
            'grooming_start_time' => '2026-07-25 23:30:00', 'grooming_end_time' => '2026-07-26 01:00:00',
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED, 'special_instructions' => 'Repeat visit instructions',
        ]);
        DB::table('booking_services')->insert([
            'booking_service_id' => 4, 'booking_id' => 2, 'booking_pet_id' => 3,
            'service_id' => 1, 'price_at_booking' => 400,
        ]);
        DB::table('payments')->insert([
            ['payment_id' => 1, 'booking_id' => 1, 'total_amount' => 725, 'amount_tendered' => 1000,
                'change_amount' => 275, 'payment_method' => 'cash', 'payment_status' => 'paid',
                'paid_at' => '2026-07-24 10:15:00', 'notes' => 'First payment note'],
            ['payment_id' => 2, 'booking_id' => 2, 'total_amount' => 400, 'amount_tendered' => 400,
                'change_amount' => 0, 'payment_method' => 'gcash', 'payment_status' => 'paid',
                'paid_at' => '2026-07-26 01:02:00', 'notes' => 'Recorded transfer reference 123456'],
        ]);
        DB::table('grooming_payment_products')->insert([
            'payment_id' => 1, 'item_id' => 1, 'item_name' => 'Historical shampoo',
            'quantity' => 2, 'price_at_sale' => 75, 'subtotal' => 150,
        ]);
        // A changed live catalogue and pet profile cannot replace session snapshots.
        DB::table('services')->where('service_id', 1)->update(['base_price' => 9999, 'price_medium' => 9999, 'price_large' => 9999]);
        DB::table('pets')->where('pet_id', 1)->update(['size' => 'extra_large']);

        $response = $this->getJson('/api/admin/bookings/archived')->assertOk()->assertJsonPath('total', 2);
        $records = collect($response->json('archived'))->keyBy('id');
        $first = $records[1];
        $repeat = $records[2];
        $this->assertSame('MULTI-PET-SECURITY', $first['bookingReference']);
        $this->assertSame('Pre-Register', $first['bookingType']);
        $this->assertCount(2, $first['pets']);
        $this->assertSame(['medium', 'medium'], array_column($first['pets'], 'confirmedSize'));
        $this->assertSame([1, 2, 1], array_column($first['services'], 'bookingPetId'));
        $this->assertEquals([250, 250, 75], array_column($first['services'], 'paidPrice'));
        $this->assertSame('First visit instructions', $first['pets'][0]['specialInstructions']);
        $this->assertSame('First visit customer note', $first['specialNotes']);
        $this->assertSame('First visit staff note', $first['internalStaffNote']);
        $this->assertEquals(725, $first['payment']['finalPrice']);
        $this->assertSame('cash', $first['payment']['paymentMethod']);
        $this->assertSame('First payment note', $first['payment']['notes']);
        $this->assertEquals(275, $first['payment']['changeAmount']);
        $this->assertEquals(75, $first['productAddons'][0]['priceAtSale']);
        $this->assertEquals(150, $first['productAddons'][0]['subtotal']);
        $this->assertSame('WI-REPEAT-VISIT', $repeat['bookingReference']);
        $this->assertSame('Walk-In', $repeat['bookingType']);
        $this->assertCount(1, $repeat['pets']);
        $this->assertSame(1, $repeat['pets'][0]['petId']);
        $this->assertSame(3, $repeat['pets'][0]['bookingPetId']);
        $this->assertSame('large', $repeat['pets'][0]['confirmedSize']);
        $this->assertSame('Repeat visit instructions', $repeat['pets'][0]['specialInstructions']);
        $this->assertSame('Repeat walk-in note', $repeat['specialNotes']);
        $this->assertSame('gcash', $repeat['payment']['paymentMethod']);
        $this->assertSame('Recorded transfer reference 123456', $repeat['payment']['notes']);
        $this->assertEquals(400, $repeat['payment']['finalPrice']);
        $this->assertEquals(400, $repeat['services'][0]['paidPrice']);
        $this->assertSame([], $repeat['productAddons']);
        $this->assertStringStartsWith('2026-07-25T23:30:00', $repeat['startedAtIso']);
        $this->assertStringStartsWith('2026-07-26T01:00:00', $repeat['completedAtIso']);
        $this->assertSame('2026-07-26 01:00:00', Carbon::parse($repeat['pets'][0]['groomingFinishedAtIso'])->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('booking_services', ['booking_service_id' => 1, 'price_at_booking' => 250]);
    }

    #[DataProvider('authorizedGroomingRoles')]
    public function test_staff_and_admin_can_complete_the_existing_core_grooming_workflow(
        string $role,
        string $subrole = 'grooming_receptionist',
    ): void {
        $this->authenticateAs($role, $subrole);
        $this->seedMultiPetBooking();

        $this->getJson('/api/admin/bookings')
            ->assertOk()
            ->assertJsonPath('queuedList.0.id', 1)
            ->assertJsonPath('capacity.used', 2);

        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')
            ->assertOk()
            ->assertJsonPath('all_pets_started', false);
        $this->postJson('/api/admin/bookings/1/pets/2/start-grooming')
            ->assertOk()
            ->assertJsonPath('all_pets_started', true)
            ->assertJsonPath('booking_status', 'in_progress');

        $this->postJson('/api/admin/bookings/1/pets/1/mark-done')
            ->assertOk()
            ->assertJsonPath('all_pets_finished', false);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);
        $this->postJson('/api/admin/bookings/1/pets/2/mark-done')
            ->assertOk()
            ->assertJsonPath('all_pets_finished', true)
            ->assertJsonPath('booking_status', 'for_payment');

        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);

        $this->postJson('/api/admin/bookings/1/pay', [
            'final_price' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'service_prices' => [
                ['booking_service_id' => 1, 'amount' => 250],
                ['booking_service_id' => 2, 'amount' => 250],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('final_price', '500.00');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 1,
            'status' => 'released',
            'paid' => true,
        ]);
        $this->assertDatabaseHas('payments', [
            'booking_id' => 1,
            'payment_status' => 'paid',
        ]);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);

        DB::table('bookings')->insert([
            'booking_id' => 2,
            'booking_reference' => "RELEASE-{$role}",
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'for_payment',
            'queue_number' => 2,
            'paid' => true,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 3,
            'pet_name' => 'Ready',
            'species' => 'dog',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 3,
            'booking_id' => 2,
            'pet_id' => 3,
            'pet_queue_date' => now()->toDateString(),
            'pet_queue_number' => 3,
            'grooming_start_time' => now()->subHour(),
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson('/api/admin/bookings/2/release')
            ->assertOk();
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 2,
            'status' => 'released',
        ]);

        $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'weight' => 8,
                'groomer_id' => 1, 'services' => [[
                    'service_slug' => 'partial_grooming',
                ]],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'checked_in')
            ->assertJsonPath('pets.0.pet_name', 'Bantay');

        DB::table('users')->insert([
            'user_id' => 100,
            'first_name' => 'Gerald',
            'last_name' => 'Senining',
            'role' => 'customer',
        ]);
        DB::table('bookings')->where('booking_id', 1)->update(['user_id' => 100]);

        $this->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'payment_confirmed')
            ->assertJsonPath(
                'notifications.0.message',
                'Payment received from Gerald Senining for booking MULTI-PET-SECURITY.',
            )
            ->assertJsonFragment(['type' => 'payment_due']);
        $this->getJson('/api/admin/transactions')
            ->assertOk()
            ->assertJsonPath('transactions.0.bookingId', 1);

        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 4);
        $this->postJson('/api/admin/bookings/1/picked-up')->assertOk();
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('capacity.used', 2);
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 2);
    }

    public static function authorizedGroomingRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    public function test_phone_less_customer_uses_visit_contact_without_updating_account(): void
    {
        $this->authenticateAs('admin');
        DB::table('users')->insert(['user_id' => 50, 'first_name' => 'Test', 'last_name' => 'Owner', 'role' => 'customer', 'phone' => null]);
        $payload = [
            'fname' => 'Test', 'lname' => 'Owner', 'phone' => '',
            'owner_record_type' => 'registered', 'customer_user_id' => 50,
            'pets' => [['pet_name' => 'Buddy', 'species' => 'Dog', 'weight' => 8,
                'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']]]],
            'terms_agreed' => true, 'sedation_consent' => false,
        ];
        $this->submitSignedGrooming('/api/admin/walk-in', $payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $payload['phone'] = '09179998888';
        $this->submitSignedGrooming('/api/admin/walk-in', $payload)->assertCreated();
        $this->assertDatabaseHas('walkins', ['user_id' => 50, 'phone' => '09179998888']);
        $this->assertDatabaseHas('users', ['user_id' => 50, 'phone' => null]);
        $this->assertDatabaseHas('pets', ['pet_name' => 'Buddy', 'user_id' => 50]);
    }

    public function test_existing_registered_and_unregistered_owners_keep_pet_ownership_on_walk_in(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Active',
            'last_name' => 'Owner',
            'email' => 'active-owner@example.test',
            'phone' => '09170000050',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
        ]);
        DB::table('pets')->insert([
            'pet_id' => 50,
            'user_id' => 50,
            'pet_name' => 'Buddy',
            'species' => 'Dog',
            'weight' => 12,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('services')->insert([
            'service_id' => 2,
            'service_name' => 'Regular Dog Grooming',
            'slug' => 'regular_dog_grooming',
            'base_price' => 0,
        ]);

        $registeredResponse = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Ignored',
            'lname' => 'Name',
            'phone' => '09171111111',
            'owner_record_type' => 'registered',
            'customer_user_id' => 50,
            'pets' => [
                [
                    'pet_id' => 50,
                    'pet_name' => 'Buddy',
                    'species' => 'Dog',
                    'weight' => 12,
                    'size' => 'medium',
                    'grooming_preference' => 'regular_trim',
                    'groomer_id' => 1, 'services' => [['service_slug' => 'regular_dog_grooming']],
                ],
                [
                    'pet_name' => 'Luna',
                    'species' => 'Dog',
                    'weight' => 8,
                    'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']],
                ],
            ],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('owner.name', 'Active Owner');

        $registeredBookingId = $registeredResponse->json('booking_id');
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $registeredBookingId,
            'user_id' => 50,
            'booking_type' => 'walk_in',
        ]);
        $this->assertDatabaseHas('pets', [
            'user_id' => 50,
            'pet_name' => 'Luna',
        ]);
        $this->assertDatabaseHas('pets', ['pet_id' => 50, 'size' => 'medium', 'weight' => 12]);
        $this->assertDatabaseHas('booking_pets', ['pet_id' => 50, 'confirmed_size' => 'medium']);
        $this->assertDatabaseHas('booking_services', ['service_id' => 2, 'price_at_booking' => 650]);

        DB::table('booking_services')->delete();
        DB::table('booking_pets')->delete();
        DB::table('bookings')->delete();

        $unregisteredId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Unregistered',
            'last_name' => 'Owner',
            'phone' => '09170000060',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Ignored',
            'lname' => 'Again',
            'phone' => '09172222222',
            'owner_record_type' => 'unregistered',
            'unregistered_customer_id' => $unregisteredId,
            'pets' => [[
                'pet_name' => 'Milo',
                'species' => 'Dog',
                'weight' => 4,
                'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated()
            ->assertJsonPath('owner.name', 'Unregistered Owner');

        $this->assertDatabaseHas('pets', [
            'unregistered_customer_id' => $unregisteredId,
            'pet_name' => 'Milo',
        ]);
        $this->assertDatabaseHas('walkins', [
            'unregistered_customer_id' => $unregisteredId,
        ]);
    }

    public function test_successful_new_owner_pickup_creates_an_unregistered_customer_and_assigns_the_pet(): void
    {
        $this->authenticateAs('staff');

        $response = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'mname' => 'A',
            'email' => 'maria.walkin@example.test',
            'phone' => '09171234567',
            'owner_record_type' => 'new',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'size' => 'small',
                'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ])->assertCreated();

        $bookingId = $response->json('booking_id');
        $petId = $response->json('pets.0.pet_id');
        $walkinId = DB::table('bookings')->where('booking_id', $bookingId)->value('walkin_id');
        $bookingPetId = DB::table('booking_pets')->where('booking_id', $bookingId)->value('booking_pet_id');

        $this->assertDatabaseCount('unregistered_customers', 0);
        $this->assertDatabaseHas('pets', [
            'pet_id' => $petId,
            'user_id' => null,
            'unregistered_customer_id' => null,
        ]);

        DB::table('bookings')->where('booking_id', $bookingId)->update([
            'status' => 'released',
            'paid' => true,
        ]);
        DB::table('booking_pets')->where('booking_pet_id', $bookingPetId)->update([
            'grooming_start_time' => now()->subHour(),
            'grooming_end_time' => now(),
            'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
        ]);

        $this->postJson("/api/admin/bookings/{$bookingId}/picked-up")
            ->assertOk()
            ->assertJsonPath('success', true);

        $customerId = DB::table('unregistered_customers')
            ->where('phone', '09171234567')
            ->value('id');

        $this->assertNotNull($customerId);
        $this->assertDatabaseHas('unregistered_customers', [
            'id' => $customerId,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'middle_name' => 'A',
            'created_by_user_id' => 2,
        ]);
        $this->assertDatabaseHas('walkins', [
            'id' => $walkinId,
            'unregistered_customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('pets', [
            'pet_id' => $petId,
            'unregistered_customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => $bookingId,
            'status' => 'archived',
        ]);
    }

    public function test_walk_in_submission_enforces_duplicate_phone_and_similar_name_confirmation(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
            'phone' => '09170000050',
            'role' => 'customer',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $payload = [
            'fname' => 'Another',
            'lname' => 'Owner',
            'phone' => '09170000050',
            'owner_record_type' => 'new',
            'pets' => [[
                'pet_name' => 'Bantay',
                'species' => 'dog',
                'size' => 'small',
                'groomer_id' => 1, 'services' => [['service_slug' => 'partial_grooming']],
            ]],
            'sedation_consent' => false,
            'terms_agreed' => true,
        ];

        $this->submitSignedGrooming('/api/admin/walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $similarPayload = [
            ...$payload,
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09170000051',
        ];

        $this->submitSignedGrooming('/api/admin/walk-in', $similarPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'similar_customer_name');

        $this->submitSignedGrooming('/api/admin/walk-in', [
            ...$similarPayload,
            'confirm_similar_name' => true,
        ])->assertCreated();

        $this->assertDatabaseCount('unregistered_customers', 0);
    }

    public function test_admin_cancellation_notifies_the_customer_with_the_required_reason(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 50,
            'first_name' => 'Rudito',
            'last_name' => 'Gracia',
            'email' => 'rudito@example.test',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 50,
                'booking_reference' => 'CANCEL-CUSTOM-50',
                'user_id' => 50,
                'booking_date' => now()->addDay()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'incoming',
            ],
            [
                'booking_id' => 51,
                'booking_reference' => 'CANCEL-DEFAULT-51',
                'user_id' => 50,
                'booking_date' => now()->addDays(2)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'incoming',
            ],
        ]);

        $customMessage = 'Your grooming online registration CANCEL-CUSTOM-50 has been cancelled by the clinic. Reason: Owner requested a different date.';
        $defaultMessage = 'Your grooming online registration CANCEL-DEFAULT-51 has been cancelled by the clinic. Reason: Clinic unavailable.';

        $this->postJson('/api/admin/bookings/50/cancel', [
            'cancellation_reason' => '  Owner requested a different date.  ',
        ])
            ->assertOk()
            ->assertJsonPath('customer_notified', true);

        $this->postJson('/api/admin/bookings/51/cancel', [
            'cancellation_reason' => 'Clinic unavailable.',
        ])
            ->assertOk()
            ->assertJsonPath('customer_notified', true);

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 50,
            'status' => 'cancelled',
            'cancellation_reason' => 'Owner requested a different date.',
            'cancel_count' => 1,
        ]);
        $this->assertDatabaseHas('bookings', [
            'booking_id' => 51,
            'status' => 'cancelled',
            'cancellation_reason' => 'Clinic unavailable.',
            'cancel_count' => 1,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 50,
            'booking_id' => 50,
            'type' => 'booking_cancelled',
            'message' => $customMessage,
            'is_read' => false,
        ]);
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => 50,
            'booking_id' => 51,
            'type' => 'booking_cancelled',
            'message' => $defaultMessage,
            'is_read' => false,
        ]);
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'booking_id' => 50,
            'type' => 'cancelled',
            'is_read' => false,
        ]);
        $this->assertDatabaseHas('notifications', [
            'booking_id' => 51,
            'type' => 'cancelled',
            'is_read' => false,
        ]);
        $this->assertDatabaseCount('notifications', 2);

        $this->postJson('/api/admin/bookings/50/cancel')
            ->assertUnprocessable();
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertDatabaseCount('notifications', 2);

        Sanctum::actingAs(User::query()->findOrFail(50), ['*']);

        $this->getJson('/api/customer/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonFragment([
                'type' => 'booking_cancelled',
                'message' => $customMessage,
                'display_message' => $customMessage,
            ])
            ->assertJsonFragment([
                'type' => 'booking_cancelled',
                'message' => $defaultMessage,
                'display_message' => $defaultMessage,
            ]);
    }

    public function test_invalid_staff_cancellation_reason_does_not_cancel_or_notify(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 60,
            'first_name' => 'Customer',
            'last_name' => 'User',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 60,
            'booking_reference' => 'CANCEL-VALIDATION-60',
            'user_id' => 60,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'incoming',
        ]);

        $this->postJson('/api/admin/bookings/60/cancel', [
            'cancellation_reason' => str_repeat('a', 501),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cancellation_reason');

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 60,
            'status' => 'incoming',
            'cancel_count' => 0,
        ]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_customer_notification_failure_rolls_back_admin_cancellation(): void
    {
        $this->authenticateAs('admin');

        DB::table('users')->insert([
            'user_id' => 70,
            'first_name' => 'Customer',
            'last_name' => 'Rollback',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            'booking_id' => 70,
            'booking_reference' => 'CANCEL-ROLLBACK-70',
            'user_id' => 70,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'incoming',
        ]);

        DB::statement(
            "CREATE TRIGGER reject_booking_cancellation_notification
             BEFORE INSERT ON customer_notifications
             WHEN NEW.type = 'booking_cancelled'
             BEGIN
                 SELECT RAISE(ABORT, 'simulated notification failure');
             END",
        );

        try {
            $this->postJson('/api/admin/bookings/70/cancel', [
                'cancellation_reason' => 'Clinic is unavailable.',
            ])->assertServerError();
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS reject_booking_cancellation_notification');
        }

        $this->assertDatabaseHas('bookings', [
            'booking_id' => 70,
            'status' => 'incoming',
            'cancellation_reason' => null,
            'cancel_count' => 0,
        ]);
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_cancelled_pre_registrations_remain_in_admin_and_customer_grooming_history(): void
    {
        DB::table('users')->insert([
            'user_id' => 80,
            'first_name' => 'History',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 80,
                'booking_reference' => 'LEGACY-CANCELLED-ARCHIVE',
                'user_id' => 80,
                'booking_date' => now()->subDays(3)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'archived',
                'cancellation_reason' => 'Cancelled by clinic staff.',
                'cancel_count' => 1,
                'archived_at' => now()->subDays(2),
            ],
            [
                'booking_id' => 81,
                'booking_reference' => 'COMPLETED-GROOMING-HISTORY',
                'user_id' => 80,
                'booking_date' => now()->subDays(2)->toDateString(),
                'number_of_pets' => 1,
                'status' => 'archived',
                'cancellation_reason' => null,
                'cancel_count' => 0,
                'archived_at' => now()->subDay(),
            ],
            [
                'booking_id' => 82,
                'booking_reference' => 'CURRENTLY-CANCELLED',
                'user_id' => 80,
                'booking_date' => now()->addDay()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'cancelled',
                'cancellation_reason' => 'Customer is unavailable.',
                'cancel_count' => 1,
                'archived_at' => null,
            ],
        ]);

        $this->authenticateAs('admin');

        $this->getJson('/api/admin/bookings/archived')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonFragment(['bookingReference' => 'COMPLETED-GROOMING-HISTORY'])
            ->assertJsonFragment(['bookingReference' => 'LEGACY-CANCELLED-ARCHIVE', 'status' => 'cancelled'])
            ->assertJsonFragment(['bookingReference' => 'CURRENTLY-CANCELLED', 'status' => 'cancelled']);

        Sanctum::actingAs(User::query()->findOrFail(80), ['*']);

        $this->getJson('/api/booking/history')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonPath('history_total', 3)
            ->assertJsonFragment(['booking_reference' => 'COMPLETED-GROOMING-HISTORY'])
            ->assertJsonFragment(['booking_reference' => 'LEGACY-CANCELLED-ARCHIVE'])
            ->assertJsonFragment(['booking_reference' => 'CURRENTLY-CANCELLED']);
    }

    public function test_customer_capacity_matches_visible_queued_and_in_progress_pets(): void
    {
        DB::table('users')->insert([
            'user_id' => 90,
            'first_name' => 'Capacity',
            'last_name' => 'Customer',
            'role' => 'customer',
        ]);
        DB::table('bookings')->insert([
            [
                'booking_id' => 90,
                'booking_reference' => 'CAPACITY-CANCELLED',
                'user_id' => 90,
                'booking_date' => now()->toDateString(),
                'number_of_pets' => 1,
                'status' => 'cancelled',
                'cancellation_reason' => 'Cancelled by clinic staff.',
                'cancel_count' => 1,
                'dropped_off_at' => null,
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 90,
                'booking_id' => 90,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
        ]);

        Sanctum::actingAs(User::query()->findOrFail(90), ['*']);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('capacity.used', 0)
            ->assertJsonPath('capacity.remaining', 20)
            ->assertJsonPath('capacity.percent', 0)
            ->assertJsonPath('queue.active', 0)
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.in_progress', 0);

        DB::table('bookings')->insert([
            'booking_id' => 92,
            'booking_reference' => 'CAPACITY-ACTIVE',
            'user_id' => 90,
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'in_progress',
            'dropped_off_at' => now(),
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 92,
                'booking_id' => 92,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
                'grooming_start_time' => null,
            ],
            [
                'booking_pet_id' => 93,
                'booking_id' => 92,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
                'grooming_start_time' => now(),
            ],
        ]);

        $this->getJson('/api/booking/grooming-capacity')
            ->assertOk()
            ->assertJsonPath('capacity.used', 2)
            ->assertJsonPath('capacity.remaining', 18)
            ->assertJsonPath('capacity.percent', 10)
            ->assertJsonPath('queue.active', 2)
            ->assertJsonPath('queue.queued', 1)
            ->assertJsonPath('queue.in_progress', 1);
    }

    public function test_staff_and_admin_can_manage_named_pet_groomers(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $this->authenticateAs($role);
            $response = $this->postJson('/api/admin/pet-groomers', ['first_name' => ' Lucia '])->assertOk();
            $id = collect($response->json('pet_groomers'))->last()['id'];
            $this->assertDatabaseHas('pet_groomers', ['id' => $id, 'first_name' => 'Lucia', 'on_duty' => false]);
            $this->patchJson('/api/admin/pet-groomers/'.$id, ['on_duty' => true, 'first_name' => 'Luz'])
                ->assertOk()->assertJsonPath('groomers_on_duty', $role === 'staff' ? 3 : 4);
        }
        $this->patchJson('/api/admin/clinic/settings/groomers-on-duty', ['groomers_on_duty' => 5])->assertUnprocessable();
    }

    public function test_veterinarians_have_dashboard_read_access_without_grooming_operations(): void
    {
        foreach (['veterinarian'] as $subrole) {
            $this->authenticateAs('staff');
            $user = auth()->user();
            $user->forceFill(['staff_type' => 'clinic', 'staff_subrole' => $subrole]);
            Sanctum::actingAs($user, ['*']);
            foreach (self::GROOMING_ADMIN_ROUTES as [$method, $uri]) {
                if (str_starts_with($uri, 'api/admin/notifications') || str_starts_with($uri, 'api/admin/dashboard/') || $uri === 'api/admin/transactions') continue;
                $this->json($method, '/'.preg_replace('/\{[^}]+\}/', '1', $uri))
                    ->assertForbidden()->assertExactJson(self::FORBIDDEN_RESPONSE);
            }
        }
        $this->authenticateAs('staff');
        auth()->user()->forceFill(['staff_subrole' => null]);
        $this->getJson('/api/admin/bookings')->assertForbidden();

        foreach (['api/settings/security/account', 'api/admin/notifications', 'api/admin/clinic/settings/availability',
            'api/admin/clinic/blocked-dates', 'api/admin/transactions', 'api/inventory/items', 'api/pos/transactions'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $route) => $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
            $this->assertNotContains('grooming', $route->gatherMiddleware());
        }
    }

    public function test_clinic_receptionist_uses_the_existing_core_grooming_workflow(): void
    {
        $this->test_staff_and_admin_can_complete_the_existing_core_grooming_workflow('staff', 'clinic_receptionist');
    }

    public function test_every_grooming_administration_route_has_authentication_and_role_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach (self::GROOMING_ADMIN_ROUTES as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected grooming route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
            if (str_starts_with($uri, 'api/admin/dashboard/')) {
                $this->assertContains('staff:dashboard', $route->gatherMiddleware());
                $this->assertNotContains('grooming', $route->gatherMiddleware());
            } elseif (($uri === 'api/admin/clinic/settings/groomers-on-duty' || str_starts_with($uri, 'api/admin/pet-groomers'))) {
                $this->assertContains('staff:availability_manage', $route->gatherMiddleware());
            } elseif (! str_starts_with($uri, 'api/admin/notifications') && $uri !== 'api/admin/transactions') {
                $this->assertContains('grooming', $route->gatherMiddleware());
            } else {
                $this->assertNotContains('grooming', $route->gatherMiddleware());
            }
        }

        $controllerClasses = [
            AdminBookingController::class,
            NotificationController::class,
            PaymentController::class,
            WalkinController::class,
        ];
        $controllerRoutes = $routes->filter(
            fn (RoutingRoute $route) => $route->getActionName()
                === ClinicSettingController::class.'@updateGroomersOnDuty'
                || in_array($route->getActionName(), [
                    \App\Http\Controllers\PetGroomerController::class.'@store',
                    \App\Http\Controllers\PetGroomerController::class.'@update',
                ], true)
                || collect($controllerClasses)->contains(
                    fn (string $controller) => str_starts_with(
                        $route->getActionName(),
                        $controller.'@',
                    ),
                ),
        );

        $this->assertCount(count(self::GROOMING_ADMIN_ROUTES), $controllerRoutes);
        $controllerRoutes->each(function (RoutingRoute $route): void {
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        });
    }

    public function test_customer_grooming_and_pet_routes_do_not_inherit_staff_role_middleware(): void
    {
        $customerRoutes = [
            ['POST', 'api/booking/store'],
            ['GET', 'api/booking/history'],
            ['GET', 'api/booking/grooming-capacity'],
            ['GET', 'api/booking/{id}'],
            ['POST', 'api/booking/cancel'],
            ['POST', 'api/booking/reschedule'],
            ['GET', 'api/pets'],
            ['GET', 'api/pets/{id}'],
            ['GET', 'api/pets/{petId}/medical-records'],
            ['GET', 'api/pets/{petId}/vaccinations'],
            ['GET', 'api/customer/notifications'],
            ['PATCH', 'api/customer/notifications/read-all'],
            ['PATCH', 'api/customer/notifications/{id}/read'],
        ];
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($customerRoutes as [$method, $uri]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri
                    && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected customer route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertNotContains('role:admin,staff', $route->gatherMiddleware());
            $this->assertNotContains('role:admin', $route->gatherMiddleware());
        }
    }

    public function test_grooming_pre_registrations_share_preferred_times_without_reserving_capacity_or_queue(): void
    {
        DB::table('services')->insert(['slug' => 'nail_clipping', 'service_name' => 'Cat Full Grooming', 'base_price' => 500]);
        DB::table('time_windows')->insert([
            'window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'is_active' => true,
        ]);
        foreach (range(1, 6) as $customer) {
            DB::table('users')->insert(['user_id' => 100 + $customer, 'role' => 'customer']);
            Sanctum::actingAs(User::findOrFail(100 + $customer), ['*']);
            $this->submitSignedGrooming('/api/booking/store', [
                'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 4,
                'pets' => array_fill(0, 4, ['pet_name' => 'Test pet', 'species' => 'cat', 'services' => ['ala_carte' => ['nail_clipping']]]),
            ])->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
        }
        $this->assertSame(24, (int) DB::table('bookings')->sum('number_of_pets'));
        $this->assertSame(0, DB::table('bookings')->whereNotNull('queue_number')->count());
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        $this->getJson('/api/timeslots?date='.now()->toDateString())->assertOk()
            ->assertJsonMissingPath('windows.0.is_full')->assertJsonMissingPath('windows.0.recommended');
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 0);

        DB::table('bookings')->insert(['booking_id' => 999, 'booking_reference' => 'FULL-ON-SITE',
            'booking_date' => now()->toDateString(), 'number_of_pets' => 20, 'status' => 'released']);
        foreach (range(1, 20) as $pet) {
            DB::table('booking_pets')->insert(['booking_id' => 999, 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        }
        DB::table('users')->insert(['user_id' => 107, 'role' => 'customer']);
        Sanctum::actingAs(User::findOrFail(107), ['*']);
        $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Another pet', 'species' => 'cat', 'services' => ['ala_carte' => ['nail_clipping']]]],
        ])->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive');
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.used', 20);
        $this->getJson('/api/timeslots?date='.now()->toDateString())->assertOk()
            ->assertJsonMissingPath('windows.0.is_full')->assertJsonMissingPath('windows.0.recommended');
    }

    public function test_grooming_multi_pet_intake_respects_on_site_capacity_ignores_clinic_and_preferred_time(): void
    {
        foreach (range(1, 19) as $pet) {
            DB::table('clinic_appointments')->insert([
                'appointment_reference' => 'CV-20260724-'.str_pad($pet, 4, '0', STR_PAD_LEFT),
                'status' => 'in_consultation',
            ]);
        }
        $this->seedMultiPetBooking();
        DB::table('bookings')->insert(['booking_id' => 99, 'booking_reference' => 'ON-SITE-19',
            'booking_date' => now()->toDateString(), 'number_of_pets' => 19, 'status' => 'released']);
        foreach (range(1, 19) as $pet) {
            DB::table('booking_pets')->insert(['booking_id' => 99, 'grooming_state' => 'finished', 'grooming_end_time' => now()]);
        }
        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'waiting_to_arrive', 'queue_number' => null, 'dropped_off_at' => null]);
        DB::table('booking_pets')->update(['pet_queue_number' => null, 'pet_queue_date' => null]);
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertUnprocessable();
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'waiting_to_arrive', 'queue_number' => null]);
        DB::table('bookings')->where('booking_id', 99)->update(['status' => 'archived']);
        Carbon::setTestNow(now()->setTime(16, 30));
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => 'small'], ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertOk()->assertJsonPath('queue_number', 1);
        $this->getJson('/api/admin/bookings')->assertOk()
            ->assertJsonPath('capacity.current', 2)->assertJsonPath('summary.waitingNow', 2);
    }

    #[DataProvider('packageFacialSelections')]
    public function test_customer_and_walkin_bill_only_services_not_included_in_package(string $package, ?string $preference, string $species, int $weight, int $packagePrice): void
    {
        DB::table('services')->where('slug', $package)->delete();
        DB::table('services')->insert([
            ['service_id' => 91, 'service_name' => 'Grooming', 'slug' => $package, 'base_price' => $packagePrice],
            ['service_id' => 92, 'service_name' => 'Facial Trimming', 'slug' => 'facial_trimming', 'base_price' => 150],
        ]);
        (require database_path('migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        (require database_path('migrations/2026_10_26_000002_include_facial_trimming_in_dog_packages.php'))->up();
        DB::table('services')->where('service_id', 91)->update(['price_small' => $packagePrice, 'price_medium' => $packagePrice,
            'price_large' => $species === 'dog' ? $packagePrice : null,
            'price_extra_large' => $species === 'dog' ? $packagePrice : null, 'starting_price_sizes' => '[]']);
        $included = in_array($package, ['partial_grooming', 'regular_dog_grooming', 'deluxe_dog_grooming'], true);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $pet = ['pet_name' => 'Coco', 'species' => $species, 'weight' => $weight, 'groomer_id' => 1, 'grooming_preference' => $preference];
        $this->authenticateAs('customer');
        $payload = ['booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [[...$pet, 'services' => ['package' => $package, 'ala_carte' => ['facial_trimming']]]]];
        if ($included) {
            $this->submitSignedGrooming('/api/booking/store', $payload)->assertUnprocessable()->assertJsonValidationErrors('pets.0.services');
            $this->assertDatabaseCount('bookings', 0);
            $payload['pets'][0]['services']['ala_carte'] = [];
        }
        $customer = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated();
        $this->authenticateAs('staff');
        $payload = ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [[...$pet, 'services' => [['service_slug' => $package], ['service_slug' => 'facial_trimming']]]]];
        if ($included) {
            $this->submitSignedGrooming('/api/admin/walk-in', $payload)->assertUnprocessable()->assertJsonValidationErrors('pets.0.services');
            $payload['pets'][0]['services'] = [['service_slug' => $package]];
        }
        $walkin = $this->submitSignedGrooming('/api/admin/walk-in', $payload)->assertCreated();
        foreach ([$customer->json('booking.booking_id'), $walkin->json('booking_id')] as $id) {
            $this->assertDatabaseHas('booking_pets', ['booking_id' => $id, 'grooming_preference' => $preference]);
            $this->assertEquals($included ? 0 : 1, DB::table('booking_services')->where('booking_id', $id)->where('service_id', 92)->count());
            $this->assertEquals($packagePrice + ($included ? 0 : 150), DB::table('booking_services')->where('booking_id', $id)->sum('price_at_booking'));
        }
    }

    public static function packageFacialSelections(): array
    {
        $cases = [];
        foreach ([['partial_grooming', null, 400], ['regular_dog_grooming', 'summer_cut', 550],
            ['deluxe_dog_grooming', 'puppy_cut', 650], ['bath_and_go', null, 450]] as [$package, $preference, $price]) {
            foreach ([8, 11, 26, 51] as $weight) {
                $cases[] = [$package, $preference, 'dog', $weight, $price];
            }
        }
        return [...$cases, ['cat_full_grooming', null, 'cat', 4, 500], ['cat_full_grooming', null, 'cat', 10, 600]];
    }

    #[DataProvider('groomingArrivalWindowCases')]
    public function test_grooming_legacy_availability_and_automatic_registration_day(string $time, string $date, ?string $restriction, bool $accepted): void
    {
        DB::table('services')->insert(['slug' => 'cat_full_grooming', 'service_name' => 'Cat Full Grooming', 'base_price' => 500]);
        Carbon::setTestNow("2026-10-09 {$time}");
        $this->authenticateAs('customer');
        DB::table('clinic_settings')->where('id', 1)->update([
            'grooming_open_time' => '18:00:00', 'grooming_close_time' => '22:00:00',
            'grooming_prereg_cutoff_time' => $restriction === 'cutoff' ? '19:00:00' : '22:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1, 'start_time' => '19:00:00', 'end_time' => '20:00:00', 'window_label' => '7:00 PM - 8:00 PM',
        ]);
        if (in_array($restriction, ['blocked_date', 'stop_today'], true)) {
            DB::table('clinic_closures')->insert(['type' => $restriction, 'start_date' => $date, 'end_date' => $date]);
        }

        $availability = $this->getJson('/api/timeslots?date='.$date)->assertOk();
        $availability->assertJsonMissingPath('cutoff_passed')
            ->assertJsonPath('windows.0.is_past', $date === '2026-10-09' && $time >= '20:00:00')
            ->assertJsonPath('windows.0.is_closed', in_array($restriction, ['blocked_date', 'stop_today'], true));
        $response = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => $date, 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Mochi', 'species' => 'cat', 'services' => ['package' => 'cat_full_grooming']]],
        ]);
        $expectedDay = in_array($restriction, ['blocked_date', 'stop_today'], true) || $time >= '22:00:00'
            ? '2026-10-10' : '2026-10-09';
        $response->assertCreated()->assertJsonPath('booking.status', 'waiting_to_arrive')
            ->assertJsonPath('booking.booking_date', $expectedDay);
        $this->assertDatabaseHas('bookings', ['window_id' => null, 'queue_number' => null,
            'dropped_off_at' => null]);
    }

    public static function groomingArrivalWindowCases(): array
    {
        return [
            'before start' => ['18:59:00', '2026-10-09', null, true],
            'at start' => ['19:00:00', '2026-10-09', null, true],
            'inside window' => ['19:05:00', '2026-10-09', null, true],
            'before end' => ['19:59:00', '2026-10-09', null, true],
            'at end' => ['20:00:00', '2026-10-09', null, false],
            'legacy cutoff is ignored' => ['19:05:00', '2026-10-09', 'cutoff', true],
            'blocked date' => ['19:05:00', '2026-10-09', 'blocked_date', false],
            'stopped today' => ['19:05:00', '2026-10-09', 'stop_today', false],
            'future date after cutoff' => ['23:00:00', '2026-10-10', null, true],
        ];
    }

    public function test_near_closing_registration_has_no_arrival_window(): void
    {
        $payload = $this->prepareRegistrationDay();
        Carbon::setTestNow('2026-10-16 15:59:00');
        Sanctum::actingAs(User::findOrFail(101), ['*']);
        $response = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated();
        $this->assertDatabaseHas('bookings', ['booking_id' => $response->json('booking.booking_id'), 'arrival_end_time' => null]);
        $this->getJson('/api/timeslots?date=2026-10-16')->assertOk()
            ->assertJsonPath('windows.1.end_time', '16:00:00')->assertJsonPath('windows.1.is_past', false);
    }

    public function test_near_closing_walkin_and_check_in_ignore_retired_factor_inputs(): void
    {
        $this->seedMultiPetBooking();
        Carbon::setTestNow('2026-07-24 16:59:00');
        DB::table('bookings')->where('booking_id', 1)->update(['status' => 'waiting_to_arrive']);
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/1/check-in', ['pet_sizes' => [
            ['booking_pet_id' => 1, 'size' => 'small', 'estimate_factors' => ['obsolete-factor']],
            ['booking_pet_id' => 2, 'size' => 'small'],
        ]])->assertOk();
        $this->assertDatabaseHas('bookings', ['booking_id' => 1, 'status' => 'checked_in', 'dropped_off_at' => now()->toDateTimeString()]);
        $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'large', 'groomer_id' => 1,
                'services' => [['service_slug' => 'partial_grooming']]]],
        ])->assertCreated();
    }

    public function test_start_ignores_retired_factors_and_records_actual_session(): void
    {
        $this->seedMultiPetBooking();
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming', ['estimate_factors' => ['obsolete-factor']])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_state' => 'in_progress',
            'grooming_start_time' => now()->toDateTimeString()]);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 2, 'grooming_state' => 'not_started', 'grooming_start_time' => null]);
    }

    public function test_retired_reschedule_preserves_historical_arrival_snapshots(): void
    {
        $payload = $this->prepareRegistrationDay();
        Sanctum::actingAs(User::findOrFail(101), ['*']);
        $id = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated()->json('booking.booking_id');
        DB::table('bookings')->where('booking_id', $id)->update(['window_id' => 2, 'arrival_end_time' => '15:10:00']);
        $this->assertSame('15:10:00', \App\Models\Booking::findOrFail($id)->preferredArrivalWindow()->end_time);
        $this->postJson('/api/booking/reschedule', ['booking_id' => $id, 'new_date' => '2026-10-17', 'new_window_id' => 2])
            ->assertStatus(410)->assertJsonPath('code', 'arrival_selection_retired');
        $this->assertDatabaseHas('bookings', ['booking_id' => $id, 'booking_date' => '2026-10-16', 'arrival_end_time' => '15:10:00']);
    }

    public function test_start_preserves_independent_haircut_preference_validation(): void
    {
        $this->seedMultiPetBooking();
        DB::table('services')->insert(['service_id' => 91, 'service_name' => 'Regular Dog Grooming', 'slug' => 'regular_dog_grooming', 'base_price' => 650]);
        DB::table('booking_services')->where('booking_pet_id', 1)->update(['service_id' => 91]);
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming', ['grooming_preference' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('grooming_preference');
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_start_time' => null]);
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming', ['grooming_preference' => 'regular_trim'])->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_preference' => 'regular_trim', 'grooming_start_time' => now()->toDateTimeString()]);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('inProgressList.0.pets.0.groomingPreferenceLabel', 'Regular Trim');
    }

    public function test_saved_pet_size_still_limits_supported_package_sizes_without_estimator(): void
    {
        DB::table('users')->insert(['user_id' => 3, 'first_name' => 'Saved', 'last_name' => 'Owner', 'role' => 'customer', 'phone' => '09171234567']);
        DB::table('pets')->insert(['pet_id' => 3, 'user_id' => 3, 'pet_name' => 'Rigby', 'species' => 'dog', 'weight' => 12, 'size' => 'medium']);
        DB::table('services')->insert(['service_id' => 91, 'service_name' => 'Small Dog Package', 'slug' => 'small_only', 'base_price' => 500]);
        (require database_path('migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        DB::table('services')->where('service_id', 91)->update(['kind' => 'package', 'pet_type' => 'dog', 'price_small' => 500]);
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM', 'start_time' => '11:00:00', 'end_time' => '12:00:00']);
        foreach ([false, true] as $walkin) {
            $this->authenticateAs($walkin ? 'staff' : 'customer');
            $pet = ['pet_id' => 3, 'pet_name' => 'Rigby', 'species' => 'dog', 'groomer_id' => 1,
                'services' => $walkin ? [['service_slug' => 'small_only']] : ['package' => 'small_only']];
            $payload = $walkin ? ['fname' => 'Saved', 'lname' => 'Owner', 'phone' => '09171234567', 'owner_record_type' => 'registered', 'customer_user_id' => 3, 'pets' => [$pet]]
                : ['booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1, 'pets' => [$pet]];
            $this->submitSignedGrooming($walkin ? '/api/admin/walk-in' : '/api/booking/store', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('pets.0.size');
            $this->assertDatabaseCount('bookings', 0);
        }
    }

    private function prepareRegistrationDay(): array
    {
        Carbon::setTestNow('2026-10-16 13:00:00');
        $this->setPetGroomersOnDuty(1);
        DB::table('clinic_settings')->update(['grooming_prereg_cutoff_time' => '17:00:00']);
        DB::table('time_windows')->insert([
            ['window_id' => 1, 'window_label' => '1:00 PM - 2:00 PM', 'start_time' => '13:00:00', 'end_time' => '14:00:00'],
            ['window_id' => 2, 'window_label' => '3:00 PM - 4:00 PM', 'start_time' => '15:00:00', 'end_time' => '16:00:00'],
        ]);
        DB::table('users')->insert([
            ['user_id' => 101, 'first_name' => 'Maria', 'last_name' => 'Santos', 'role' => 'customer'],
            ['user_id' => 102, 'first_name' => 'Diego', 'last_name' => 'Reyes', 'role' => 'customer'],
        ]);
        return ['booking_date' => now()->toDateString(), 'window_id' => 2, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Coco', 'species' => 'dog', 'size' => 'large', 'services' => ['package' => 'partial_grooming']]]];
    }

    public function test_online_order_does_not_control_check_in_order_and_multi_pet_retries_are_safe(): void
    {
        $payload = $this->prepareRegistrationDay();
        DB::table('services')->insert(['slug' => 'nail_clipping', 'service_name' => 'Nail Clipping', 'base_price' => 100]);
        unset($payload['booking_date'], $payload['window_id']);
        Carbon::setTestNow('2026-10-16 09:00:00');
        Sanctum::actingAs(User::findOrFail(101));
        $a = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated()->json('booking.booking_id');
        Carbon::setTestNow('2026-10-16 09:10:00');
        Sanctum::actingAs(User::findOrFail(102));
        $payload['pets'][] = ['pet_name' => 'Bella', 'species' => 'cat', 'size' => 'small', 'weight' => 4, 'services' => ['ala_carte' => ['nail_clipping']]];
        $payload['number_of_pets'] = 2;
        $b = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated()->json('booking.booking_id');
        $this->assertSame(2, DB::table('bookings')->distinct()->count('booking_reference'));
        $this->assertSame(0, DB::table('bookings')->whereNotNull('queue_number')->count());
        $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
        $registered = DB::table('bookings')->where('booking_id', $a)->value('created_at');
        DB::table('bookings')->insert(['booking_reference' => 'PREVIOUS-10', 'booking_date' => '2026-10-16', 'queue_number' => 10, 'status' => 'archived']);
        DB::table('booking_pets')->insert(['booking_id' => DB::table('bookings')->max('booking_id'), 'pet_queue_number' => 10, 'pet_queue_date' => '2026-10-16', 'grooming_state' => 'finished']);
        $this->authenticateAs('staff');
        Carbon::setTestNow('2026-10-16 09:30:00');
        $this->postJson("/api/admin/bookings/{$b}/check-in")->assertOk()->assertJsonPath('queue_number', 11);
        $checkedIn = DB::table('bookings')->where('booking_id', $b)->value('dropped_off_at');
        Carbon::setTestNow('2026-10-16 09:45:00');
        $this->postJson("/api/admin/bookings/{$a}/check-in")->assertOk()->assertJsonPath('queue_number', 12);
        $this->postJson("/api/admin/bookings/{$b}/check-in")->assertOk()->assertJsonPath('queue_number', 11);
        $this->assertSame($checkedIn, DB::table('bookings')->where('booking_id', $b)->value('dropped_off_at'));
        $this->assertSame($registered, DB::table('bookings')->where('booking_id', $a)->value('created_at'));
        $this->assertSame([11, 12], DB::table('booking_pets')->where('booking_id', $b)->orderBy('pet_queue_number')->pluck('pet_queue_number')->all());
        $this->assertSame(13, DB::table('booking_pets')->where('booking_id', $a)->value('pet_queue_number'));
        $this->assertSame(3, DB::table('booking_pets')->whereIn('booking_id', [$a, $b])->count());
    }

    public function test_submission_uses_current_hours_when_legacy_window_is_no_longer_available(): void
    {
        $payload = $this->prepareRegistrationDay();
        Sanctum::actingAs(User::findOrFail(101), ['*']);
        DB::table('clinic_settings')->update(['grooming_close_time' => '15:00:00']);
        $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated()
            ->assertJsonPath('booking.booking_date', '2026-10-16')->assertJsonPath('booking.window', null);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('pets', 1);
    }

    public function test_date_expiry_notifies_once_through_the_customer_dashboard_notifications(): void
    {
        $payload = $this->prepareRegistrationDay();
        Sanctum::actingAs(User::findOrFail(101), ['*']);
        $id = $this->submitSignedGrooming('/api/booking/store', $payload)->assertCreated()->json('booking.booking_id');
        Carbon::setTestNow('2026-10-16 23:59:59');
        $this->assertSame(0, app(\App\Services\PreRegistrationExpiry::class)->expire());
        Carbon::setTestNow('2026-10-17 00:00:00');
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'Grooming online registration expired')
            ->assertJsonPath('notifications.0.display_message', 'Your grooming online registration for Oct 16 was cancelled because the scheduled date passed without check-in.');
        $this->assertSame(0, app(\App\Services\PreRegistrationExpiry::class)->expire());
        $this->assertDatabaseCount('customer_notifications', 1);
        $this->assertSame(1, DB::table('notifications')->where('type', 'no_show')->where('booking_id', $id)->count());
        $this->assertDatabaseHas('bookings', ['booking_id' => $id, 'status' => 'expired', 'queue_number' => null]);
        $this->getJson('/api/pre-registration/access')->assertOk()->assertJsonPath('allowed', true);
    }

    public function test_named_pet_groomer_limit_and_historical_assignment_survive_duty_changes(): void
    {
        $this->authenticateAs('admin');
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['groomer_id' => 1]);
        $this->setPetGroomersOnDuty(5);
        $response = $this->postJson('/api/admin/pet-groomers', ['first_name' => 'Lucia'])->assertOk();
        $id = collect($response->json('pet_groomers'))->last()['id'];
        $this->patchJson('/api/admin/pet-groomers/'.$id, ['on_duty' => true])->assertUnprocessable();
        $this->patchJson('/api/admin/pet-groomers/1', ['on_duty' => false, 'first_name' => 'Ana'])
            ->assertOk()->assertJsonPath('groomers_on_duty', 4);
        $this->assertSame('Ana', BookingPet::find(1)->groomer->first_name);
        $this->getJson('/api/grooming/pet-groomers')->assertOk()->assertJsonMissing(['id' => 1, 'first_name' => 'Ana']);
        $this->getJson('/api/admin/bookings')->assertOk()->assertJsonPath('queuedList.0.pets.0.petGroomerName', 'Ana');
        $this->patchJson('/api/admin/pet-groomers/1', ['first_name' => '  '])->assertUnprocessable();
        $this->deleteJson('/api/admin/pet-groomers/1')->assertStatus(405);
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'groomer_id' => 1]);
    }

    #[DataProvider('namedPetGroomerCases')]
    public function test_named_pet_groomer_submission_validation_and_persistence(bool $walkIn, int $count, array $ids, bool $accepted): void
    {
        $payload = $this->prepareRegistrationDay();
        $this->setPetGroomersOnDuty($count);
        $pets = array_map(fn ($id, $index) => [
            'pet_name' => ['Coco', 'Luna'][$index], 'species' => 'dog', 'size' => 'small',
            'groomer_id' => $id,
            'services' => $walkIn ? [['service_slug' => 'partial_grooming']] : ['package' => 'partial_grooming'],
        ], $ids, array_keys($ids));
        if ($walkIn) {
            $this->authenticateAs('staff');
            DB::table('users')->where('user_id', 101)->update(['phone' => '09171234567']);
            $payload = ['fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
                'sedation_consent' => false, 'terms_agreed' => true, 'pets' => $pets,
                'owner_record_type' => 'registered', 'customer_user_id' => 101];
        } else {
            Sanctum::actingAs(User::findOrFail(101), ['*']);
            $payload = [...$payload, 'window_id' => 1, 'pets' => $pets, 'number_of_pets' => count($pets)];
        }
        $response = $this->submitSignedGrooming($walkIn ? '/api/admin/walk-in' : '/api/booking/store', $payload);
        if (! $accepted) {
            $response->assertUnprocessable()->assertJsonValidationErrors('pets.0.groomer_id');
            foreach (['bookings', 'booking_pets', 'pets', 'walkins'] as $table) $this->assertDatabaseCount($table, 0);
            return;
        }
        $response->assertCreated();
        $expected = array_map(fn ($id) => $count === 1 ? 1 : $id, $ids);
        $this->assertSame($expected, DB::table('booking_pets')->orderBy('booking_pet_id')->pluck('groomer_id')->all());
        $rows = $response->json($walkIn ? 'pets' : 'booking.pets');
        $this->assertSame($expected, array_column($rows, 'groomer_id'));
        foreach ($rows as $index => $row) {
            $this->assertSame($expected[$index] === null ? null : ['Anna', 'Miguel'][$expected[$index] - 1], $row['pet_groomer']['first_name'] ?? null);
        }
    }

    public static function namedPetGroomerCases(): array
    {
        $cases = [];
        foreach ([false, true] as $walkIn) {
            $prefix = $walkIn ? 'walk-in ' : 'customer ';
            $cases[$prefix.'zero available'] = [$walkIn, 0, [null], false];
            $cases[$prefix.'single automatic'] = [$walkIn, 1, [null], true];
            $cases[$prefix.'single automatic multiple pets'] = [$walkIn, 1, [null, null], true];
            $cases[$prefix.'specific'] = [$walkIn, 2, [2], true];
            $cases[$prefix.'different choices per pet'] = [$walkIn, 2, [1, 2], true];
            $cases[$prefix.'off duty'] = [$walkIn, 2, [3], false];
            $cases[$prefix.'stale'] = [$walkIn, 2, [9999], false];
            $cases[$prefix.'flexible'] = [$walkIn, 2, [null], ! $walkIn];
        }
        $cases['customer mixed flexible and specific'] = [false, 2, [null, 2], true];
        $cases['walk-in each pet required'] = [true, 2, [null, 2], false];
        return $cases;
    }

    public function test_zero_named_pet_groomers_blocks_admission_and_start_without_changing_queue(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        $this->setPetGroomersOnDuty(0);
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')->assertUnprocessable();
        DB::table('bookings')->update(['status' => 'waiting_to_arrive', 'dropped_off_at' => null]);
        $this->postJson('/api/admin/bookings/1/check-in')->assertUnprocessable();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'grooming_state' => 'not_started', 'pet_queue_number' => 1]);
    }

    public function test_undo_grooming_start_preserves_the_pet_groomer_assignment(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['groomer_id' => 1]);
        $this->postJson('/api/admin/bookings/1/pets/1/start-grooming')->assertOk();
        $this->postJson('/api/admin/bookings/1/pets/1/undo-start')->assertOk();
        $this->assertDatabaseHas('booking_pets', ['booking_pet_id' => 1, 'groomer_id' => 1,
            'grooming_state' => 'not_started', 'grooming_start_time' => null]);
    }

    private function signedGroomingPayload(bool $walkIn): array
    {
        $pet = ['pet_name' => 'Signed Visit', 'species' => 'dog', 'weight' => 4, 'groomer_id' => 1];
        if ($walkIn) {
            return [...$this->groomingConsentFixture(), 'fname' => 'Ana', 'lname' => 'Cruz', 'phone' => '09171234567',
                'pets' => [[...$pet, 'services' => [['service_slug' => 'partial_grooming']]]]];
        }
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        return [...$this->groomingConsentFixture(), 'booking_date' => now()->toDateString(), 'window_id' => 1,
            'number_of_pets' => 1, 'pets' => [[...$pet, 'services' => ['package' => 'partial_grooming']]]];
    }

    public static function signedConsentFlows(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    public static function reportSnapshotFlows(): array
    {
        return ['customer booking' => [false], 'walk-in booking' => [true]];
    }

    #[DataProvider('reportSnapshotFlows')]
    public function test_booking_workflows_snapshot_service_identity_for_reports(bool $walkIn): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->string('service_name_snapshot')->nullable();
            $table->string('service_kind_snapshot')->nullable();
        });
        $this->authenticateAs($walkIn ? 'staff' : 'customer');
        $payload = $this->signedGroomingPayload($walkIn);
        $service = \App\Models\Service::where('slug', 'partial_grooming')->firstOrFail();
        $response = $this->postJson($walkIn ? '/api/admin/walk-in' : '/api/booking/store', $payload)->assertCreated();
        $id = $response->json($walkIn ? 'booking_id' : 'booking.booking_id');
        $this->assertDatabaseHas('booking_services', ['booking_id' => $id,
            'service_name_snapshot' => $service->service_name, 'service_kind_snapshot' => $service->kind]);
    }

    #[DataProvider('signedConsentFlows')]
    public function test_signed_consent_survives_the_complete_grooming_flow(bool $walkIn, bool $sedation): void
    {
        $this->authenticateAs($walkIn ? 'staff' : 'customer');
        $payload = $this->signedGroomingPayload($walkIn);
        $payload['sedation_consent'] = $sedation;
        $payload['signed_at'] = '2000-01-01T00:00:00Z'; // Never trust the browser's clock.
        $response = $this->postJson($walkIn ? '/api/admin/walk-in' : '/api/booking/store', $payload)->assertCreated();
        $id = $response->json($walkIn ? 'booking_id' : 'booking.booking_id');
        $stored = \App\Models\GroomingConsent::where('booking_id', $id)->firstOrFail();
        $this->assertSame($sedation, $stored->sedation_consent);
        $this->assertTrue($stored->grooming_consent);
        $this->assertSame('Customer Representative', $stored->signer_name);
        $this->assertTrue($stored->signed_at->equalTo(now()));
        $this->assertSame($payload['signature_strokes'], $stored->signature_strokes);
        $this->assertSame(\App\Services\GroomingConsentCapture::agreement(), $stored->agreement_snapshot);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $stored->signature_image);
        $snapshot = $stored->toArray();

        $this->authenticateAs('staff');
        $list = $walkIn ? 'queuedList' : 'incomingList';
        $this->getJson('/api/admin/bookings')->assertOk()
            ->assertJsonPath($list.'.0.sedationConsent', $sedation)
            ->assertJsonPath($list.'.0.sedationConsentRecorded', true);
        if (! $walkIn) $this->postJson("/api/admin/bookings/{$id}/check-in")->assertOk();
        $petId = DB::table('booking_pets')->where('booking_id', $id)->value('booking_pet_id');
        $serviceId = DB::table('booking_services')->where('booking_id', $id)->value('booking_service_id');
        $this->postJson("/api/admin/bookings/{$id}/pets/{$petId}/start-grooming")->assertOk();
        $this->getJson('/api/admin/bookings')->assertOk()
            ->assertJsonPath('inProgressList.0.sedationConsent', $sedation)
            ->assertJsonPath('inProgressList.0.sedationConsentRecorded', true);
        Carbon::setTestNow(now()->addHour());
        $this->postJson("/api/admin/bookings/{$id}/pets/{$petId}/mark-done")->assertOk();
        $this->postJson("/api/admin/bookings/{$id}/pay", ['final_price' => 250, 'amount_paid' => 250,
            'payment_method' => 'cash', 'service_prices' => [['booking_service_id' => $serviceId, 'amount' => 250]]])->assertOk();
        $this->postJson("/api/admin/bookings/{$id}/picked-up")->assertOk();
        $this->getJson('/api/admin/bookings/archived')->assertOk()->assertJsonPath('archived.0.id', $id)
            ->assertJsonMissingPath('archived.0.signature_image');
        $this->getJson("/api/admin/bookings/{$id}/signed-consent")->assertOk()
            ->assertJsonPath('consent.booking_id', $id)->assertJsonPath('consent.sedation_consent', $sedation)
            ->assertJsonPath('consent.signature_image', $stored->signature_image)
            ->assertJsonPath('consent.agreement_snapshot', $stored->agreement_snapshot);
        $this->assertSame($snapshot, $stored->fresh()->toArray(), 'Completion must preserve the accepted consent unchanged.');
        $this->assertDatabaseCount('grooming_consents', 1);
    }

    public static function invalidSignedConsents(): array
    {
        $cases = [];
        foreach ([false, true] as $walkIn) {
            foreach ([['signature_strokes', null], ['signature_strokes', []], ['signature_strokes', [[[30, 30], [30, 30]]]],
                ['signer_name', ''], ['signer_name', '   '], ['terms_agreed', false], ['consent_version', 'old']] as [$field, $value]) {
                $cases[] = [$walkIn, $field, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidSignedConsents')]
    public function test_signed_consent_rejects_incomplete_submissions(bool $walkIn, string $field, mixed $value): void
    {
        $this->authenticateAs($walkIn ? 'staff' : 'customer');
        $payload = $this->signedGroomingPayload($walkIn);
        $payload[$field] = $value;
        $this->postJson($walkIn ? '/api/admin/walk-in' : '/api/booking/store', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('grooming_consents', 0);
    }

    public function test_signed_consent_legacy_records_preserve_unknown_values(): void
    {
        $this->authenticateAs('staff');
        $this->seedMultiPetBooking();
        $this->getJson('/api/admin/bookings/1/signed-consent')->assertOk()
            ->assertJsonPath('consent', null)->assertJsonPath('legacy_grooming_consent', null)
            ->assertJsonPath('legacy_sedation_consent', null);
        DB::table('bookings')->where('booking_id', 1)->update(['sedation_consent' => true]);
        $this->getJson('/api/admin/bookings/1/signed-consent')->assertOk()->assertJsonPath('legacy_sedation_consent', true);
        $this->getJson('/api/admin/bookings/999/signed-consent')->assertNotFound();
    }

    #[DataProvider('groomingMinimumAgeCases')]
    public function test_customer_grooming_enforces_calendar_month_minimum_age(?string $birthdate, string $today, bool $eligible, bool $saved): void
    {
        DB::table('services')->insert(['slug' => 'cat_full_grooming', 'service_name' => 'Cat Full Grooming', 'base_price' => 500]);
        Carbon::setTestNow($today.' 10:00:00');
        $this->authenticateAs('customer');
        DB::table('time_windows')->insert([
            'window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00',
            'max_slots' => 4, 'is_active' => true,
        ]);
        $pet = ['pet_name' => 'Mochi', 'species' => 'cat', 'services' => ['package' => 'cat_full_grooming'], 'birthdate' => $birthdate];
        if ($saved) {
            DB::table('pets')->insert(['pet_id' => 42, 'user_id' => 3, ...array_diff_key($pet, ['services' => true])]);
            // A stale or tampered payload must not override the saved birthdate.
            $pet['pet_id'] = 42;
            $pet['birthdate'] = null;
        }
        $response = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => $today, 'window_id' => 1, 'number_of_pets' => 1, 'pets' => [$pet],
        ]);
        if ($eligible) {
            $response->assertCreated();
            $this->assertDatabaseHas('pets', ['birthdate' => $birthdate, 'pet_name' => 'Mochi']);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('pets.0.birthdate');
            $this->assertSame(['Pet must be at least 6 months old for grooming.'], $response->json('errors')['pets.0.birthdate']);
            $this->assertDatabaseCount('bookings', 0);
            $this->assertDatabaseCount('booking_pets', 0);
        }
    }

    public static function groomingMinimumAgeCases(): array
    {
        $cases = [];
        foreach ([false, true] as $saved) {
            foreach ([
                [null, '2026-10-16', true],
                ['2026-06-16', '2026-10-16', false],
                ['2026-04-17', '2026-10-16', false],
                ['2026-04-16', '2026-10-16', true],
                ['2024-10-16', '2026-10-16', true],
                ['2025-08-31', '2026-02-27', false],
                ['2025-08-31', '2026-02-28', true],
                ['2023-08-31', '2024-02-28', false],
                ['2023-08-31', '2024-02-29', true],
                ['2026-04-30', '2026-10-27', false],
            ] as [$birthdate, $today, $eligible]) {
                $cases[] = [$birthdate, $today, $eligible, $saved];
            }
        }

        return $cases;
    }

    public function test_managed_services_work_through_customer_and_walkin_submission_without_changing_existing_prices(): void
    {
        (require base_path('database/migrations/2026_10_17_000001_add_grooming_catalog_management.php'))->up();
        $this->authenticateAs('admin');
        $extra = $this->postJson('/api/admin/grooming/services', ['name' => 'Paw Balm Care', 'kind' => 'ala_carte',
            'pricing_type' => 'fixed', 'amount' => '180', 'isActive' => true, 'displayOrder' => 9])->assertCreated()->json('data');
        $payload = ['name' => 'Coat Care Grooming', 'kind' => 'package', 'petType' => 'dog',
            'descriptionItems' => ['Gentle bath and coat conditioning'], 'includedServiceIds' => [],
            'isActive' => true, 'displayOrder' => 3, 'sizes' => []];
        foreach (['small' => 600, 'medium' => 700, 'large' => 900, 'extra_large' => 1100] as $size => $amount) {
            $payload['sizes'][$size] = ['pricing_type' => 'fixed', 'amount' => (string) $amount];
        }
        $package = $this->postJson('/api/admin/grooming/services', $payload)->assertCreated()->json('data');
        DB::table('time_windows')->insert(['window_id' => 1, 'window_label' => '11:00 AM - 12:00 PM',
            'start_time' => '11:00:00', 'end_time' => '12:00:00', 'max_slots' => 4, 'is_active' => true]);
        $this->authenticateAs('customer');
        $customer = $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->toDateString(), 'window_id' => 1, 'number_of_pets' => 1,
            'pets' => [['pet_name' => 'Milo', 'species' => 'dog', 'weight' => 8, 'groomer_id' => 1,
                'services' => ['package' => $package['id'], 'ala_carte' => [$extra['id']]]]],
        ])->assertCreated()->assertJsonPath('booking.pets.0.grooming_preference', null);
        $this->authenticateAs('admin');
        $payload['name'] = 'Coat and Skin Care';
        $payload['sizes']['small']['amount'] = '650';
        $this->putJson('/api/admin/grooming/services/'.$package['serviceId'], $payload)->assertOk();
        $this->authenticateAs('staff');
        $walkin = $this->submitSignedGrooming('/api/admin/walk-in', [
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pets' => [['pet_name' => 'Coco', 'species' => 'dog', 'weight' => 8, 'groomer_id' => 1,
                'services' => [['service_slug' => $package['id'], 'price' => 1], ['service_slug' => $extra['id'], 'price' => 1]]]],
        ])->assertCreated();
        foreach ([[$customer->json('booking.booking_id'), 600], [$walkin->json('booking_id'), 650]] as [$bookingId, $amount]) {
            $this->assertDatabaseHas('booking_services', ['booking_id' => $bookingId, 'service_id' => $package['serviceId'], 'price_at_booking' => $amount]);
            $this->assertDatabaseHas('booking_services', ['booking_id' => $bookingId, 'service_id' => $extra['serviceId'], 'price_at_booking' => 180]);
        }
        $this->authenticateAs('admin');
        $this->patchJson('/api/admin/grooming/services/'.$package['serviceId'].'/status', ['isActive' => false])->assertOk();
        $this->assertDatabaseHas('booking_services', ['booking_id' => $customer->json('booking.booking_id'), 'service_id' => $package['serviceId'], 'price_at_booking' => 600]);
    }

    public function test_concurrent_grooming_submissions_preserve_registration_integrity_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'bethlehem_capacity_')) {
            $this->markTestSkipped('Run tests/grooming-capacity-concurrency.php with its isolated MySQL database.');
        }
        $payload = array_replace($this->groomingConsentFixture(), $this->prepareRegistrationDay());
        unset($payload['booking_date'], $payload['window_id']);
        Schema::table('clinic_appointments', function (Blueprint $table) {
            $table->string('appointment_type')->nullable(); $table->string('case_type')->nullable();
            $table->date('appointment_date')->nullable(); $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('queue_number')->nullable(); $table->dateTime('checked_in_at')->nullable();
            $table->json('common_concerns')->nullable(); $table->timestamps();
        });
        foreach (['clinic_vitals', 'clinic_records'] as $table) {
            Schema::create($table, function (Blueprint $columns) { $columns->id(); $columns->unsignedBigInteger('clinic_appointment_id'); });
        }
        $directory = \Tests\Support\capacityRunDirectory();
        $run = function (array $owners, array $payloads, string|array $action) use ($directory): array {
            $environment = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_URL' => '',
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
            $worker = base_path('tests/grooming-capacity-submit.php');
            $processes = [];
            foreach (['first', 'second'] as $index => $role) {
                $processes[] = new \Symfony\Component\Process\Process([PHP_BINARY, $worker, (string) $owners[$index], json_encode($payloads[$index]), $role, is_array($action) ? $action[$index] : $action], base_path(), $environment);
                $processes[$index]->setTimeout(25);
            }
            try {
                $processes[0]->start();
                $deadline = microtime(true) + 10;
                while (! is_file($directory.'/first-lock.json') && $processes[0]->isRunning() && microtime(true) < $deadline) { clearstatcache(); usleep(10000); }
                $this->assertFileExists($directory.'/first-lock.json', $processes[0]->getErrorOutput());
                $processes[1]->start();
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                    $this->assertJson($process->getOutput());
                }
                [$a, $b] = array_map(fn ($process) => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), $processes);
                foreach ([$a, $b] as $result) {
                    $this->assertSame('mysql', $result['driver']);
                    $this->assertSame(DB::connection()->getDatabaseName(), $result['database']);
                }
                $this->assertLessThan($a['finished_at'], $b['started_at'], 'Operations must overlap.');
                $this->assertArrayHasKey('contention_observed_at', $a['lock'], 'The first connection must observe the other waiting for GET_LOCK.');
                $this->assertGreaterThanOrEqual($a['lock']['release_started_at'], $b['lock']['acquired_at']);
                return [$a, $b];
            } finally {
                foreach ($processes as $process) $process->stop(1);
                foreach (['first-lock.json', 'second-attempt.json'] as $file) if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
        };
        try {
            [$a, $b] = $run([101, 102], [$payload, $payload], 'grooming-submit');
            $this->assertSame([201, 201], [$a['status'], $b['status']]);
            $this->assertSame(2, DB::table('bookings')->distinct()->count('booking_reference'));
            $this->assertSame(0, DB::table('bookings')->whereNotNull('queue_number')->count());
            $this->assertSame(0, DB::table('booking_pets')->whereNotNull('pet_queue_number')->count());
            $this->assertDatabaseHas('bookings', ['window_id' => null, 'arrival_end_time' => null]);
            $this->assertDatabaseCount('notifications', 2);
            $ids = [$b['body']['booking']['booking_id'], $a['body']['booking']['booking_id']];
            [$first, $second] = $run([102, 101], [['id' => $ids[0]], ['id' => $ids[1]]], 'grooming-check-in');
            $this->assertSame([200, 200], [$first['status'], $second['status']]);
            $this->assertSame([1, 2], [$first['body']['queue_number'], $second['body']['queue_number']]);
            $this->assertSame(2, DB::table('booking_pets')->distinct()->count('pet_queue_number'));
            $before = DB::table('bookings')->where('booking_id', $ids[0])->first();
            $this->assertSame(1, app(AdminBookingController::class)->checkIn($ids[0])->getData(true)['queue_number']);
            $this->assertEquals($before, DB::table('bookings')->where('booking_id', $ids[0])->first());
            DB::table('users')->insert([['user_id' => 103, 'role' => 'customer'], ['user_id' => 104, 'role' => 'customer']]);
            DB::table('pets')->insert([['pet_id' => 103, 'user_id' => 103, 'pet_name' => 'Clinic A', 'species' => 'cat'], ['pet_id' => 104, 'user_id' => 104, 'pet_name' => 'Clinic B', 'species' => 'cat']]);
            [$a, $b] = $run([103, 104], [['pet_id' => 103, 'common_concerns' => ['Vomiting']], ['pet_id' => 104, 'common_concerns' => ['Routine check-up']]], 'clinic-submit');
            $this->assertSame([201, 201], [$a['status'], $b['status']]);
            $this->assertSame(2, DB::table('clinic_appointments')->distinct()->count('appointment_reference'));
            $this->assertSame(0, DB::table('clinic_appointments')->whereNotNull('queue_number')->count());
            $ids = [$b['body']['appointment']['appointment_id'], $a['body']['appointment']['appointment_id']];
            [$first, $second] = $run([104, 103], [['id' => $ids[0]], ['id' => $ids[1]]], 'clinic-check-in');
            $this->assertSame([200, 200], [$first['status'], $second['status']]);
            $this->assertSame([1, 2], [$first['body']['appointment']['queue_number'], $second['body']['appointment']['queue_number']]);
            $before = DB::table('clinic_appointments')->where('id', $ids[0])->first();
            app(\App\Http\Controllers\AdminClinicController::class)->checkIn($ids[0], app(\App\Services\ClinicAppointmentSequence::class));
            $this->assertEquals($before, DB::table('clinic_appointments')->where('id', $ids[0])->first());
            // Dynamic cage limit: real overlapping physical admissions must not exceed it.
            $template = (array) DB::table('bookings')->where('status', 'checked_in')->first();
            $pet = (array) DB::table('booking_pets')->where('booking_id', $template['booking_id'])->first();
            unset($template['booking_id'], $pet['booking_pet_id']);
            $pending = [];
            foreach (['capacity-a', 'capacity-b'] as $reference) {
                $id = DB::table('bookings')->insertGetId(array_replace($template, [
                    'booking_reference' => $reference, 'status' => 'waiting_to_arrive', 'queue_number' => null, 'dropped_off_at' => null,
                ]), 'booking_id');
                DB::table('booking_pets')->insert(array_replace($pet, ['booking_id' => $id, 'pet_queue_number' => null, 'pet_queue_date' => null]));
                $pending[] = $id;
            }
            \App\Models\ClinicSetting::current()->update(['grooming_cage_capacity' => 3]);
            [$a, $b] = $run([101, 102], [['id' => $pending[0]], ['id' => $pending[1]]], 'grooming-check-in');
            $this->assertSame([200, 422], [$a['status'], $b['status']]);
            $this->assertSame(3, app(\App\Services\OperationalCapacity::class)->snapshot()['used']);
            // A concurrent limit reduction must see the just-admitted pet.
            DB::table('bookings')->where('booking_id', $pending[0])->update(['status' => 'waiting_to_arrive']);
            \App\Models\ClinicSetting::current()->update(['grooming_cage_capacity' => 4]);
            [$a, $b] = $run([101, 102], [['id' => $pending[0]], ['maximum' => 2]], ['grooming-check-in', 'capacity-update']);
            $this->assertSame([200, 422], [$a['status'], $b['status']]);
            $this->assertSame(4, \App\Models\ClinicSetting::current()->grooming_cage_capacity);
            // Reversing acquisition order makes admission observe the reduced maximum.
            DB::table('bookings')->where('booking_id', $pending[0])->update(['status' => 'waiting_to_arrive']);
            [$a, $b] = $run([101, 102], [['maximum' => 2], ['id' => $pending[1]]], ['capacity-update', 'grooming-check-in']);
            $this->assertSame([200, 422], [$a['status'], $b['status']]);
            $this->assertSame(2, app(\App\Services\OperationalCapacity::class)->snapshot()['used']);
            [$a, $b] = $run([101, 102], [['service' => 'clinic'], ['service' => 'clinic']], 'intake-stop');
            $this->assertSame([200, 200], [$a['status'], $b['status']]);
            $this->assertSame(1, DB::table('clinic_closures')->where('is_active', true)->where('service', 'clinic')->count());
            $this->assertSame(['clinic' => true, 'grooming' => false], \App\Models\ClinicClosure::stoppedToday());
            file_put_contents($directory.'/verification.json', json_encode([
                'driver' => DB::getDriverName(), 'database' => DB::connection()->getDatabaseName(),
                'overlap_observed' => true, 'grooming_submissions' => 2, 'clinic_submissions' => 2,
                'grooming_check_in_numbers' => [1, 2], 'clinic_check_in_numbers' => [1, 2],
                'premature_queue_numbers' => 0, 'retries_preserved_records' => true,
            ], JSON_THROW_ON_ERROR));
        } finally {
            Schema::dropIfExists('clinic_vitals'); Schema::dropIfExists('clinic_records');
        }
    }

    private function authenticateAs(string $role, string $subrole = 'grooming_receptionist'): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => match ($role) {
                'admin' => 1,
                'staff' => 2,
                default => 3,
            },
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
            'staff_type' => $role === 'staff' ? ($subrole === 'clinic_receptionist' ? 'clinic' : 'grooming') : null,
            'staff_subrole' => $role === 'staff' ? $subrole : null,
        ]);
        $user->exists = true;

        Sanctum::actingAs($user, ['*']);
    }

    private function seedMultiPetBooking(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'MULTI-PET-SECURITY',
            'booking_date' => now()->toDateString(),
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'dropped_off_at' => now(),
        ]);
        DB::table('pets')->insert([
            [
                'pet_id' => 1,
                'pet_name' => 'Zeus',
                'species' => 'dog',
            ],
            [
                'pet_id' => 2,
                'pet_name' => 'Ginger',
                'species' => 'cat',
            ],
        ]);
        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'pet_queue_date' => now()->toDateString(),
                'pet_queue_number' => 1,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'pet_queue_date' => now()->toDateString(),
                'pet_queue_number' => 2,
            ],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 1,
                'booking_id' => 1,
                'booking_pet_id' => 1,
                'service_id' => 1,
                'price_at_booking' => 250,
            ],
            [
                'booking_service_id' => 2,
                'booking_id' => 1,
                'booking_pet_id' => 2,
                'service_id' => 1,
                'price_at_booking' => 250,
            ],
        ]);
    }
}
