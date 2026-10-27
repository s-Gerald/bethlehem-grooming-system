<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminClinicController;
use App\Models\ClinicService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClinicAdministrationAuthorizationTest extends TestCase
{
    use \Tests\Support\CreatesPetGroomers;
    use \Tests\Support\SubmitsSignedGrooming;

    private const FORBIDDEN_RESPONSE = [
        'success' => false,
        'message' => 'Forbidden. You do not have permission to access this resource.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPetGroomers();
        Carbon::setTestNow(now()->setTime(10, 0));

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role');
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('password_hash')->nullable();
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
            $table->dateTime('archived_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('gender')->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_neutered')->nullable();
            $table->date('neutered_date')->nullable();
            $table->boolean('is_deceased')->default(false);
            $table->date('deceased_date')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->string('fur_type')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->dateTime('created_at')->nullable();
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

        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_reference')->unique();
            $table->string('appointment_type')->default('walk_in');
            $table->string('case_type', 30)->nullable();
            $table->string('status')->default('checked_in');
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->date('appointment_date');
            $table->dateTime('registration_closes_at')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->json('common_concerns')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('consultation_started_at')->nullable();
            $table->dateTime('consultation_finished_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('findings')->nullable();
            $table->text('treatment_given')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->text('vet_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable();
            $table->unsignedTinyInteger('body_condition_score')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_12_000002_extend_clinic_consultation_records.php'))->up();

        Schema::create('clinic_medications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('drug_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('duration')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_record_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
        });

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

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->string('window_label');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('max_slots')->default(4);
            $table->boolean('is_active')->default(true);
        });
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '1',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('window_id')->nullable();
            $table->time('arrival_end_time')->nullable();
            $table->date('booking_date');
            $table->dateTime('registration_closes_at')->nullable();
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->boolean('paid')->default(false);
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('cancel_count')->default(0);
            $table->text('special_notes')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->string('type', 50);
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id');
            $table->string('payment_status');
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id');
            $table->string('grooming_state')->default('not_started');
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('service_name');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id')->nullable();
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->nullable();
        });
        (require base_path('database/migrations/2026_10_10_000001_create_clinic_charges_table.php'))->up();
        (require database_path('migrations/2026_10_15_000002_add_clinic_cash_received.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('clinic_closures');
        Schema::dropIfExists('clinic_attachments');
        Schema::dropIfExists('clinic_medications');
        Schema::dropIfExists('clinic_vitals');
        Schema::dropIfExists('clinic_records');
        (require base_path('database/migrations/2026_10_10_000001_create_clinic_charges_table.php'))->down();
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('unregistered_customers');
        Schema::dropIfExists('users');

        Schema::dropIfExists('pet_groomers');
        parent::tearDown();
    }

    #[DataProvider('unauthenticatedClinicRoutes')]
    public function test_unauthenticated_visitors_cannot_access_clinic_administration(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->json($method, $uri, $payload)
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public static function unauthenticatedClinicRoutes(): array
    {
        return [
            'availability page' => ['GET', '/api/admin/availability/page'],
            'availability settings' => ['GET', '/api/admin/clinic/settings/availability'],
            'capacity edit' => ['PATCH', '/api/admin/clinic/settings/capacity', ['maximum' => 10]],
            'stop intake' => ['POST', '/api/admin/clinic/stop-today', ['service' => 'clinic']],
            'reopen intake' => ['POST', '/api/admin/clinic/reopen-today', ['service' => 'grooming']],
            'hours edit' => ['PATCH', '/api/admin/clinic/settings/availability', ['service' => 'clinic']],
            'blocked date list' => ['GET', '/api/admin/clinic/blocked-dates'],
            'blocked date add' => ['POST', '/api/admin/clinic/blocked-dates'],
            'blocked date remove' => ['DELETE', '/api/admin/clinic/blocked-dates/1'],
            'groomer add' => ['POST', '/api/admin/pet-groomers'],
            'groomer edit' => ['PATCH', '/api/admin/pet-groomers/1'],

            'current clinic case' => ['GET', '/api/admin/pets/1/clinic-case'],
            'appointment listing' => ['GET', '/api/admin/clinic-appointments'],
            'clinic archive listing' => ['GET', '/api/admin/clinic-appointments/archived'],
            'medical record modification' => ['POST', '/api/admin/clinic-appointments/1/record', [
                'diagnosis' => 'Must not be accepted',
            ]],
            'attachment upload' => ['POST', '/api/admin/clinic-appointments/1/attachments'],
            'attachment download' => ['GET', '/api/admin/clinic-appointments/1/attachments/1/download'],
            'attachment deletion' => ['DELETE', '/api/admin/clinic-appointments/1/attachments/1'],
        ];
    }

    #[DataProvider('customerForbiddenClinicRoutes')]
    public function test_customers_receive_the_same_forbidden_response_for_every_sensitive_clinic_action(
        string $method,
        string $uri,
        array $payload = [],
    ): void {
        $this->authenticateAs('customer');

        $this->json($method, $uri, $payload)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public static function customerForbiddenClinicRoutes(): array
    {
        return [
            'availability page' => ['GET', '/api/admin/availability/page'],
            'availability settings' => ['GET', '/api/admin/clinic/settings/availability'],
            'capacity edit' => ['PATCH', '/api/admin/clinic/settings/capacity', ['maximum' => 10]],
            'stop intake' => ['POST', '/api/admin/clinic/stop-today', ['service' => 'clinic']],
            'reopen intake' => ['POST', '/api/admin/clinic/reopen-today', ['service' => 'grooming']],
            'hours edit' => ['PATCH', '/api/admin/clinic/settings/availability', ['service' => 'clinic']],
            'blocked date list' => ['GET', '/api/admin/clinic/blocked-dates'],
            'blocked date add' => ['POST', '/api/admin/clinic/blocked-dates'],
            'blocked date remove' => ['DELETE', '/api/admin/clinic/blocked-dates/1'],
            'groomer add' => ['POST', '/api/admin/pet-groomers'],
            'groomer edit' => ['PATCH', '/api/admin/pet-groomers/1'],

            'current clinic case' => ['GET', '/api/admin/pets/1/clinic-case'],
            'view appointments and detailed records' => ['GET', '/api/admin/clinic-appointments'],
            'view archived clinic records' => ['GET', '/api/admin/clinic-appointments/archived'],
            'create clinic walk-in' => ['POST', '/api/admin/clinic-walk-in'],
            'check in appointment' => ['POST', '/api/admin/clinic-appointments/1/check-in'],
            'start consultation' => ['POST', '/api/admin/clinic-appointments/1/start-consultation'],
            'finish consultation' => ['POST', '/api/admin/clinic-appointments/1/finish-consultation'],
            'mark clinic payment' => ['POST', '/api/admin/clinic-appointments/1/pay'],
            'cancel appointment' => ['POST', '/api/admin/clinic-appointments/1/cancel'],
            'save records and vitals' => ['POST', '/api/admin/clinic-appointments/1/record', [
                'diagnosis' => 'Must not be accepted',
                'weight_kg' => 8.2,
            ]],
            'upload medical attachment' => ['POST', '/api/admin/clinic-appointments/1/attachments'],
            'download medical attachment' => ['GET', '/api/admin/clinic-appointments/1/attachments/1/download'],
            'delete medical attachment' => ['DELETE', '/api/admin/clinic-appointments/1/attachments/1'],
            'stop clinic operations' => ['POST', '/api/admin/clinic/stop-today'],
            'change clinic settings' => ['PATCH', '/api/admin/clinic/settings/groomers-on-duty', [
                'groomers_on_duty' => 3,
            ]],
            'view availability settings' => ['GET', '/api/admin/clinic/settings/availability'],
            'change availability settings' => ['PATCH', '/api/admin/clinic/settings/availability', [
                'service' => 'clinic',
                'open_time' => '08:00',
                'close_time' => '17:00',
                'pre_registration_cutoff_time' => '14:00',
            ]],
        ];
    }

    public static function receptionistRoles(): array
    {
        return [['clinic_receptionist'], ['grooming_receptionist']];
    }

    #[DataProvider('receptionistRoles')]
    public function test_receptionist_cannot_call_any_clinical_mutation_endpoint(string $subrole): void
    {
        $this->authenticateAs('staff', subrole: $subrole);
        foreach ([
            ['POST', '/api/admin/clinic-cases/1/start'],
            ['POST', '/api/admin/clinic-cases/1/finish'],
            ['POST', '/api/admin/clinic-cases/1/services'],
            ['DELETE', '/api/admin/clinic-cases/1/services/1'],
            ['POST', '/api/admin/clinic-appointments/1/start-consultation'],
            ['POST', '/api/admin/clinic-appointments/1/finish-consultation'],
            ['POST', '/api/admin/clinic-appointments/1/record'],
            ['POST', '/api/admin/clinic-appointments/1/attachments'],
            ['DELETE', '/api/admin/clinic-appointments/1/attachments/1'],
            ['POST', '/api/admin/pets/1/vaccinations'],
            ['PATCH', '/api/admin/pets/1/vaccinations/1'],
            ['POST', '/api/admin/pets/1/vaccinations/1/publish'],
            ['POST', '/api/admin/pets/1/vaccinations/1/void'],
            ['POST', '/api/admin/clinic-cases/1/confinement'],
            ['POST', '/api/admin/clinic-cases/1/confinement/charges'],
            ['POST', '/api/admin/clinic-cases/1/confinement/charges/1/stop'],
            ['DELETE', '/api/admin/clinic-cases/1/confinement/charges/1'],
            ['POST', '/api/admin/clinic-cases/1/confinement/observations'],
            ['POST', '/api/admin/clinic-cases/1/confinement/discharge'],
            ['PATCH', '/api/admin/clinic-cases/1/charges/1'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden()->assertExactJson(self::FORBIDDEN_RESPONSE);
        }
    }

    public function test_both_clinic_subroles_and_admin_can_check_in_and_view_the_shared_queue(): void
    {
        foreach (['veterinarian', 'clinic_receptionist', 'grooming_receptionist', 'admin'] as $index => $subrole) {
            $this->authenticateAs($subrole === 'admin' ? 'admin' : 'staff', subrole: $subrole);
            $this->insertClinicAppointment('waiting_to_arrive', id: $index + 1);
            $this->postJson('/api/admin/clinic-appointments/'.($index + 1).'/check-in')
                ->assertOk()->assertJsonPath('appointment.status', 'checked_in');
            $this->getJson('/api/admin/clinic-cases')->assertOk();
            $this->getJson('/api/admin/clinic-records')->assertOk();
        }
    }

    public function test_other_staff_and_missing_clinic_subroles_are_denied_and_me_exposes_permissions(): void
    {
        foreach (['', 'another_role'] as $subrole) {
            $this->authenticateAs('staff', subrole: $subrole);
            $this->getJson('/api/admin/clinic-cases')->assertForbidden();
            $this->postJson('/api/admin/clinic-appointments/1/check-in')->assertForbidden();
        }
        $this->authenticateAs('staff', staffType: 'grooming');
        $this->getJson('/api/admin/clinic-cases')->assertForbidden();
        $this->authenticateAs('staff');
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.staff_type', 'clinic')
            ->assertJsonPath('user.staff_subrole', 'veterinarian')
            ->assertJsonPath('user.clinic_permissions.clinical', true)
            ->assertJsonPath('user.clinic_permissions.billing_view', true)
            ->assertJsonPath('user.clinic_permissions.billing_edit', true)
            ->assertJsonPath('user.clinic_permissions.payment', true)
            ->assertJsonPath('user.clinic_permissions.customers', true)
            ->assertJsonPath('user.clinic_permissions.queue', true)
            ->assertJsonPath('user.clinic_permissions.services_view', true)
            ->assertJsonPath('user.clinic_permissions.services_manage', false);
        $this->authenticateAs('staff', subrole: 'clinic_receptionist');
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('user.clinic_permissions.clinical', false)
            ->assertJsonPath('user.clinic_permissions.billing_view', true)
            ->assertJsonPath('user.clinic_permissions.billing_edit', false)
            ->assertJsonPath('user.clinic_permissions.payment', true)
            ->assertJsonPath('user.clinic_permissions.services_view', true)
            ->assertJsonPath('user.clinic_permissions.services_manage', false);
        $this->authenticateAs('admin');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.clinic_permissions', [
            'access' => true, 'intake' => true, 'clinical' => true, 'billing_view' => true, 'billing_edit' => true,
            'payment' => true, 'customers' => true, 'queue' => true,
            'services_view' => true, 'services_manage' => true,
        ]);
        foreach ((new User(['role' => 'admin']))->clinicPermissions() as $permission) {
            $this->assertTrue($permission);
        }
    }

    public function test_partial_admission_prerequisite_save_preserves_other_record_fields_and_vitals(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        $url = '/api/admin/clinic-appointments/1/record';
        $this->postJson($url, [
            'chief_complaint' => 'Observe patient', 'vet_notes' => 'Existing notes', 'treatment_given' => 'Existing care',
            'follow_up_date' => now()->addWeek()->toDateString(), 'follow_up_notes' => 'Review later',
            'weight_kg' => '8.2', 'temperature_c' => 38.4, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20,
            'body_condition_score' => 5,
        ])->assertOk();
        $this->postJson($url, ['primary_veterinarian_id' => 1, 'weight_kg' => '18.4', 'finish_case' => false])->assertOk();
        $this->assertDatabaseHas('clinic_records', [
            'clinic_appointment_id' => 1, 'primary_veterinarian_id' => 1, 'chief_complaint' => 'Observe patient',
            'vet_notes' => 'Existing notes', 'treatment_given' => 'Existing care', 'follow_up_notes' => 'Review later',
            'follow_up_date' => now()->addWeek()->startOfDay()->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('clinic_vitals', [
            'clinic_appointment_id' => 1, 'weight_kg' => 18.4, 'temperature_c' => 38.4,
            'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20, 'body_condition_score' => 5,
        ]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'in_consultation']);
        $this->postJson($url, ['vet_notes' => null, 'temperature_c' => null])->assertOk();
        $this->assertDatabaseHas('clinic_records', ['clinic_appointment_id' => 1, 'vet_notes' => null]);
        $this->assertDatabaseHas('clinic_vitals', ['clinic_appointment_id' => 1, 'weight_kg' => 18.4, 'temperature_c' => null]);
    }

    #[DataProvider('receptionistRoles')]
    public function test_receptionist_creates_a_checked_in_case_without_starting_the_assessment(string $subrole): void
    {
        $this->authenticateAs('staff', subrole: $subrole);
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'species' => 'cat']);
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'consultation'])
            ->assertCreated()->assertJsonPath('case.status', 'checked_in')
            ->assertJsonPath('case.queue_number', 1)
            ->assertJsonPath('case.consultation_started_at', null);
        $this->postJson('/api/admin/clinic-cases/1/start')->assertForbidden();
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/clinic-cases/1/start')->assertOk()->assertJsonPath('case.status', 'in_consultation');
    }

    public function test_both_start_endpoints_require_check_in_and_queue_numbers_reset_by_clinic_date(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('checked_in', id: 10);
        DB::table('clinic_appointments')->where('id', 10)->update(['appointment_date' => now()->subDay()->toDateString(), 'queue_number' => 30]);
        foreach (['/api/admin/clinic-cases/1/start', '/api/admin/clinic-appointments/2/start-consultation'] as $index => $url) {
            $id = $index + 1;
            $this->insertClinicAppointment('waiting_to_arrive', id: $id);
            DB::table('clinic_appointments')->where('id', $id)->update(['queue_number' => null]);
            $this->postJson($url)->assertUnprocessable();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'status' => 'waiting_to_arrive', 'queue_number' => null, 'consultation_started_at' => null]);
            $this->postJson('/api/admin/clinic-appointments/'.$id.'/check-in')->assertOk()
                ->assertJsonPath('appointment.status', 'checked_in')->assertJsonPath('appointment.queue_number', $id);
            $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'consultation_started_at' => null]);
            $this->postJson($url)->assertOk();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'status' => 'in_consultation', 'queue_number' => $id]);
            $started = DB::table('clinic_appointments')->where('id', $id)->value('consultation_started_at');
            $this->postJson($url)->assertUnprocessable();
            $this->assertSame($started, DB::table('clinic_appointments')->where('id', $id)->value('consultation_started_at'));
            DB::table('clinic_appointments')->where('id', $id)->update(['status' => 'for_payment']);
            $this->postJson($url)->assertUnprocessable();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'status' => 'for_payment']);
        }
        Carbon::setTestNow(now()->addDay());
        $this->insertClinicAppointment('waiting_to_arrive', id: 3);
        DB::table('clinic_appointments')->where('id', 3)->update(['queue_number' => null]);
        $this->postJson('/api/admin/clinic-appointments/3/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 1);
    }

    public function test_unfinished_outpatients_remain_visible_with_payment_and_confinement_in_separate_lists(): void
    {
        $this->authenticateAs('admin');
        foreach ([1 => 'checked_in', 2 => 'in_consultation', 3 => 'for_payment', 4 => 'completed',
            5 => 'waiting_to_arrive', 6 => 'checked_in', 7 => 'waiting_to_arrive', 8 => 'in_consultation'] as $id => $status) {
            $this->insertClinicAppointment($status, id: $id);
            DB::table('clinic_appointments')->where('id', $id)->update([
                'appointment_date' => in_array($id, [5, 6]) ? now()->toDateString() : ($id === 7 ? now()->addDay()->toDateString() : now()->subDay()->toDateString()),
            ]);
        }
        $migrations = array_map(fn ($file) => require database_path('migrations/'.$file), [
            '2026_10_13_000001_create_clinic_services_table.php', '2026_10_13_000002_create_clinic_case_services_table.php',
            '2026_10_13_000004_create_clinic_confinements.php',
        ]);
        foreach ($migrations as $migration) $migration->up();
        try {
            $serviceId = DB::table('clinic_services')->insertGetId([
                'name' => 'Inpatient care', 'category_id' => 6, 'identity_key' => hash('sha256', 'inpatient'), 'pricing_method' => 'fixed', 'price' => 500,
            ]);
            $entryId = DB::table('clinic_case_services')->insertGetId([
                'clinic_appointment_id' => 8, 'clinic_service_id' => $serviceId, 'request_key' => (string) Str::uuid(), 'status' => 'ordered',
            ]);
            DB::table('clinic_confinements')->insert([
                'clinic_appointment_id' => 8, 'clinic_case_service_id' => $entryId, 'admitted_at' => now()->subDay(), 'reason' => 'Observe overnight',
            ]);
            $response = $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 1);
            $this->assertEqualsCanonicalizing([1, 2, 3, 6], array_column($response->json('cases'), 'id'));
            $response->assertJsonCount(1, 'arrivals')->assertJsonPath('arrivals.0.id', 5);
            $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('data.0.id', 8);
            $this->assertDatabaseCount('clinic_appointments', 8);
        } finally {
            foreach (array_reverse($migrations) as $migration) $migration->down();
        }
    }

    public function test_charge_migration_preserves_existing_pending_payment_amounts(): void
    {
        $migration = require base_path('database/migrations/2026_10_10_000001_create_clinic_charges_table.php');
        $migration->down();
        $this->insertClinicAppointment('for_payment');
        DB::table('clinic_appointments')->where('id', 1)->update(['total_amount' => 1250000]);
        $migration->up();
        $this->assertDatabaseHas('clinic_charges', ['clinic_appointment_id' => 1, 'kind' => 'existing', 'amount' => 1250000]);
        $this->authenticateAs('admin');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1250000, 'payment_method' => 'cash', 'amount_paid' => 1250000])
            ->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'paid' => false]);
    }

    public function test_consultation_charges_wait_for_receptionist_payment_and_reject_tampered_totals(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('checked_in');
        $this->postJson('/api/admin/clinic-appointments/1/start-consultation')->assertOk();
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Previously recorded charge',
            'quantity' => 1, 'unit_price' => 350, 'amount' => 350,
        ]);
        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Healthy', 'findings' => 'Stable', 'treatment_given' => 'Exam',
            'weight_kg' => 4.5, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20,
            'finish_case' => true,
        ])->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'paid' => false, 'total_amount' => 350]);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases');
        $this->getJson('/api/admin/clinic-cases/1')->assertOk()
            ->assertJsonPath('case.charges.0.description', 'Previously recorded charge');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1, 'payment_method' => 'cash', 'amount_paid' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->authenticateAs('staff', subrole: 'clinic_receptionist');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1, 'payment_method' => 'cash', 'amount_paid' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 350, 'payment_method' => 'cash', 'amount_paid' => 350])->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'completed', 'paid' => true, 'payment_method' => 'cash', 'paid_by_user_id' => 1]);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases');
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 350, 'payment_method' => 'cash', 'amount_paid' => 350])->assertUnprocessable();
    }

    #[DataProvider('receptionistRoles')]
    public function test_clinic_cash_payment_persists_server_receipt_and_authenticated_processor(string $subrole): void
    {
        $this->authenticateAs('staff', subrole: $subrole);
        $label = $subrole === 'clinic_receptionist' ? 'Clinic Receptionist' : 'Grooming Receptionist';
        $this->insertClinicAppointment('for_payment');
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Clinic consultation',
            'quantity' => 2, 'unit_price' => 175.25, 'amount' => 350.50,
        ]);
        $payload = ['total_amount' => 350.50, 'payment_method' => 'cash', 'amount_paid' => 500, 'paid_by_user_id' => 999];
        $receipt = $this->postJson('/api/admin/clinic-appointments/1/pay', $payload)->assertOk()
            ->assertJsonPath('appointment.status', 'completed')
            ->assertJsonPath('appointment.paid', true)
            ->assertJsonPath('appointment.cash_received', '500.00')
            ->assertJsonPath('appointment.change', 149.5)
            ->assertJsonPath('appointment.processed_by.name', 'Staff · '.$label)
            ->assertJsonPath('appointment.charges.0.description', 'Clinic consultation');
        $this->assertNotEmpty($receipt->json('appointment.paid_at'));
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'paid_by_user_id' => 1, 'cash_received' => 500]);
        $notificationCount = DB::table('notifications')->count();
        $this->postJson('/api/admin/clinic-appointments/1/pay', array_replace($payload, ['amount_paid' => 600]))->assertUnprocessable();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'cash_received' => 500]);
        $this->assertDatabaseCount('notifications', $notificationCount);
    }

    public function test_clinic_cash_validation_rejects_invalid_tender_without_recording_payment(): void
    {
        $this->authenticateAs('admin');
        $this->insertClinicAppointment('for_payment');
        DB::table('clinic_charges')->insert([
            'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Clinic consultation',
            'quantity' => 1, 'unit_price' => 350.50, 'amount' => 350.50,
        ]);
        foreach ([null, '', 'abc', -1, 350.49, '350.501', 2000.01, 1000000] as $received) {
            $this->postJson('/api/admin/clinic-appointments/1/pay', [
                'total_amount' => 350.50, 'payment_method' => 'cash', 'amount_paid' => $received,
            ])->assertUnprocessable()->assertJsonValidationErrors('amount_paid');
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'paid' => false, 'cash_received' => null]);
        }
        $this->postJson('/api/admin/clinic-appointments/1/pay', [
            'total_amount' => 350.50, 'payment_method' => 'cash', 'amount_paid' => 350.50,
        ])->assertOk()->assertJsonPath('appointment.change', 0);
    }

    public function test_both_finish_endpoints_preserve_the_payment_stage_for_admin(): void
    {
        $this->authenticateAs('admin');
        foreach (['/api/admin/clinic-cases/1/finish', '/api/admin/clinic-appointments/2/finish-consultation'] as $index => $uri) {
            $this->insertClinicAppointment('in_consultation', id: $index + 1);
            DB::table('clinic_appointments')->where('id', $index + 1)->update(['case_type' => 'vaccination']);
            $this->postJson($uri)->assertOk();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $index + 1, 'status' => 'for_payment', 'paid' => false]);
            $this->postJson('/api/admin/clinic-appointments/'.($index + 1).'/pay', ['total_amount' => 0, 'payment_method' => 'cash', 'amount_paid' => 0])->assertOk();
        }
    }

    public function test_all_clinic_roles_can_record_payment_and_manage_the_clinic_queue(): void
    {
        foreach (['admin', 'veterinarian', 'clinic_receptionist'] as $index => $subrole) {
            $this->authenticateAs($subrole === 'admin' ? 'admin' : 'staff', subrole: $subrole);
            $id = $index * 2 + 1;
            $this->insertClinicAppointment('for_payment', id: $id);
            $this->postJson('/api/admin/clinic-appointments/'.$id.'/pay', ['total_amount' => 0, 'payment_method' => 'cash', 'amount_paid' => 0])
                ->assertOk();
            $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'paid' => true, 'status' => 'completed']);
            $this->insertClinicAppointment('checked_in', id: $id + 1);
            $this->postJson('/api/admin/clinic-appointments/'.($id + 1).'/cancel')->assertOk();
        }
    }

    public function test_manual_services_feed_the_current_total_through_finish_payment_and_history(): void
    {
        $catalogMigration = require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php');
        $caseServiceMigration = require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php');
        $catalogMigration->up();
        $caseServiceMigration->up();
        try {
            $this->authenticateAs('staff');
            $this->insertClinicAppointment('checked_in');
            $this->postJson('/api/admin/clinic-cases/1/start')->assertOk();
            $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('cases.0.status', 'in_consultation');
            DB::table('clinic_charges')->insert([
                'clinic_appointment_id' => 1, 'kind' => 'existing', 'description' => 'Existing clinical charge',
                'quantity' => 1, 'unit_price' => 350, 'amount' => 350,
            ]);
            $entries = [];
            foreach (['CBC' => 990, 'Anti Rabies' => 650] as $name => $price) {
                $service = ClinicService::create([
                    'name' => $name, 'category_id' => 2, 'identity_key' => hash('sha256', Str::uuid()),
                    'pricing_method' => 'fixed', 'price' => $price, 'is_active' => true,
                ]);
                $entries[$name] = $this->postJson('/api/admin/clinic-cases/1/services', [
                    'clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid(),
                    'notes' => $name === 'CBC' ? 'Blood test requested' : '', 'applied_price' => 1,
                ])->assertCreated()->assertJsonPath('data.applied_price', number_format($price, 2, '.', ''))->json('data.id');
            }
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 1990);
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$entries['CBC'])->assertOk();
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 1000);
            $this->assertDatabaseMissing('clinic_charges', ['clinic_case_service_id' => $entries['CBC']]);
            $this->assertDatabaseHas('clinic_charges', ['description' => 'Existing clinical charge', 'amount' => 350]);

            $this->postJson('/api/admin/clinic-appointments/1/record', ['finish_case' => true])
                ->assertUnprocessable()->assertJsonValidationErrors(['weight_kg', 'temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm']);
            $this->postJson('/api/admin/clinic-appointments/1/record', [
                'diagnosis' => 'Healthy', 'finish_case' => true,
                'weight_kg' => 4.5, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20,
            ])->assertOk();
            $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases')
                ->assertJsonPath('cases.0.status', 'for_payment')->assertJsonPath('cases.0.paid', false)
                ->assertJsonPath('cases.0.total_amount', 1000);
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'paid' => false, 'total_amount' => 1000]);
            $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1, 'payment_method' => 'cash', 'amount_paid' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');

            $this->authenticateAs('staff', subrole: 'clinic_receptionist');
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 1000);
            $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1990, 'payment_method' => 'cash', 'amount_paid' => 1990])
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
            $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 1000, 'payment_method' => 'cash', 'amount_paid' => 1000])->assertOk();
            $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases');
            $this->getJson('/api/admin/clinic-appointments/archived')->assertOk()
                ->assertJsonPath('archived.0.status', 'completed')->assertJsonPath('archived.0.total_amount', 1000)
                ->assertJsonPath('archived.0.paid', true);
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'completed', 'paid' => true, 'total_amount' => 1000]);
            $this->authenticateAs('staff');
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$entries['Anti Rabies'])->assertUnprocessable();
            $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $entries['Anti Rabies'], 'amount' => 650]);
        } finally {
            $caseServiceMigration->down();
            $catalogMigration->down();
        }
    }

    public function test_removed_clinical_product_endpoints_are_unavailable_for_every_role(): void
    {
        foreach ([['admin', 'veterinarian'], ['staff', 'veterinarian'], ['staff', 'clinic_receptionist'], ['customer', '']] as [$role, $subrole]) {
            $this->authenticateAs($role, subrole: $subrole);
            $this->getJson('/api/admin/clinic/clinical-items')->assertNotFound();
            $this->postJson('/api/admin/clinic-appointments/1/products', ['item_id' => 1, 'quantity' => 2])->assertNotFound();
        }
    }

    public function test_stale_medication_and_service_payloads_cannot_create_or_modify_entries(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $this->authenticateAs($role);
            $id = $role === 'admin' ? 1 : 2;
            $this->insertClinicAppointment('in_consultation', id: $id);
            $recordId = DB::table('clinic_records')->insertGetId(['clinic_appointment_id' => $id]);
            DB::table('clinic_medications')->insert(['clinic_record_id' => $recordId, 'drug_name' => 'Historical entry']);
            $this->postJson("/api/admin/clinic-appointments/{$id}/record", [
                'diagnosis' => 'Saved diagnosis',
                'medications' => [['drug_name' => 'Removed entry']],
                'services' => [['description' => 'Removed service', 'amount' => 100]],
            ])->assertOk()->assertJsonPath('record.diagnosis', 'Saved diagnosis')->assertJsonMissingPath('record.medications');
            $this->assertDatabaseHas('clinic_medications', ['clinic_record_id' => $recordId, 'drug_name' => 'Historical entry']);
        }
        $this->assertDatabaseCount('clinic_medications', 2);
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->authenticateAs('staff', subrole: 'clinic_receptionist');
        $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnosis' => 'Forbidden'])->assertForbidden();
    }

    public function test_staff_can_list_clinic_appointments_and_save_a_medical_record(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation');

        $this->getJson('/api/admin/clinic-appointments')
            ->assertOk()
            ->assertJsonPath('in_consultation.0.appointment_reference', 'CL-20260722-001');

        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Authorized test diagnosis',
            'weight_kg' => 8.2,
            'temperature_c' => 38.4,
        ])
            ->assertOk()
            ->assertJsonPath('record.diagnosis', 'Authorized test diagnosis')
            ->assertJsonMissingPath('record.medications');

        $this->assertDatabaseHas('clinic_vitals', [
            'clinic_appointment_id' => 1,
            'weight_kg' => 8.2,
        ]);
    }

    public function test_admin_clinic_archive_lists_only_terminal_visits_with_full_existing_record_data(): void
    {
        $this->authenticateAs('admin');

        Schema::create('vaccination_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('voided_at')->nullable();
        });
        Schema::create('clinic_case_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clinic_appointment_id');
            $table->string('status');
        });
        DB::table('vaccination_records')->insert([
            ['clinic_appointment_id' => 1, 'published_at' => now(), 'voided_at' => null],
            ['clinic_appointment_id' => 1, 'published_at' => null, 'voided_at' => null],
            ['clinic_appointment_id' => 1, 'published_at' => now(), 'voided_at' => now()],
            ['clinic_appointment_id' => 2, 'published_at' => now(), 'voided_at' => null],
        ]);
        DB::table('clinic_case_services')->insert([
            ['clinic_appointment_id' => 1, 'status' => 'performed'],
            ['clinic_appointment_id' => 1, 'status' => 'cancelled'],
            ['clinic_appointment_id' => 1, 'status' => 'ordered'],
            ['clinic_appointment_id' => 2, 'status' => 'performed'],
        ]);

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
            'phone' => '09171234567',
            'role' => 'customer',
            'account_deleted_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'gender' => 'female',
            'weight' => 4.5,
            'medical_conditions' => 'Asthma',
            'is_archived' => false,
        ]);

        $this->insertClinicAppointment(status: 'completed', id: 1);
        DB::table('clinic_appointments')->where('id', 1)->update([
            'appointment_type' => 'pre_registered',
            'window_id' => 1,
            'user_id' => 10,
            'pet_id' => 101,
            'total_amount' => 750,
            'paid' => true,
            'consultation_finished_at' => now(),
            'updated_at' => now()->addHour(),
        ]);
        $this->insertClinicAppointment(status: 'cancelled', id: 2);
        $this->insertClinicAppointment(status: 'no_show', id: 3);
        $this->insertClinicAppointment(status: 'in_consultation', id: 4);

        $recordId = DB::table('clinic_records')->insertGetId([
            'clinic_appointment_id' => 1,
            'chief_complaint' => 'Breathing concern',
            'diagnosis' => 'Mild irritation',
            'findings' => 'Stable on examination',
            'treatment_given' => 'Supportive care',
            'follow_up_date' => now()->addWeek()->toDateString(),
            'follow_up_notes' => 'Return if symptoms worsen',
            'vet_notes' => 'Monitor at home',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_vitals')->insert([
            'clinic_appointment_id' => 1,
            'weight_kg' => 4.5,
            'temperature_c' => 38.2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $recordId,
            'drug_name' => 'Test medication',
            'dosage' => '1 tablet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/clinic-appointments/archived')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('archived.0.appointment_type_label', 'Online Registration')
            ->assertJsonPath('archived.0.time_window.window_label', '8:00 AM - 9:00 AM')
            ->assertJsonPath('archived.0.final_status_label', 'Completed')
            ->assertJsonPath('archived.0.treatment_count', 1)
            ->assertJsonPath('archived.0.service_count', 1)
            ->assertJsonPath('archived.0.payment_status_label', 'Paid')
            ->assertJsonPath('archived.0.owner.name', 'Maria Santos')
            ->assertJsonPath('archived.0.owner.account_deleted', true)
            ->assertJsonPath('archived.0.ownerAccountDeleted', true)
            ->assertJsonPath('archived.0.contactNumber', '—')
            ->assertJsonPath('archived.0.owner.email', null)
            ->assertJsonPath('archived.0.pet.name', 'Mochi')
            ->assertJsonPath('archived.0.pet.medical_conditions', 'Asthma')
            ->assertJsonPath('archived.0.record.diagnosis', 'Mild irritation')
            ->assertJsonMissingPath('archived.0.record.medications')
            ->assertJsonPath('archived.0.vitals.temperature_c', '38.2')
            ->assertJsonPath('archived.0.assigned_veterinarian', null)
            ->assertJsonPath('archived.0.activity.created_by_name', null);

        $this->assertNotContains(4, collect($response->json('archived'))->pluck('id')->all());

        DB::table('clinic_records')->where('id', $recordId)->update([
            'chief_complaint' => 'Itching around ears',
            'working_diagnosis' => 'Otitis externa',
        ]);
        foreach (['Itching around ears', 'Otitis externa'] as $search) {
            $this->getJson('/api/admin/clinic-appointments/archived?search='.urlencode($search))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('archived.0.id', 1);
        }

        $this->getJson('/api/admin/clinic-appointments/archived?search=Mochi')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('archived.0.id', 1);
    }

    #[DataProvider('decimalClinicPetWeights')]
    public function test_clinic_walkin_uses_shared_decimal_size(string $species, mixed $weight, string $size): void
    {
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'], 'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'pet_name' => 'Decimal pet', 'species' => $species, 'weight' => $weight,
            'size' => $size === 'small' ? 'medium' : 'small', 'terms_agreed' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('pets', ['pet_name' => 'Decimal pet', 'weight' => $weight, 'size' => $size]);
    }

    public static function decimalClinicPetWeights(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/weight-rules.json'), true)['pets'];
    }

    public function test_clinic_walkin_rejects_invalid_weight_text_for_admin_and_staff(): void
    {
        $invalid = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true)['invalid_weights'];
        foreach (['admin', 'staff'] as $role) {
            $this->authenticateAs($role);
            foreach ($invalid as $weight) {
                $this->postJson('/api/admin/clinic-walk-in', ['common_concerns' => ['Routine check-up'],
                    'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
                    'pet_name' => 'Invalid decimal', 'species' => 'Dog', 'weight' => $weight, 'terms_agreed' => true])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight');
            }
        }
        $this->assertDatabaseCount('pets', 0);
        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_clinic_walkin_can_select_a_historical_pet_without_changing_its_weight(): void
    {
        DB::table('users')->insert(['user_id' => 10, 'first_name' => 'Maria', 'last_name' => 'Santos',
            'phone' => '09171234567', 'role' => 'customer']);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Historical dog',
            'species' => 'Dog', 'weight' => 10.999, 'size' => 'small', 'is_archived' => false]);
        $this->authenticateAs('staff');
        $this->postJson('/api/admin/clinic-walk-in', ['common_concerns' => ['Routine check-up'],
            'fname' => 'Maria', 'lname' => 'Santos', 'phone' => '09171234567',
            'owner_record_type' => 'registered', 'customer_user_id' => 10,
            'pet_id' => 101, 'pet_name' => 'Historical dog', 'species' => 'Dog', 'terms_agreed' => true])->assertCreated();
        $this->assertDatabaseHas('pets', ['pet_id' => 101, 'weight' => 10.999, 'size' => 'small']);
    }

    public function test_clinic_vitals_share_precision_limits_and_preserve_records_on_invalid_input(): void
    {
        $this->insertClinicAppointment('in_consultation');
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/weight-rules.json')), true);
        foreach (['admin', 'staff'] as $role) {
            $this->authenticateAs($role);
            foreach ($cases['invalid_weights'] as $weight) {
                $this->postJson('/api/admin/clinic-appointments/1/record', ['weight_kg' => $weight, 'diagnosis' => 'Do not save'])
                    ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
            }
        }
        $this->assertDatabaseCount('clinic_records', 0);
        $this->assertDatabaseCount('clinic_vitals', 0);
        foreach (['4', '4.', '4.1', '4.20', '4.25', '0.50', '155.6'] as $weight) {
            $this->postJson('/api/admin/clinic-appointments/1/record', ['weight_kg' => $weight])->assertOk();
            $this->assertDatabaseHas('clinic_vitals', ['clinic_appointment_id' => 1, 'weight_kg' => (float) $weight]);
        }
    }

    public function test_phone_less_customer_uses_visit_contact_without_updating_account(): void
    {
        $this->authenticateAs('staff');
        DB::table('users')->insert(['user_id' => 50, 'first_name' => 'Test', 'last_name' => 'Owner', 'role' => 'customer', 'phone' => null]);
        $payload = [
            'fname' => 'Test', 'lname' => 'Owner', 'phone' => '',
            'owner_record_type' => 'registered', 'customer_user_id' => 50,
            'pet_name' => 'Buddy', 'species' => 'Dog', 'common_concerns' => ['Routine check-up'], 'terms_agreed' => true,
        ];
        $this->postJson('/api/admin/clinic-walk-in', $payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $payload['phone'] = '09179998888';
        $this->postJson('/api/admin/clinic-walk-in', $payload)->assertCreated();
        $this->assertDatabaseHas('walkins', ['user_id' => 50, 'phone' => '09179998888']);
        $this->assertDatabaseHas('users', ['user_id' => 50, 'phone' => null]);
        $this->assertDatabaseHas('pets', ['pet_name' => 'Buddy', 'user_id' => 50]);
    }

    public function test_staff_can_create_a_clinic_walk_in(): void
    {
        $this->authenticateAs('staff');

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up', 'Vaccination'],
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pet_name' => 'Bantay',
            'species' => 'dog',
            'weight' => 12,
            'size' => 'medium',
            'chief_complaint' => 'Routine consultation',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'checked_in')
            ->assertJsonPath('queue_number', 1)
            ->assertJsonPath('pet.name', 'Bantay')
            ->assertJsonPath('common_concerns', ['Routine check-up', 'Vaccination'])
            ->assertJsonPath('chief_complaint', "Routine check-up, Vaccination\nRoutine consultation");

        $this->assertSame(
            ['Routine check-up', 'Vaccination'],
            json_decode(DB::table('clinic_appointments')->value('common_concerns'), true),
        );
        $this->assertDatabaseHas('pets', ['pet_name' => 'Bantay', 'size' => 'medium', 'weight' => 12]);
        $this->assertSame(['size'], json_decode(DB::table('pets')->value('clinic_verified_fields'), true));
    }

    #[DataProvider('receptionistRoles')]
    public function test_receptionist_cancellation_rechecks_current_status_and_preserves_other_roles(string $receptionist): void
    {
        $this->authenticateAs('staff', subrole: $receptionist);
        foreach (['checked_in', 'in_consultation', 'for_payment', 'completed', 'waiting_to_arrive', 'cancelled', 'expired'] as $index => $status) {
            $id = $index + 1;
            $this->insertClinicAppointment($status, $id);
            $response = $this->postJson("/api/admin/clinic-appointments/{$id}/cancel");
            if ($status === 'checked_in') {
                $response->assertOk();
                $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'status' => 'cancelled']);
            } else {
                $response->assertConflict();
                $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'status' => $status]);
            }
        }
        // The UI may still show checked_in, but another vet has already started this case.
        $this->insertClinicAppointment('checked_in', 10);
        $this->authenticateAs('staff', subrole: 'veterinarian');
        $this->postJson('/api/admin/clinic-cases/10/start')->assertOk();
        $this->authenticateAs('staff', subrole: $receptionist);
        $this->postJson('/api/admin/clinic-appointments/10/cancel')->assertConflict();
        foreach (['veterinarian', 'admin'] as $subrole) {
            $this->authenticateAs($subrole === 'admin' ? 'admin' : 'staff', subrole: $subrole);
            DB::table('clinic_appointments')->where('id', 10)->update(['status' => 'in_consultation']);
            $this->postJson('/api/admin/clinic-appointments/10/cancel')->assertOk();
        }
    }

    #[DataProvider('receptionistRoles')]
    public function test_receptionist_cannot_edit_existing_patients_through_the_api(string $subrole): void
    {
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'species' => 'cat']);
        $this->authenticateAs('staff', subrole: $subrole);
        $this->putJson('/api/admin/pets/1', ['pet_name' => 'Changed'])->assertForbidden();
        $this->assertDatabaseHas('pets', ['pet_id' => 1, 'pet_name' => 'Mochi']);
    }

    #[DataProvider('receptionistRoles')]
    public function test_walkins_check_in_sequentially_without_starting_and_reject_duplicate_intake(string $subrole): void
    {
        $this->authenticateAs('staff', subrole: $subrole);
        DB::table('unregistered_customers')->insert(['id' => 10, 'first_name' => 'Ana', 'last_name' => 'Santos', 'phone' => '09171234567']);
        $payload = ['common_concerns' => ['Routine check-up'], 'fname' => 'Ana', 'lname' => 'Santos',
            'phone' => '09171234567', 'pet_name' => 'Mochi', 'species' => 'cat', 'terms_agreed' => true,
            'owner_record_type' => 'unregistered', 'unregistered_customer_id' => 10];
        $first = $this->postJson('/api/admin/clinic-walk-in', $payload)->assertCreated()
            ->assertJsonPath('status', 'checked_in')->assertJsonPath('queue_number', 1);
        $id = $first->json('appointment_id');
        $this->assertNotEmpty($first->json('checked_in_at'));
        $this->assertDatabaseHas('clinic_appointments', ['id' => $id, 'consultation_started_at' => null]);
        $this->postJson('/api/admin/clinic-walk-in', [...$payload, 'pet_name' => 'Bantay'])
            ->assertCreated()->assertJsonPath('queue_number', 2);
        $petId = DB::table('clinic_appointments')->where('id', $id)->value('pet_id');
        $ownerId = DB::table('pets')->where('pet_id', $petId)->value('unregistered_customer_id');
        $duplicate = [...$payload, 'owner_record_type' => 'unregistered', 'unregistered_customer_id' => $ownerId, 'pet_id' => $petId];
        foreach (['checked_in', 'in_consultation', 'for_payment', 'waiting_to_arrive'] as $status) {
            DB::table('clinic_appointments')->where('id', $id)->update(['status' => $status]);
            $this->postJson('/api/admin/clinic-walk-in', $duplicate)->assertConflict();
            $this->assertDatabaseCount('clinic_appointments', 2);
            $this->assertDatabaseCount('walkins', 2);
        }
        DB::table('clinic_appointments')->where('id', $id)->update(['status' => 'checked_in']);
        $this->postJson("/api/admin/clinic-cases/{$id}/start")->assertForbidden();
        $this->authenticateAs('staff');
        $this->postJson("/api/admin/clinic-cases/{$id}/start")->assertOk()->assertJsonPath('case.status', 'in_consultation');
    }

    public function test_clinic_receipts_use_linked_processor_identity_across_roles_and_identity_changes(): void
    {
        foreach (['clinic_receptionist' => 'Clinic Receptionist', 'veterinarian' => 'Veterinarian', 'admin' => 'Admin'] as $subrole => $label) {
            $this->authenticateAs($subrole === 'admin' ? 'admin' : 'staff', subrole: $subrole);
            $id = DB::table('clinic_appointments')->count() + 1;
            $this->insertClinicAppointment('for_payment', $id);
            $first = $subrole === 'admin' ? 'Admin' : 'Staff';
            $this->postJson("/api/admin/clinic-appointments/{$id}/pay", ['total_amount' => 0, 'amount_paid' => 0, 'payment_method' => 'cash'])
                ->assertOk()->assertJsonPath('appointment.processed_by.name', "{$first} · {$label}");
            DB::table('users')->where('user_id', 1)->update(['first_name' => 'Renamed', 'is_active' => false]);
            $this->getJson("/api/admin/clinic-cases/{$id}")->assertOk()
                ->assertJsonPath('case.processed_by.name', "Renamed · {$label}");
            DB::table('clinic_appointments')->where('id', $id)->update(['paid_by_user_id' => 999]);
            $this->getJson("/api/admin/clinic-cases/{$id}")->assertOk()->assertJsonPath('case.processed_by.name', null);
        }
    }

    public function test_staff_weight_update_replaces_a_verified_size(): void
    {
        $this->authenticateAs('staff');
        DB::table('pets')->insert([
            'pet_id' => 501,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'weight' => 8,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size']),
        ]);

        $this->putJson('/api/admin/pets/501', [
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ])
            ->assertOk()
            ->assertJsonPath('pet.size', 'medium');

        $this->assertDatabaseHas('pets', ['pet_id' => 501, 'weight' => 20, 'size' => 'medium']);
        $this->assertSame(['size', 'weight'], json_decode(DB::table('pets')->where('pet_id', 501)->value('clinic_verified_fields'), true));
    }

    public function test_customer_weight_sets_estimated_size_even_if_a_different_size_is_sent(): void
    {
        $this->authenticateAs('customer', 10);

        $this->postJson('/api/pets', [
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'small',
        ])
            ->assertCreated()
            ->assertJsonPath('pet.size', 'medium')
            ->assertJsonPath('pet.clinic_verified_fields', null);
    }

    public function test_valid_weight_replaces_a_contradictory_verified_profile_size(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 502,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 8,
            'size' => 'small',
            'clinic_verified_fields' => json_encode(['size']),
        ]);

        $this->putJson('/api/pets/502', [
            'pet_name' => 'Mochi',
            'species' => 'Dog',
            'weight' => 20,
            'size' => 'medium',
        ])
            ->assertOk()
            ->assertJsonPath('pet.size', 'medium');

        $this->assertNull(DB::table('pets')->where('pet_id', 502)->value('clinic_verified_fields'));
    }

    public function test_walk_in_requires_valid_distinct_common_concerns(): void
    {
        $this->authenticateAs('staff');
        $payload = [
            'fname' => 'Maria',
            'lname' => 'Santos',
            'phone' => '09171234567',
            'pet_name' => 'Bantay',
            'species' => 'dog',
            'terms_agreed' => true,
        ];

        $this->postJson('/api/admin/clinic-walk-in', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns');
        $this->postJson('/api/admin/clinic-walk-in', $payload + ['common_concerns' => ['Other', 'Other']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');
        $this->postJson('/api/admin/clinic-walk-in', $payload + ['common_concerns' => ['Unknown']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_clinic_cases_reuse_one_open_case_per_pet_and_finish_with_saved_record(): void
    {
        $this->authenticateAs('staff');
        DB::table('clinic_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        DB::table('unregistered_customers')->insert([
            'id' => 1, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '09171234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pets')->insert(['pet_id' => 1, 'unregistered_customer_id' => 1, 'pet_name' => 'Mochi', 'species' => 'Dog']);

        $caseId = $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'consultation'])
            ->assertCreated()
            ->assertJsonPath('case.queue_number', 1)
            ->json('case.id');
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'vaccination'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_case_exists')
            ->assertJsonPath('case.id', $caseId)
            ->assertJsonPath('case.case_type', 'consultation');
        $this->assertSame(1, DB::table('clinic_appointments')->count());

        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases');
        $this->postJson("/api/admin/clinic-cases/{$caseId}/start")->assertOk();

        $this->postJson("/api/admin/clinic-appointments/{$caseId}/record", [
            'diagnosis' => 'Healthy', 'finish_case' => true, 'weight_kg' => 4.5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm'])
            ->assertJsonMissingValidationErrors(['weight_kg', 'body_condition_score']);
        $this->assertNotSame('completed', DB::table('clinic_appointments')->where('id', $caseId)->value('status'));

        $this->postJson("/api/admin/clinic-appointments/{$caseId}/record", [
            'diagnosis' => 'Healthy', 'follow_up_date' => now()->addMonths(3)->addDay()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['follow_up_date']);

        $this->postJson("/api/admin/clinic-appointments/{$caseId}/record", [
            'diagnosis' => 'Healthy', 'follow_up_date' => now()->subDay()->toDateString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['follow_up_date']);

        $this->postJson("/api/admin/clinic-appointments/{$caseId}/record", [
            'diagnosis' => 'Healthy', 'finish_case' => true, 'follow_up_date' => now()->addMonths(3)->toDateString(),
            'weight_kg' => 4.5, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20,
        ])->assertOk();

        $this->assertSame('for_payment', DB::table('clinic_appointments')->where('id', $caseId)->value('status'));
        $this->assertSame('Healthy', DB::table('clinic_records')->where('clinic_appointment_id', $caseId)->value('diagnosis'));
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases');
    }

    public function test_expected_arrival_moves_through_check_in_consultation_payment_and_completion(): void
    {
        Carbon::setTestNow('2026-10-19 08:30:00');
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'cat']);
        $id = $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up'], 'chief_complaint' => 'Annual exam',
            'appointment_date' => now()->toDateString(), 'window_id' => 1, 'pet_id' => 101,
        ])->assertCreated()->json('appointment.appointment_id');

        $this->authenticateAs('staff');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')
            ->assertJsonCount(1, 'arrivals')->assertJsonPath('arrivals.0.id', $id)
            ->assertJsonPath('arrivals.0.final_status_label', 'Waiting for Arrival')
            ->assertJsonPath('arrivals.0.pet.name', 'Mochi')
            ->assertJsonPath('arrivals.0.ownerName', 'Customer User')
            ->assertJsonPath('arrivals.0.time_window', null)
            ->assertJsonPath('arrivals.0.chief_complaint', "Routine check-up\nAnnual exam");
        $this->assertSame(0, app(\App\Services\OperationalCapacity::class)->waitingCount());
        $this->postJson("/api/admin/clinic-appointments/{$id}/check-in")->assertOk()
            ->assertJsonPath('appointment.queue_number', 1);
        $this->assertSame(1, app(\App\Services\OperationalCapacity::class)->waitingCount());
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'arrivals')
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.status', 'checked_in');
        $this->postJson("/api/admin/clinic-cases/{$id}/start")->assertOk()->assertJsonPath('case.status', 'in_consultation');
        $this->postJson("/api/admin/clinic-appointments/{$id}/record", [
            'diagnosis' => 'Healthy', 'finish_case' => true,
            'weight_kg' => 4.5, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20,
        ])->assertOk();
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'arrivals')
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.status', 'for_payment');
        $this->postJson("/api/admin/clinic-appointments/{$id}/pay", [
            'total_amount' => 0, 'payment_method' => 'cash', 'amount_paid' => 0,
        ])->assertOk()->assertJsonPath('appointment.status', 'completed')->assertJsonPath('appointment.paid', true);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'arrivals')->assertJsonCount(0, 'cases');
        $this->getJson('/api/admin/pets/101/clinic-case')->assertOk()->assertJsonPath('case', null)->assertJsonPath('arrival', null);
    }

    public function test_clinic_submission_requires_review_after_closing_without_creating_a_visit(): void
    {
        Carbon::setTestNow('2026-10-19 17:00:00');
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'cat']);
        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'service_day' => '2026-10-19', 'pet_id' => 101, 'common_concerns' => ['Routine check-up'],
        ])->assertStatus(409)->assertJsonPath('code', 'service_day_changed')
            ->assertJsonPath('availability.service_day', '2026-10-20');
        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_cancelled_and_no_show_arrivals_leave_expected_while_future_registrations_still_block_intake(): void
    {
        $this->authenticateAs('staff');
        DB::table('pets')->insert(['pet_id' => 101, 'user_id' => 1, 'pet_name' => 'Mochi', 'species' => 'cat']);
        foreach (range(1, 3) as $id) {
            $this->insertClinicAppointment('waiting_to_arrive', $id);
            DB::table('clinic_appointments')->where('id', $id)->update([
                'pet_id' => $id === 3 ? 101 : null, 'queue_number' => null,
                'appointment_date' => now()->addDays($id - 2)->toDateString(),
            ]);
        }
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')
            ->assertJsonCount(1, 'arrivals')->assertJsonPath('arrivals.0.id', 2);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'no_show']);
        $this->postJson('/api/admin/clinic-appointments/2/cancel')->assertOk();
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')->assertJsonCount(0, 'arrivals');
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 101, 'case_type' => 'consultation'])
            ->assertStatus(409)->assertJsonPath('code', 'pre_registration_exists')->assertJsonPath('arrival.id', 3);
        $this->postJson('/api/admin/clinic-appointments/3/check-in')->assertUnprocessable();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 3, 'status' => 'waiting_to_arrive', 'queue_number' => null]);
        $this->assertSame(0, \App\Models\ClinicAppointment::operational()->count());
    }

    public function test_online_request_assigns_its_queue_number_only_when_staff_starts_physical_intake(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('waiting_to_arrive');
        DB::table('clinic_appointments')->where('id', 1)->update(['appointment_type' => 'pre_registered', 'queue_number' => null]);

        $this->getJson('/api/admin/clinic-cases')
            ->assertOk()
            ->assertJsonCount(0, 'cases')
            ->assertJsonCount(1, 'arrivals')
            ->assertJsonPath('arrivals.0.case_type', 'online_request')
            ->assertJsonPath('arrivals.0.queue_number', null);

        $this->assertSame(0, \App\Models\ClinicAppointment::operational()->count());
        $this->assertSame(1, \App\Models\ClinicAppointment::blocking()->count());

        DB::table('unregistered_customers')->insert([
            'id' => 1, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '09171234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pets')->insert(['pet_id' => 1, 'unregistered_customer_id' => 1, 'pet_name' => 'Mochi', 'species' => 'Dog']);
        DB::table('clinic_appointments')->where('id', 1)->update(['pet_id' => 1]);
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()
            ->assertJsonPath('case', null)->assertJsonPath('arrival.id', 1);
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 1, 'case_type' => 'vaccination'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'pre_registration_exists')
            ->assertJsonPath('arrival.id', 1)
            ->assertJsonMissingPath('case')
            ->assertJsonPath('message', 'Mochi already has an online registration waiting for arrival (CL-20260722-001).');
        $this->assertSame('waiting_to_arrive', DB::table('clinic_appointments')->where('id', 1)->value('status'));

        $this->postJson('/api/admin/clinic-cases/1/start')
            ->assertUnprocessable();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'waiting_to_arrive', 'queue_number' => null, 'consultation_started_at' => null]);
        $this->postJson('/api/admin/clinic-appointments/1/check-in')->assertOk()
            ->assertJsonPath('appointment.status', 'checked_in')->assertJsonPath('appointment.queue_number', 1);
        $this->getJson('/api/admin/clinic-cases')->assertOk()
            ->assertJsonCount(0, 'arrivals')->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.status', 'checked_in');
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()
            ->assertJsonPath('arrival', null)->assertJsonPath('case.id', 1);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'consultation_started_at' => null]);
        $this->postJson('/api/admin/clinic-cases/1/start')
            ->assertOk()
            ->assertJsonPath('case.status', 'in_consultation')
            ->assertJsonPath('case.queue_number', 1);
        $this->assertNotNull(DB::table('clinic_appointments')->where('id', 1)->value('checked_in_at'));
    }

    #[DataProvider('ongoingClinicStatuses')]
    public function test_clinic_case_lifecycle_keeps_previous_day_cases_visible_and_blocks_duplicate_intake(string $status): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->authenticateAs('staff');
        DB::table('pets')->insert(['pet_id' => 80, 'user_id' => 1, 'pet_name' => 'Gummy', 'species' => 'Dog']);
        $this->insertClinicAppointment($status, 56, 'CL-20261016-001');
        DB::table('clinic_appointments')->where('id', 56)->update([
            'pet_id' => 80, 'user_id' => 1, 'checked_in_at' => now(),
        ]);
        $original = (array) DB::table('clinic_appointments')->where('id', 56)->first();

        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(1, 'cases')
            ->assertJsonPath('today', '2026-10-17')->assertJsonPath('cases.0.id', 56)
            ->assertJsonPath('cases.0.status', $status)->assertJsonPath('cases.0.started_on', '2026-10-16');
        $this->getJson('/api/admin/pets/80/clinic-case')->assertOk()
            ->assertJsonPath('case.id', 56)->assertJsonPath('case.status', $status);
        foreach (range(1, 2) as $attempt) {
            $this->postJson('/api/admin/clinic-cases', ['pet_id' => 80, 'case_type' => 'consultation'])
                ->assertStatus(409)->assertJsonPath('code', 'active_case_exists')->assertJsonPath('case.id', 56);
        }
        $this->assertDatabaseCount('clinic_appointments', 1);
        $this->assertSame($original, (array) DB::table('clinic_appointments')->where('id', 56)->first());
    }

    public static function ongoingClinicStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['checked_in', 'in_consultation', 'for_payment']);
    }

    #[DataProvider('terminalClinicStatuses')]
    public function test_clinic_case_lifecycle_terminal_cases_allow_one_legitimate_new_visit(string $status): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->authenticateAs('staff');
        DB::table('pets')->insert(['pet_id' => 80, 'user_id' => 1, 'pet_name' => 'Gummy', 'species' => 'Dog']);
        $this->insertClinicAppointment($status);
        DB::table('clinic_appointments')->where('id', 1)->update(['pet_id' => 80, 'appointment_date' => '2026-10-16']);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases');
        $this->getJson('/api/admin/pets/80/clinic-case')->assertOk()->assertJsonPath('case', null);
        $id = $this->postJson('/api/admin/clinic-cases', ['pet_id' => 80, 'case_type' => 'consultation'])
            ->assertCreated()->assertJsonPath('case.status', 'checked_in')->json('case.id');
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 80, 'case_type' => 'consultation'])
            ->assertStatus(409)->assertJsonPath('case.id', $id);
        $this->assertDatabaseCount('clinic_appointments', 2);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => $status]);
    }

    public static function terminalClinicStatuses(): array
    {
        return array_map(fn ($status) => [$status], \App\Models\ClinicAppointment::TERMINAL_STATUSES);
    }

    public function test_clinic_case_lifecycle_closing_marks_only_unused_registrations_no_show(): void
    {
        Carbon::setTestNow('2026-10-16 16:59:59');
        $this->authenticateAs('staff');
        DB::table('pets')->insert(['pet_id' => 80, 'user_id' => 1, 'pet_name' => 'Gummy', 'species' => 'Dog']);
        foreach (['checked_in', 'in_consultation', 'for_payment', 'waiting_to_arrive', 'waiting_to_arrive'] as $index => $status) {
            $this->insertClinicAppointment($status, $index + 1);
        }
        DB::table('clinic_appointments')->where('id', 4)->update(['pet_id' => 80, 'checked_in_at' => null, 'queue_number' => null]);
        DB::table('clinic_appointments')->where('id', 5)->update(['appointment_date' => '2026-10-17', 'checked_in_at' => null]);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(3, 'cases')
            ->assertJsonCount(1, 'arrivals')->assertJsonPath('arrivals.0.id', 4);

        Carbon::setTestNow('2026-10-16 17:00:00');
        $this->assertSame(1, app(\App\Services\PreRegistrationExpiry::class)->expire());
        $this->assertDatabaseHas('clinic_appointments', ['id' => 4, 'status' => 'no_show']);
        Carbon::setTestNow('2026-10-17 00:00:01');
        $this->getJson('/api/admin/pets/80/clinic-case')->assertOk()->assertJsonPath('case', null);
        $response = $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(3, 'cases')
            ->assertJsonCount(1, 'arrivals')->assertJsonPath('arrivals.0.id', 5);
        $this->assertEqualsCanonicalizing([1, 2, 3], array_column($response->json('cases'), 'id'));
        $this->assertDatabaseHas('clinic_appointments', ['id' => 4, 'status' => 'no_show']);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 5, 'status' => 'waiting_to_arrive']);
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->postJson('/api/admin/clinic-cases', ['pet_id' => 80, 'case_type' => 'consultation'])->assertCreated();
    }

    public function test_clinic_case_lifecycle_does_not_silently_truncate_unfinished_cases(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->authenticateAs('staff');
        foreach (range(1, 101) as $id) {
            $this->insertClinicAppointment('in_consultation', $id);
        }
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(101, 'cases');
    }

    public function test_clinic_case_lifecycle_lookup_preserves_staff_permissions(): void
    {
        DB::table('pets')->insert(['pet_id' => 80, 'pet_name' => 'Gummy', 'species' => 'Dog']);
        $this->authenticateAs('staff', subrole: 'clinic_receptionist');
        $this->getJson('/api/admin/pets/80/clinic-case')->assertOk()->assertJsonPath('case', null);
        $this->authenticateAs('staff', staffType: 'grooming');
        $this->getJson('/api/admin/pets/80/clinic-case')->assertForbidden();
    }

    public function test_clinic_records_are_paginated_newest_first(): void
    {
        $this->authenticateAs('staff');
        foreach (range(1, 30) as $n) {
            DB::table('unregistered_customers')->insert([
                'id' => $n, 'first_name' => "Owner{$n}", 'last_name' => 'Cruz', 'phone' => '09171234567',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pets')->insert(['pet_id' => $n, 'unregistered_customer_id' => $n, 'pet_name' => "Pet{$n}", 'species' => 'Dog']);
        }

        $this->getJson('/api/admin/clinic-records')
            ->assertOk()
            ->assertJsonCount(25, 'rows')
            ->assertJsonPath('rows.0.owner.fullName', 'Owner30 Cruz')
            ->assertJsonPath('rows.0.pets.0.petName', 'Pet30')
            ->assertJsonPath('has_more', true);
        $this->getJson('/api/admin/clinic-records?page=2')->assertJsonCount(5, 'rows')->assertJsonPath('has_more', false);
    }

    public function test_clinic_records_group_pets_under_one_owner_row(): void
    {
        $this->authenticateAs('staff');
        DB::table('unregistered_customers')->insert([
            ['id' => 1, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'phone' => '09171234567', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Reyes', 'phone' => '09171234568', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1, 'unregistered_customer_id' => 1, 'pet_name' => 'Coco', 'species' => 'Dog'],
            ['pet_id' => 2, 'unregistered_customer_id' => 2, 'pet_name' => 'Luna', 'species' => 'Cat'],
            ['pet_id' => 3, 'unregistered_customer_id' => 1, 'pet_name' => 'Buddy', 'species' => 'Dog'],
        ]);

        $this->getJson('/api/admin/clinic-records')
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.owner.fullName', 'Ana Cruz')
            ->assertJsonCount(2, 'rows.0.pets')
            ->assertJsonPath('rows.0.pets.0.petName', 'Buddy')
            ->assertJsonPath('rows.0.pets.1.petName', 'Coco')
            ->assertJsonPath('rows.1.owner.fullName', 'Ben Reyes')
            ->assertJsonCount(1, 'rows.1.pets');
    }

    public function test_staff_can_create_a_clinic_walk_in_for_a_registered_customer_and_saved_pet(): void
    {
        $this->authenticateAs('staff');

        DB::table('users')->insert([
            'user_id' => 10,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'is_archived' => false,
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Maria',
            'lname' => 'Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 10,
            'pet_id' => 101,
            'pet_name' => 'Bantay',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'chief_complaint' => 'Routine consultation',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.pet_id', 101);

        $this->assertDatabaseHas('walkins', [
            'user_id' => 10,
            'unregistered_customer_id' => null,
            'appointment_type' => 'clinic',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => 10,
            'pet_id' => 101,
            'status' => 'checked_in',
        ]);
        $this->assertDatabaseCount('pets', 1);
    }

    public function test_staff_can_create_a_clinic_walk_in_for_an_unregistered_customer_and_saved_pet(): void
    {
        $this->authenticateAs('staff');

        $customerId = DB::table('unregistered_customers')->insertGetId([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'phone' => '09181234567',
            'email' => 'ana@example.com',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pets')->insert([
            'pet_id' => 102,
            'unregistered_customer_id' => $customerId,
            'pet_name' => 'Mingming',
            'species' => 'Cat',
            'breed' => 'Domestic Shorthair',
            'is_archived' => false,
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Ana',
            'lname' => 'Reyes',
            'email' => 'ana@example.com',
            'phone' => '09181234567',
            'owner_record_type' => 'unregistered',
            'unregistered_customer_id' => $customerId,
            'pet_id' => 102,
            'pet_name' => 'Mingming',
            'species' => 'Cat',
            'breed' => 'Domestic Shorthair',
            'chief_complaint' => 'Loss of appetite',
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.pet_id', 102);

        $this->assertDatabaseHas('walkins', [
            'user_id' => null,
            'unregistered_customer_id' => $customerId,
            'appointment_type' => 'clinic',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => null,
            'pet_id' => 102,
            'status' => 'checked_in',
        ]);
        $this->assertDatabaseCount('pets', 1);
    }

    public function test_staff_can_add_a_new_pet_for_an_existing_clinic_walk_in_customer(): void
    {
        $this->authenticateAs('staff');

        DB::table('users')->insert([
            'user_id' => 11,
            'first_name' => 'Jose',
            'last_name' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'role' => 'customer',
        ]);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Jose',
            'lname' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 11,
            'pet_name' => 'brownie',
            'species' => 'Dog',
            'chief_complaint' => 'Skin irritation',
            'clinic_quick_entry' => true,
            'terms_agreed' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['breed']);

        $this->postJson('/api/admin/clinic-walk-in', [
            'common_concerns' => ['Routine check-up'],
            'fname' => 'Jose',
            'lname' => 'Cruz',
            'email' => 'jose@example.com',
            'phone' => '09191234567',
            'owner_record_type' => 'registered',
            'customer_user_id' => 11,
            'pet_name' => 'brownie',
            'species' => 'Dog',
            'breed' => 'Aspin',
            'gender' => 'female',
            'birthdate' => '2021-06-15',
            'is_neutered' => true,
            'neutered_date' => '2022-01-10',
            'color' => 'Brown',
            'chief_complaint' => 'Skin irritation',
            'clinic_quick_entry' => true,
            'terms_agreed' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('returning_customer', true)
            ->assertJsonPath('pet.name', 'Brownie');

        $this->assertDatabaseHas('pets', [
            'user_id' => 11,
            'unregistered_customer_id' => null,
            'pet_name' => 'Brownie',
            'breed' => 'Aspin',
            'gender' => 'female',
            'birthdate' => '2021-06-15',
            'is_neutered' => true,
            'neutered_date' => '2022-01-10',
            'color' => 'Brown',
        ]);
        $this->assertDatabaseHas('clinic_appointments', [
            'user_id' => 11,
            'status' => 'checked_in',
        ]);
    }

    #[DataProvider('phoneEnforcementModes')]
    public function test_customer_can_pre_register_an_owned_pet_without_reentering_owner_information(bool $phoneEnforced): void
    {
        $this->authenticateAs('customer', 10);
        if ($phoneEnforced) {
            config(['services.sms_verification.available' => true, 'services.sms_verification.enforced' => true]);
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable());
            DB::table('users')->where('user_id', 10)->update(['phone' => '09171234567', 'phone_verified_at' => now()]);
            Sanctum::actingAs(User::findOrFail(10));
        }
        $appointmentDate = now()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'breed' => 'Persian',
            'is_archived' => false,
        ]);

        $response = $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up', 'Vomiting'],
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('appointment.appointment_type', 'pre_registered')
            ->assertJsonPath('appointment.status', 'waiting_to_arrive')
            ->assertJsonPath('appointment.appointment_date', $appointmentDate)
            ->assertJsonPath('appointment.time_window', null)
            ->assertJsonPath('appointment.pet.pet_id', 101)
            ->assertJsonPath('appointment.owner.name', 'Customer User')
            ->assertJsonPath('appointment.common_concerns', ['Routine check-up', 'Vomiting'])
            ->assertJsonPath('appointment.chief_complaint', "Routine check-up, Vomiting\nRoutine wellness consultation");

        $this->assertDatabaseHas('clinic_appointments', [
            'appointment_type' => 'pre_registered',
            'status' => 'waiting_to_arrive',
            'queue_number' => null,
            'window_id' => null,
            'user_id' => 10,
            'walkin_id' => null,
            'pet_id' => 101,
            'chief_complaint' => "Routine check-up, Vomiting\nRoutine wellness consultation",
        ]);
        $this->assertSame(
            ['Routine check-up', 'Vomiting'],
            json_decode(DB::table('clinic_appointments')->value('common_concerns'), true),
        );
        $this->getJson('/api/booking/history?history_limit=0')->assertOk()
            ->assertJsonPath('clinic_visits.0.status', 'waiting_to_arrive')
            ->assertJsonPath('clinic_visits.0.appointment_type', 'pre_registered')
            ->assertJsonPath('clinic_visits.0.queue_number', null)
            ->assertJsonPath('clinic_visits.0.pet_name', 'Mochi')
            ->assertJsonPath('clinic_visits.0.service_day', $appointmentDate);
        $this->assertDatabaseCount('walkins', 0);
    }

    public static function phoneEnforcementModes(): array
    {
        return ['optional' => [false], 'verified and enforced' => [true]];
    }

    public function test_pre_registration_requires_common_concerns_and_allows_optional_details(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        $payload = [
            'service_day' => now('Asia/Manila')->toDateString(),
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 101,
        ];

        $this->postJson('/api/clinic/pre-register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns');
        $this->postJson('/api/clinic/pre-register', $payload + ['common_concerns' => ['Unknown']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('common_concerns.0');
        $this->postJson('/api/clinic/pre-register', $payload + [
            'common_concerns' => ['Routine check-up', 'Vaccination'],
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.common_concerns', ['Routine check-up', 'Vaccination'])
            ->assertJsonPath('appointment.chief_complaint', 'Routine check-up, Vaccination');
    }

    public function test_ongoing_grooming_blocks_a_new_clinic_pre_registration(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('bookings')->insert([
            'booking_reference' => 'BAC-ONGOING-1',
            'user_id' => 10,
            'window_id' => 1,
            'booking_date' => now()->addDay()->toDateString(),
            'number_of_pets' => 1,
            'status' => 'waiting_to_arrive',
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ongoing_pre_registration')
            ->assertJsonPath('ongoing.type', 'grooming');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_ongoing_clinic_visit_blocks_a_new_grooming_pre_registration(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('clinic_appointments')->insert([
            'appointment_reference' => 'CL-ONGOING-1',
            'appointment_type' => 'pre_registered',
            'status' => 'waiting_to_arrive',
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'user_id' => 10,
            'pet_id' => 101,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->submitSignedGrooming('/api/booking/store', [
            'booking_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'number_of_pets' => 1,
            'pets' => [[
                'pet_id' => 101,
                'pet_name' => 'Mochi',
                'species' => 'cat',
            ]],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ongoing_pre_registration')
            ->assertJsonPath('ongoing.type', 'clinic');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_cannot_pre_register_another_customers_pet(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 202,
            'user_id' => 20,
            'pet_name' => 'Not My Pet',
            'species' => 'dog',
            'is_archived' => false,
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => now()->addDay()->toDateString(),
            'window_id' => 1,
            'pet_id' => 202,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_id');

        $this->assertDatabaseCount('clinic_appointments', 0);
    }

    public function test_customer_pre_registration_rechecks_clinic_closures_on_submission(): void
    {
        $this->authenticateAs('customer', 10);
        $appointmentDate = now()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);
        DB::table('clinic_closures')->insert([
            'type' => 'blocked_date',
            'start_date' => $appointmentDate,
            'end_date' => $appointmentDate,
            'reason' => 'Veterinary team training',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->addDay()->toDateString(),
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => $appointmentDate,
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertCreated()->assertJsonPath('appointment.appointment_date', now()->addDay()->toDateString());

        $this->assertDatabaseCount('clinic_appointments', 1);
    }

    public function test_customer_clinic_timeslots_return_active_window_availability(): void
    {
        $appointmentDate = now()->addDay()->toDateString();

        $this->getJson("/api/clinic/timeslots?date={$appointmentDate}")
            ->assertOk()
            ->assertJsonPath('date', $appointmentDate)
            ->assertJsonPath('windows.0.window_id', 1)
            ->assertJsonPath('windows.0.window_label', '8:00 AM - 9:00 AM')
            ->assertJsonMissingPath('windows.0.remaining')
            ->assertJsonMissingPath('windows.0.is_full')
            ->assertJsonMissingPath('windows.0.recommended')
            ->assertJsonPath('windows.0.is_past', false);
    }

    public function test_customer_can_select_the_same_preferred_window_after_four_pre_registrations(): void
    {
        $this->authenticateAs('customer', 10);
        $appointmentDate = now()->addDay()->toDateString();
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        foreach (range(1, 4) as $number) {
            DB::table('clinic_appointments')->insert([
                'appointment_reference' => 'CL-FULL-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'appointment_type' => 'pre_registered',
                'status' => 'waiting_to_arrive',
                'appointment_date' => $appointmentDate,
                'window_id' => 1,
                'paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/clinic/timeslots?date={$appointmentDate}")
            ->assertOk()
            ->assertJsonMissingPath('windows.0.remaining')
            ->assertJsonMissingPath('windows.0.is_full')
            ->assertJsonMissingPath('windows.0.recommended')
            ->assertJsonPath('windows.0.is_closed', false);

        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => $appointmentDate,
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertCreated()
            ->assertJsonPath('appointment.status', 'waiting_to_arrive');

        $this->assertDatabaseCount('clinic_appointments', 5);
        $this->assertNull(DB::table('clinic_appointments')->where('user_id', 10)->value('queue_number'));
    }

    public function test_admin_can_list_appointments_and_update_clinic_status(): void
    {
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: 'waiting_to_arrive');

        $this->getJson('/api/admin/clinic-appointments')
            ->assertOk()
            ->assertJsonPath('incoming.0.appointment_reference', 'CL-20260722-001');

        $this->postJson('/api/admin/clinic-appointments/1/check-in')
            ->assertOk()
            ->assertJsonPath('appointment.status', 'checked_in');

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 1,
            'status' => 'checked_in',
        ]);
    }

    public function test_check_in_assigns_the_next_queue_number_to_a_pre_registration(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'waiting_to_arrive', id: 1);
        $this->insertClinicAppointment(status: 'checked_in', id: 2);
        DB::table('clinic_appointments')->where('id', 1)->update(['queue_number' => null]);

        $this->postJson('/api/admin/clinic-appointments/1/check-in')
            ->assertOk()
            ->assertJsonPath('appointment.status', 'checked_in')
            ->assertJsonPath('appointment.queue_number', 3);

        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 1,
            'status' => 'checked_in',
            'queue_number' => 3,
        ]);
    }

    public function test_clinic_fcfs_uses_check_in_order_and_retries_preserve_both_timestamps(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'waiting_to_arrive', id: 1);
        $this->insertClinicAppointment(status: 'waiting_to_arrive', id: 2);
        $this->insertClinicAppointment(status: 'completed', id: 3);
        DB::table('clinic_appointments')->whereIn('id', [1, 2])->update(['queue_number' => null, 'checked_in_at' => null]);
        DB::table('clinic_appointments')->where('id', 3)->update(['queue_number' => 10]);
        DB::table('clinic_appointments')->where('id', 1)->update(['created_at' => now()->setTime(9, 0)]);
        DB::table('clinic_appointments')->where('id', 2)->update(['created_at' => now()->setTime(9, 10)]);
        $registered = DB::table('clinic_appointments')->where('id', 2)->value('created_at');
        Carbon::setTestNow(now()->setTime(9, 30));
        $this->postJson('/api/admin/clinic-appointments/2/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 11);
        $checkedIn = DB::table('clinic_appointments')->where('id', 2)->value('checked_in_at');
        Carbon::setTestNow(now()->setTime(9, 45));
        $this->postJson('/api/admin/clinic-appointments/1/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 12);
        $this->postJson('/api/admin/clinic-appointments/2/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 11);
        $this->assertSame($registered, DB::table('clinic_appointments')->where('id', 2)->value('created_at'));
        $this->assertSame($checkedIn, DB::table('clinic_appointments')->where('id', 2)->value('checked_in_at'));
        $this->assertDatabaseCount('clinic_appointments', 3);
    }

    public function test_veterinarian_can_view_but_cannot_modify_availability(): void
    {
        $this->authenticateAs('staff');

        $this->getJson('/api/clinic/status')
            ->assertOk()
            ->assertJsonPath('stopped_today.clinic', false)->assertJsonPath('stopped_today.grooming', false);

        foreach (['stop-today', 'reopen-today'] as $action) {
            $this->postJson('/api/admin/clinic/'.$action)
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_RESPONSE);
        }

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/clinic/settings/availability')
            ->assertOk()
            ->assertJsonPath('availability.clinic.open_time', '08:00')
            ->assertJsonPath('groomers_on_duty', 2);

        $this->postJson('/api/admin/clinic/blocked-dates', [
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);

        $this->deleteJson('/api/admin/clinic/blocked-dates/1')
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '09:00',
            'close_time' => '18:00',
            'pre_registration_cutoff_time' => '15:00',
        ])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_RESPONSE);
    }

    public function test_availability_role_permissions_and_cross_service_mutations(): void
    {
        foreach (['admin', 'grooming_receptionist', 'clinic_receptionist', 'veterinarian'] as $role) {
            $this->authenticateAs($role === 'admin' ? 'admin' : 'staff', subrole: $role, staffType: $role === 'grooming_receptionist' ? 'grooming' : 'clinic');
            $this->get('/api/admin/availability/page')->assertOk();
            $this->getJson('/api/admin/clinic/settings/availability')->assertOk()->assertJsonPath('capacity.max', 20);
            $mutations = [
                ['POST', '/api/admin/clinic/stop-today', ['service' => 'clinic']],
                ['POST', '/api/admin/clinic/stop-today', ['service' => 'grooming']],
                ['POST', '/api/admin/clinic/reopen-today', ['service' => 'clinic']],
                ['POST', '/api/admin/clinic/reopen-today', ['service' => 'grooming']],
                ['PATCH', '/api/admin/clinic/settings/availability', ['service' => 'clinic', 'open_time' => '08:00', 'close_time' => '17:00']],
                ['PATCH', '/api/admin/clinic/settings/availability', ['service' => 'grooming', 'open_time' => '08:00', 'close_time' => '17:00']],
                ['PATCH', '/api/admin/clinic/settings/capacity', ['maximum' => 20]],
                ['POST', '/api/admin/pet-groomers', ['first_name' => 'Test']],
                ['PATCH', '/api/admin/pet-groomers/1', ['first_name' => 'Renamed']],
                ['POST', '/api/admin/clinic/blocked-dates', ['start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]],
            ];
            foreach ($mutations as [$method, $uri, $data]) {
                $response = $this->json($method, $uri, $data);
                if ($role === 'veterinarian') $response->assertForbidden();
                else $response->assertSuccessful();
            }
            $id = DB::table('clinic_closures')->where('type', 'blocked_date')->value('id') ?? 1;
            $response = $this->deleteJson('/api/admin/clinic/blocked-dates/'.$id);
            if ($role === 'veterinarian') $response->assertForbidden();
            else $response->assertOk();
            DB::table('clinic_closures')->delete();
        }
        $this->authenticateAs('customer');
        $this->get('/api/admin/availability/page')->assertForbidden();
        $this->getJson('/api/admin/clinic/settings/availability')->assertForbidden();
        $this->patchJson('/api/admin/clinic/settings/capacity', ['maximum' => 10])->assertForbidden();
    }

    public function test_cage_limit_validation_and_persistence(): void
    {
        $this->authenticateAs('admin');
        foreach ([0, -1, 1.5, true, false, '1.0', '1e2', 'abc', 501, null] as $invalid) {
            $this->patchJson('/api/admin/clinic/settings/capacity', ['maximum' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('maximum');
        }
        $this->patchJson('/api/admin/clinic/settings/capacity', ['maximum' => 12])->assertOk()->assertJsonPath('capacity.max', 12);
        $this->getJson('/api/admin/clinic/settings/availability')->assertOk()->assertJsonPath('capacity.max', 12)->assertJsonPath('capacity.remaining', 12);
        $this->getJson('/api/booking/grooming-capacity')->assertOk()->assertJsonPath('capacity.max', 12);
    }

    public function test_public_clinic_status_includes_configured_service_availability(): void
    {
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '09:00:00',
            'clinic_close_time' => '18:00:00',
            'clinic_prereg_cutoff_time' => '15:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '16:00:00',
            'grooming_prereg_cutoff_time' => '13:00:00',
        ]);

        $this->getJson('/api/clinic/status')
            ->assertOk()
            ->assertJsonPath('availability.clinic.operating_hours_label', '9:00 AM – 6:00 PM')
            ->assertJsonMissingPath('availability.clinic.pre_registration_cutoff_label')
            ->assertJsonPath('availability.grooming.operating_hours_label', '8:00 AM – 4:00 PM')
            ->assertJsonMissingPath('availability.grooming.pre_registration_cutoff_label');
    }

    public function test_admin_can_add_list_and_remove_blocked_dates(): void
    {
        $this->authenticateAs('admin');
        $startDate = now()->addDay()->toDateString();
        $endDate = now()->addDays(2)->toDateString();

        $this->postJson('/api/admin/clinic/blocked-dates', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Team training',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/clinic/settings/availability')->assertOk()->assertJsonPath('blocked_dates.0.start_date', $startDate)->assertJsonPath('blocked_dates.0.end_date', $endDate);
        $this->getJson('/api/clinic/status')->assertOk()->assertJsonPath('blocked_dates.0.start_date', $startDate);

        $blockedDateId = DB::table('clinic_closures')->value('id');

        $this->getJson('/api/admin/clinic/blocked-dates')
            ->assertOk()
            ->assertJsonPath('blocked_dates.0.id', $blockedDateId)
            ->assertJsonPath('blocked_dates.0.reason', 'Team training');

        $this->deleteJson("/api/admin/clinic/blocked-dates/{$blockedDateId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('clinic_closures', [
            'id' => $blockedDateId,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_view_and_update_each_service_availability(): void
    {
        $this->authenticateAs('admin');

        $this->getJson('/api/admin/clinic/settings/availability')
            ->assertOk()
            ->assertJsonPath('availability.clinic.open_time', '08:00')
            ->assertJsonPath('availability.clinic.close_time', '17:00')
            ->assertJsonMissingPath('availability.clinic.pre_registration_cutoff_time')
            ->assertJsonPath('availability.grooming.operating_hours_label', '8:00 AM – 5:00 PM');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '09:00',
            'close_time' => '18:00',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Clinic availability updated.')
            ->assertJsonPath('availability.clinic.operating_hours_label', '9:00 AM – 6:00 PM')
            ->assertJsonMissingPath('availability.clinic.pre_registration_cutoff_label')
            ->assertJsonPath('availability.grooming.open_time', '08:00');

        $storedSettings = DB::table('clinic_settings')->where('id', 1)->first();
        $this->assertSame('09:00', substr($storedSettings->clinic_open_time, 0, 5));
        $this->assertSame('18:00', substr($storedSettings->clinic_close_time, 0, 5));
        $this->assertSame('14:00', substr($storedSettings->clinic_prereg_cutoff_time, 0, 5));
        $this->assertSame('08:00', substr($storedSettings->grooming_open_time, 0, 5));
    }

    public function test_availability_rejects_invalid_time_order(): void
    {
        $this->authenticateAs('admin');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'grooming',
            'open_time' => '17:00',
            'close_time' => '08:00',
            'pre_registration_cutoff_time' => '14:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('close_time');

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'grooming',
            'open_time' => '08:00',
            'close_time' => 'invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('close_time');
    }

    public function test_extending_availability_generates_hourly_windows_and_skips_lunch_break(): void
    {
        $this->authenticateAs('admin');
        DB::table('time_windows')->insert([
            'window_id' => 5,
            'window_label' => '12:00 PM - 1:00 PM',
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->patchJson('/api/admin/clinic/settings/availability', [
            'service' => 'clinic',
            'open_time' => '08:00',
            'close_time' => '17:00',
            'pre_registration_cutoff_time' => '17:00',
        ])->assertOk();

        $appointmentDate = now()->addDay()->toDateString();
        $clinicResponse = $this->getJson("/api/clinic/timeslots?date={$appointmentDate}");

        $clinicResponse
            ->assertOk()
            ->assertJsonCount(8, 'windows')
            ->assertJsonPath('windows.6.window_label', '3:00 PM - 4:00 PM')
            ->assertJsonPath('windows.7.window_label', '4:00 PM - 5:00 PM');

        $clinicLabels = collect($clinicResponse->json('windows'))
            ->pluck('window_label');
        $this->assertFalse($clinicLabels->contains('12:00 PM - 1:00 PM'));
        $this->assertSame(0, DB::table('time_windows')
            ->where('start_time', '12:00:00')
            ->where('end_time', '13:00:00')
            ->value('is_active'));

        $groomingResponse = $this->getJson("/api/timeslots?date={$appointmentDate}");
        $groomingResponse
            ->assertOk()
            ->assertJsonCount(8, 'windows')
            ->assertJsonPath('windows.7.window_label', '4:00 PM - 5:00 PM');

        $groomingLabels = collect($groomingResponse->json('windows'))
            ->pluck('window_label');
        $this->assertTrue($groomingLabels->contains('2:00 PM - 3:00 PM'));
        $this->assertTrue($groomingLabels->contains('3:00 PM - 4:00 PM'));
    }

    public function test_clinic_timeslots_follow_configured_hours_without_same_day_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 13:59:00'));
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '09:00:00',
            'clinic_close_time' => '11:00:00',
            'clinic_prereg_cutoff_time' => '14:00:00',
            'grooming_open_time' => '08:00:00',
            'grooming_close_time' => '17:00:00',
            'grooming_prereg_cutoff_time' => '14:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 2,
            'window_label' => '2',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->getJson('/api/clinic/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonCount(1, 'windows')
            ->assertJsonPath('windows.0.window_id', 2)
            ->assertJsonMissingPath('windows.0.is_cutoff')
            ->assertJsonPath('availability.operating_hours_label', '9:00 AM – 11:00 AM');

        Carbon::setTestNow(Carbon::parse('2026-07-23 14:01:00'));

        $this->getJson('/api/clinic/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonMissingPath('cutoff_passed')
            ->assertJsonPath('windows.0.is_past', true);
    }

    #[DataProvider('clinicArrivalWindowCases')]
    public function test_clinic_legacy_availability_and_automatic_registration_day(string $time, string $date, ?string $restriction, bool $accepted): void
    {
        Carbon::setTestNow("2026-10-09 {$time}");
        $this->authenticateAs('customer', 10);
        DB::table('clinic_settings')->insert([
            'id' => 1, 'clinic_open_time' => '18:00:00', 'clinic_close_time' => '22:00:00',
            'clinic_prereg_cutoff_time' => $restriction === 'cutoff' ? '19:00:00' : '22:00:00',
        ]);
        DB::table('time_windows')->where('window_id', 1)->update([
            'start_time' => '19:00:00', 'end_time' => '20:00:00', 'window_label' => '7:00 PM - 8:00 PM',
        ]);
        DB::table('pets')->insert([
            'pet_id' => 101, 'user_id' => 10, 'pet_name' => 'Mochi', 'species' => 'cat', 'is_archived' => false,
        ]);
        if (in_array($restriction, ['blocked_date', 'stop_today'], true)) {
            DB::table('clinic_closures')->insert(['type' => $restriction, 'start_date' => $date, 'end_date' => $date]);
        }

        $availability = $this->getJson('/api/clinic/timeslots?date='.$date)->assertOk();
        $availability->assertJsonMissingPath('cutoff_passed')
            ->assertJsonPath('windows.0.is_past', $date === '2026-10-09' && $time >= '20:00:00')
            ->assertJsonPath('windows.0.is_closed', in_array($restriction, ['blocked_date', 'stop_today'], true));
        $response = $this->postJson('/api/clinic/pre-register', [
            'service_day' => in_array($restriction, ['blocked_date', 'stop_today'], true) || $time >= '22:00:00' ? '2026-10-10' : '2026-10-09',
            'appointment_date' => $date, 'window_id' => 1, 'pet_id' => 101, 'common_concerns' => ['Routine check-up'],
        ]);
        $expectedDay = in_array($restriction, ['blocked_date', 'stop_today'], true) || $time >= '22:00:00'
            ? '2026-10-10' : '2026-10-09';
        $response->assertCreated()->assertJsonPath('appointment.status', 'waiting_to_arrive')
            ->assertJsonPath('appointment.appointment_date', $expectedDay);
        $this->assertDatabaseHas('clinic_appointments', ['window_id' => null, 'queue_number' => null,
            'checked_in_at' => null]);
    }

    public static function clinicArrivalWindowCases(): array
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

    public function test_customer_can_submit_same_day_clinic_pre_registration_after_legacy_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 14:01:00'));
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        DB::table('time_windows')->where('window_id', 1)->update([
            'start_time' => '15:00:00', 'end_time' => '16:00:00', 'window_label' => '3:00 PM - 4:00 PM',
        ]);
        $this->postJson('/api/clinic/pre-register', [
            'service_day' => now('Asia/Manila')->toDateString(),
            'common_concerns' => ['Routine check-up'],
            'appointment_date' => '2026-07-23',
            'window_id' => 1,
            'pet_id' => 101,
            'chief_complaint' => 'Routine wellness consultation',
        ])
            ->assertCreated();

        $this->assertDatabaseCount('clinic_appointments', 1);
    }

    public function test_grooming_timeslots_and_submission_use_grooming_availability(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:01:00'));
        DB::table('clinic_settings')->insert([
            'id' => 1,
            'groomers_on_duty' => 2,
            'clinic_open_time' => '08:00:00',
            'clinic_close_time' => '17:00:00',
            'clinic_prereg_cutoff_time' => '14:00:00',
            'grooming_open_time' => '09:00:00',
            'grooming_close_time' => '11:00:00',
            'grooming_prereg_cutoff_time' => '12:00:00',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 2,
            'window_label' => '9:00 AM - 10:00 AM',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'max_slots' => 4,
            'is_active' => true,
        ]);

        $this->getJson('/api/timeslots?date=2026-07-23')
            ->assertOk()
            ->assertJsonCount(1, 'windows')
            ->assertJsonPath('windows.0.window_id', 2)
            ->assertJsonPath('windows.0.is_past', true)
            ->assertJsonMissingPath('windows.0.is_cutoff')
            ->assertJsonMissingPath('availability.pre_registration_cutoff_label');

        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
        ]);

        $this->getJson('/api/pre-registration/queue-info?service=grooming')->assertOk()
            ->assertJsonPath('service_day', '2026-07-24')->assertJsonPath('waiting_count', null);

    }











    #[DataProvider('authorizedClinicRoles')]
    public function test_authorized_roles_cannot_upload_general_medical_record_attachments(string $role): void
    {
        Storage::fake('local');
        $this->authenticateAs($role);
        $this->insertClinicAppointment(status: 'in_consultation');
        $this->postJson('/api/admin/clinic-appointments/1/attachments', [
            'file' => UploadedFile::fake()->create('general.png', 20, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('clinic_case_service_id');
        $this->assertDatabaseCount('clinic_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function authorizedClinicRoles(): array
    {
        return [
            'staff' => ['staff'],
            'administrator' => ['admin'],
        ];
    }

    #[DataProvider('authorizedClinicRoles')]
    public function test_authorized_roles_can_download_the_scoped_private_attachment(string $role): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs($role);
        $this->insertClinicAppointment(status: 'in_consultation');
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/scoped-document.pdf',
            'private clinic document',
        );

        $response = $this->get("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download");

        $response
            ->assertOk()
            ->assertDownload('scoped-document.pdf')
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertSame('private clinic document', $response->streamedContent());
        $this->assertStringNotContainsString('clinic/attachments/1', (string) $response->headers->get('content-disposition'));
    }

    public function test_download_returns_the_same_not_found_response_for_wrong_or_missing_attachments(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation', id: 1);
        $this->insertClinicAppointment(status: 'in_consultation', id: 2);
        $attachmentId = $this->createAttachment(
            2,
            'clinic/attachments/2/appointment-two.pdf',
            'appointment two',
        );

        $expected = [
            'success' => false,
            'message' => 'Attachment not found.',
        ];

        $this->getJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download")
            ->assertNotFound()
            ->assertExactJson($expected);
        $this->getJson('/api/admin/clinic-appointments/1/attachments/999/download')
            ->assertNotFound()
            ->assertExactJson($expected);
        $this->getJson("/api/admin/clinic-appointments/999/attachments/{$attachmentId}/download")
            ->assertNotFound()
            ->assertExactJson($expected);

        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-two.pdf');
    }

    public function test_deletion_is_scoped_to_the_appointment_and_preserves_the_other_attachment(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation', id: 1);
        $this->insertClinicAppointment(status: 'in_consultation', id: 2);
        $attachmentA = $this->createAttachment(
            1,
            'clinic/attachments/1/appointment-a.pdf',
            'appointment A',
        );
        $attachmentB = $this->createAttachment(
            2,
            'clinic/attachments/2/appointment-b.pdf',
            'appointment B',
        );

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentB}")
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Attachment not found.',
            ]);

        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentA]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentB]);
        Storage::disk('local')->assertExists('clinic/attachments/1/appointment-a.pdf');
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-b.pdf');

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentA}")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('clinic_attachments', ['id' => $attachmentA]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentB]);
        Storage::disk('local')->assertMissing('clinic/attachments/1/appointment-a.pdf');
        Storage::disk('local')->assertExists('clinic/attachments/2/appointment-b.pdf');
    }

    public function test_correctly_scoped_attachment_record_can_be_deleted_when_its_file_is_already_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: 'in_consultation');
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/already-missing.pdf',
            'not stored',
            storeFile: false,
        );

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('clinic_attachments', ['id' => $attachmentId]);
    }

    public function test_legacy_public_attachment_is_streamed_without_exposing_its_public_path(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: 'in_consultation');
        $legacyPath = 'clinic/attachments/1/legacy-public.pdf';
        $attachmentId = $this->createAttachment(
            1,
            $legacyPath,
            'legacy public contents',
            disk: 'public',
        );

        $listing = $this->getJson('/api/admin/clinic-appointments')->assertOk();
        $metadata = $listing->json('in_consultation.0.record.attachments.0');

        $this->assertSame($attachmentId, $metadata['id']);
        $this->assertArrayNotHasKey('file_path', $metadata);
        $this->assertArrayNotHasKey('file_url', $metadata);
        $this->assertStringNotContainsString($legacyPath, json_encode($listing->json()));
        $this->assertStringNotContainsString('/storage/', json_encode($listing->json()));

        $download = $this->get("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download");
        $download->assertOk()->assertDownload('legacy-public.pdf');
        $this->assertSame('legacy public contents', $download->streamedContent());
    }

    #[DataProvider('lockedClinicalHistoryStatuses')]
    public function test_terminal_and_pre_arrival_appointments_reject_all_ordinary_clinical_mutations(
        string $status,
    ): void {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('staff');
        $this->insertClinicAppointment(status: $status);
        $attachmentId = $this->createAttachment(
            1,
            'clinic/attachments/1/locked-history.pdf',
            'preserved clinical attachment',
        );
        $recordId = DB::table('clinic_records')->where('clinic_appointment_id', 1)->value('id');
        DB::table('clinic_vitals')->insert([
            'clinic_appointment_id' => 1,
            'weight_kg' => 5.25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinic_medications')->insert([
            'clinic_record_id' => $recordId,
            'drug_name' => 'Preserved medicine',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = 'Start the consultation before modifying clinical records. For Payment corrections are limited to administrators.';
        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Forbidden replacement',
            'weight_kg' => 9.99,
            'medications' => [['drug_name' => 'Forbidden medicine']],
        ])->assertConflict()->assertJsonPath('message', $message);
        $this->post('/api/admin/clinic-appointments/1/attachments', [
            'file' => UploadedFile::fake()->create('forbidden.png', 20, 'image/png'), 'clinic_case_service_id' => 1,
        ])->assertConflict()->assertJsonPath('message', $message);
        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertConflict()
            ->assertJsonPath('message', $message);

        $this->assertDatabaseHas('clinic_records', [
            'id' => $recordId,
            'chief_complaint' => 'Attachment test',
            'diagnosis' => null,
        ]);
        $this->assertDatabaseHas('clinic_vitals', [
            'clinic_appointment_id' => 1,
            'weight_kg' => 5.25,
        ]);
        $this->assertDatabaseHas('clinic_medications', [
            'clinic_record_id' => $recordId,
            'drug_name' => 'Preserved medicine',
        ]);
        $this->assertDatabaseHas('clinic_attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertExists('clinic/attachments/1/locked-history.pdf');
        Storage::disk('local')->assertMissing('clinic/attachments/1/forbidden.pdf');
    }

    public static function lockedClinicalHistoryStatuses(): array
    {
        return [
            'waiting to arrive' => ['waiting_to_arrive'],
            'checked in' => ['checked_in'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'legacy unused' => ['no_show'],
            'expired' => ['expired'],
        ];
    }

    #[DataProvider('editableClinicalHistoryStatuses')]
    public function test_valid_clinical_stages_keep_record_vitals_and_historical_attachment_deletion_available(
        string $status,
    ): void {
        Storage::fake('local');
        Storage::fake('public');
        $this->authenticateAs('admin');
        $this->insertClinicAppointment(status: $status);

        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'diagnosis' => 'Permitted diagnosis',
            'weight_kg' => 6.75,
        ])
            ->assertOk()
            ->assertJsonPath('record.diagnosis', 'Permitted diagnosis')
            ->assertJsonMissingPath('record.medications');

        $attachmentId = $this->createAttachment(1, 'clinic/attachments/1/historical.pdf', 'historical file');

        $this->deleteJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}")
            ->assertOk();
        $this->assertDatabaseCount('clinic_records', 1);
        $this->assertDatabaseCount('clinic_vitals', 1);
        $this->assertDatabaseCount('clinic_medications', 0);
        $this->assertDatabaseCount('clinic_attachments', 0);
    }

    public static function editableClinicalHistoryStatuses(): array
    {
        return [
            'in consultation' => ['in_consultation'],
            'for payment' => ['for_payment'],
        ];
    }

    public function test_every_clinic_administration_route_has_the_expected_role_middleware(): void
    {
        $expected = [
            ['GET', 'api/admin/pets/{petId}/clinic-case', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-walk-in', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-records', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-services/options', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-services', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-services', 'role:admin,staff'],
            ['PUT', 'api/admin/clinic-services/{clinicService}', 'role:admin,staff'],
            ['PATCH', 'api/admin/clinic-services/{clinicService}/status', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-medicine-options', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-confinements', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-confinement-service-options', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/confinement', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/confinement', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/confinement/admission-preview', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/confinement/charges', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/confinement/charges/{chargeId}/stop', 'role:admin,staff'],
            ['DELETE', 'api/admin/clinic-cases/{id}/confinement/charges/{chargeId}', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/confinement/observations', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/confinement/observations', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/confinement/discharge-preview', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/confinement/discharge', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/start', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/finish', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/services', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-service-options', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/service-pricing/{clinicServiceId}', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-cases/{id}/services', 'role:admin,staff'],
            ['DELETE', 'api/admin/clinic-cases/{id}/services/{caseServiceId}', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-cases/{id}/charges', 'role:admin,staff'],
            ['PATCH', 'api/admin/clinic-cases/{id}/charges/{chargeId}', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments/archived', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/check-in', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/start-consultation', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/finish-consultation', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/pay', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/online-payment', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments/{id}/online-payment', 'role:admin,staff'],
            ['DELETE', 'api/admin/clinic-appointments/{id}/online-payment', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/cancel', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/record', 'role:admin,staff'],
            ['POST', 'api/admin/clinic-appointments/{id}/attachments', 'role:admin,staff'],
            ['GET', 'api/admin/clinic-appointments/{id}/attachments/{attachmentId}/download', 'role:admin,staff'],
            ['DELETE', 'api/admin/clinic-appointments/{id}/attachments/{attachmentId}', 'role:admin,staff'],
            ['POST', 'api/admin/clinic/stop-today', 'staff:availability_manage'],
            ['POST', 'api/admin/clinic/reopen-today', 'staff:availability_manage'],
            ['GET', 'api/admin/clinic/blocked-dates', 'role:admin,staff'],
            ['POST', 'api/admin/clinic/blocked-dates', 'staff:availability_manage'],
            ['DELETE', 'api/admin/clinic/blocked-dates/{id}', 'staff:availability_manage'],
            ['GET', 'api/admin/clinic/settings/availability', 'role:admin,staff'],
            ['PATCH', 'api/admin/clinic/settings/availability', 'staff:availability_manage'],
            ['PATCH', 'api/admin/clinic/settings/groomers-on-duty', 'role:admin,staff'],
            ['PATCH', 'api/admin/clinic/settings/capacity', 'staff:availability_manage'],
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($expected as [$method, $uri, $roleMiddleware]) {
            $route = $routes->first(
                fn (RoutingRoute $route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );

            $this->assertNotNull($route, "Expected clinic route [{$method} {$uri}] is not registered.");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($roleMiddleware, $route->gatherMiddleware());
        }

        $allClinicAdministrationRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/admin/clinic')
                || $route->uri() === 'api/admin/pets/{petId}/clinic-case',
        );

        $this->assertCount(count($expected), $allClinicAdministrationRoutes);

        $adminClinicRoutes = $routes->filter(
            fn (RoutingRoute $route) => str_starts_with($route->getActionName(), AdminClinicController::class.'@'),
        );

        $this->assertCount(20, $adminClinicRoutes);
        $adminClinicRoutes->each(function (RoutingRoute $route): void {
            $this->assertContains('role:admin,staff', $route->gatherMiddleware());
        });
    }

    public function test_customer_pet_profile_and_grooming_history_apis_remain_available(): void
    {
        $this->authenticateAs('customer', 10);
        DB::table('pets')->insert([
            'pet_id' => 101,
            'user_id' => 10,
            'pet_name' => 'Mochi',
            'species' => 'cat',
            'is_archived' => false,
            'created_at' => now(),
        ]);

        $this->getJson('/api/pets')->assertOk();
        $this->getJson('/api/pets/101')
            ->assertOk()
            ->assertJsonPath('pet.pet_name', 'Mochi');
        $this->getJson('/api/booking/history?pet_id=101')
            ->assertOk()
            ->assertJsonCount(0, 'bookings')
            ->assertJsonCount(0, 'history');
    }

    public function test_full_grooming_capacity_does_not_block_clinic_intake_or_actual_arrival_order(): void
    {
        $this->authenticateAs('staff');
        try {
            DB::table('bookings')->insert(['booking_id' => 1, 'booking_reference' => 'ACTIVE-20', 'user_id' => 1,
                'booking_date' => now()->toDateString(), 'number_of_pets' => 20, 'status' => 'in_progress']);
            foreach (range(1, 20) as $pet) {
                DB::table('booking_pets')->insert(['booking_id' => 1, 'pet_id' => $pet]);
            }
            foreach ([1, 2] as $id) {
                $this->insertClinicAppointment('waiting_to_arrive', id: $id);
                DB::table('clinic_appointments')->where('id', $id)->update(['queue_number' => null, 'appointment_type' => 'pre_registered']);
            }
            $this->assertTrue(app(\App\Services\OperationalCapacity::class)->snapshot()['is_full']);
            Carbon::setTestNow(now()->setTime(16, 0));
            $this->postJson('/api/admin/clinic-appointments/2/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 1);
            $this->postJson('/api/admin/clinic-cases/1/start')->assertUnprocessable();
            $this->postJson('/api/admin/clinic-appointments/1/check-in')->assertOk()->assertJsonPath('appointment.queue_number', 2);
            $this->postJson('/api/admin/clinic-cases/1/start')->assertOk()->assertJsonPath('case.queue_number', 2);
            $this->postJson('/api/admin/clinic-appointments/2/start-consultation')->assertOk();
            $this->assertSame(20, app(\App\Services\OperationalCapacity::class)->snapshot()['used']);
        } finally {
            Schema::dropIfExists('booking_pets');
        }
    }

    public static function chiefComplaintOriginsAndActors(): array
    {
        return [
            'pre-registered veterinarian' => ['pre_registered', 'staff'],
            'pre-registered admin' => ['pre_registered', 'admin'],
            'walk-in veterinarian' => ['walk_in', 'staff'],
            'walk-in admin' => ['walk_in', 'admin'],
        ];
    }

    #[DataProvider('chiefComplaintOriginsAndActors')]
    public function test_medical_record_chief_complaint_uses_appointment_origin(string $origin, string $role): void
    {
        $this->authenticateAs('staff', 2);
        $this->authenticateAs($role);
        $this->insertClinicAppointment('in_consultation');
        $original = "Original owner statement\nVomiting since yesterday.";
        DB::table('clinic_appointments')->where('id', 1)->update(['appointment_type' => $origin, 'chief_complaint' => $original]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'chief_complaint' => 'Later record statement']);
        $url = '/api/admin/clinic-appointments/1/record';
        foreach ([false, true] as $finishCase) {
            $this->postJson($url, [
                'appointment_type' => $origin === 'pre_registered' ? 'walk_in' : 'pre_registered',
                'chief_complaint' => 'Client replacement', 'relevant_history' => 'Updated history',
                'working_diagnosis' => 'Updated diagnosis', 'physical_exam_findings' => 'Updated exam',
                'primary_veterinarian_id' => 2, 'weight_kg' => 24, 'temperature_c' => 38.5,
                'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20, 'finish_case' => $finishCase,
            ])->assertOk()->assertJsonPath('record.chief_complaint', $origin === 'pre_registered' ? $original : 'Client replacement')
                ->assertJsonPath('record.relevant_history', 'Updated history')
                ->assertJsonPath('record.working_diagnosis', 'Updated diagnosis')
                ->assertJsonPath('record.physical_exam_findings', 'Updated exam');
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'appointment_type' => $origin, 'chief_complaint' => $original,
                'status' => $finishCase ? 'for_payment' : 'in_consultation']);
            $this->getJson('/api/admin/clinic-cases')->assertOk()
                ->assertJsonPath('cases.0.chief_complaint', $original);
            $this->getJson('/api/admin/clinic-cases/1')->assertOk()
                ->assertJsonPath('case.record.chief_complaint', $origin === 'pre_registered' ? $original : 'Client replacement');
        }
    }

    public function test_pre_registered_chief_complaint_ignores_even_invalid_client_values_and_stale_copies(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        DB::table('clinic_appointments')->where('id', 1)->update(['appointment_type' => 'pre_registered', 'chief_complaint' => null]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'chief_complaint' => 'Stale record statement']);
        foreach ([['chief_complaint' => ['invalid value']], ['chief_complaint' => str_repeat('x', 1201)],
            ['chief_complaint' => null], []] as $payload) {
            $this->postJson('/api/admin/clinic-appointments/1/record', [...$payload, 'relevant_history' => 'Other fields still save'])
                ->assertOk()->assertJsonPath('record.chief_complaint', null)
                ->assertJsonPath('record.relevant_history', 'Other fields still save');
            $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'chief_complaint' => null]);
            $this->assertDatabaseHas('clinic_records', ['clinic_appointment_id' => 1, 'chief_complaint' => null]);
        }
    }

    public function test_medical_record_admin_drafts_and_finish_require_an_active_attending_vet(): void
    {
        $this->authenticateAs('admin');
        $this->insertClinicAppointment('in_consultation');
        $this->postJson('/api/admin/clinic-appointments/1/record', ['recorded_by_user_id' => 99, 'recorded_by_name' => 'Spoofed'])
            ->assertOk()->assertJsonPath('record.primary_veterinarian_id', null)
            ->assertJsonPath('record.recorded_by_user_id', 1)->assertJsonPath('record.recorded_by_name', 'Admin User');
        $vitals = ['weight_kg' => 24, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20];
        $this->postJson('/api/admin/clinic-appointments/1/record', [...$vitals, 'finish_case' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        DB::table('users')->insert(['user_id' => 2, 'role' => 'staff', 'staff_type' => 'clinic', 'staff_subrole' => 'veterinarian', 'is_active' => false]);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['primary_veterinarian_id' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        DB::table('users')->where('user_id', 2)->update(['is_active' => true, 'first_name' => 'Attending', 'last_name' => 'Vet']);
        $this->postJson('/api/admin/clinic-appointments/1/record', [...$vitals, 'primary_veterinarian_id' => 2, 'finish_case' => true])
            ->assertOk()->assertJsonPath('record.primary_veterinarian_name', 'Attending Vet');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment']);
    }

    public function test_medical_record_vet_is_automatically_primary_and_recorder_tracks_the_saving_account(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        DB::table('users')->insert(['user_id' => 2, 'role' => 'staff', 'staff_type' => 'clinic', 'staff_subrole' => 'veterinarian', 'first_name' => 'Additional', 'last_name' => 'Vet']);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['primary_veterinarian_id' => 2, 'additional_veterinarian_ids' => [2]])
            ->assertOk()->assertJsonPath('record.primary_veterinarian_id', 1)
            ->assertJsonPath('record.additional_veterinarians.0.name', 'Additional Vet')
            ->assertJsonPath('record.recorded_by_user_id', 1);
        $this->authenticateAs('admin', 3);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['working_diagnosis' => 'Updated assessment'])
            ->assertOk()->assertJsonPath('record.primary_veterinarian_id', 1)
            ->assertJsonPath('record.additional_veterinarians.0.user_id', 2)->assertJsonPath('record.recorded_by_user_id', 3);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['additional_veterinarian_ids' => [1]])
            ->assertUnprocessable()->assertJsonValidationErrors('additional_veterinarian_ids.0');
    }

    public function test_retired_diagnostics_cannot_be_written_but_historical_data_and_files_remain_readable(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Consultation Patient']);
        DB::table('clinic_appointments')->where('id', 1)->update(['pet_id' => 1]);
        Storage::fake('local');
        $attachmentId = $this->createAttachment(1, 'clinic/attachments/1/legacy.pdf', 'historical result');
        $diagnostic = ['key' => (string) Str::uuid(), 'diagnostic' => 'CBC', 'status' => 'Completed', 'result_attachment_id' => $attachmentId];
        DB::table('clinic_records')->where('clinic_appointment_id', 1)->update(['diagnostics' => json_encode([$diagnostic])]);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnostics' => [$diagnostic]])
            ->assertUnprocessable()->assertJsonValidationErrors('diagnostics');
        $this->postJson('/api/admin/clinic-appointments/1/record', ['working_diagnosis' => 'Updated clinical assessment'])
            ->assertOk()->assertJsonPath('record.diagnostics.0.result_attachment_id', $attachmentId);
        $this->getJson('/api/admin/clinic-appointments')->assertOk()
            ->assertJsonPath('in_consultation.0.record.diagnostics.0.diagnostic', 'CBC');
        $this->getJson("/api/admin/clinic-appointments/1/attachments/{$attachmentId}/download")->assertOk();
        DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'completed']);
        $history = app(\App\Services\PetProfileHistoryService::class)->medicalRecords(\App\Models\Pet::findOrFail(1))->first();
        $this->assertSame('Completed', $history['diagnostics'][0]['status']);
        $this->assertSame('Updated clinical assessment', $history['working_diagnosis']);
        Storage::disk('local')->assertExists('clinic/attachments/1/legacy.pdf');
    }

    public function test_imaging_uses_one_service_and_charge_preserves_files_on_removal_and_locks_with_the_case(): void
    {
        $catalogue = require database_path('migrations/2026_10_13_000001_create_clinic_services_table.php');
        $services = require database_path('migrations/2026_10_13_000002_create_clinic_case_services_table.php');
        $imaging = require database_path('migrations/2026_10_15_000002_configure_diagnostic_imaging.php');
        $catalogue->up(); $services->up(); $imaging->up();
        try {
            $this->authenticateAs('staff');
            $this->insertClinicAppointment('in_consultation');
            $this->insertClinicAppointment('in_consultation', 2);
            Storage::fake('local');
            $service = ClinicService::where('name', 'X-ray — Abdominal')->sole();
            $payload = ['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid()];
            $id = $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertCreated()
                ->assertJsonPath('data.quantity', '1.00')->assertJsonPath('data.charge.amount', '1950.00')->json('data.id');
            $this->postJson('/api/admin/clinic-cases/1/services', $payload)->assertOk()->assertJsonPath('data.id', $id);
            $this->getJson('/api/admin/clinic-cases/1/services?scope=imaging')->assertOk()->assertJsonPath('data.0.id', $id);
            $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('data.0.id', $id);
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 1950);
            $files = [];
            foreach (['xray.jpg' => 'image/jpeg', 'result.jpeg' => 'image/jpeg', 'report.png' => 'image/png', 'scan.dcm' => 'application/dicom'] as $name => $mime) {
                $files[] = $this->postJson('/api/admin/clinic-appointments/1/attachments', [
                    'file' => UploadedFile::fake()->create($name, 20, $mime), 'clinic_case_service_id' => $id,
                ])->assertOk()->assertJsonPath('attachment.clinic_case_service_id', $id)->json('attachment.id');
            }
            $realDicom = UploadedFile::fake()->createWithContent('real-scan.dcm', str_repeat("\0", 128).'DICM'.str_repeat("\0", 256));
            $this->postJson('/api/admin/clinic-appointments/1/attachments', [
                'file' => $realDicom, 'clinic_case_service_id' => $id,
            ])->assertOk()->assertJsonPath('attachment.file_type', 'application/dicom');
            $dicomId = DB::table('clinic_attachments')->max('id');
            $files[] = $dicomId;
            foreach ([UploadedFile::fake()->create('result.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('renamed.png', 20, 'application/pdf'),
                UploadedFile::fake()->create('wrong-extension.pdf', 20, 'image/png'),
                UploadedFile::fake()->create('oversize.png', 10241, 'image/png')] as $invalidFile) {
                $this->postJson('/api/admin/clinic-appointments/1/attachments', [
                    'file' => $invalidFile, 'clinic_case_service_id' => $id,
                ])->assertUnprocessable()->assertJsonValidationErrors('file');
            }
            $nonImaging = ClinicService::create(['name' => 'CBC', 'category_id' => 2, 'identity_key' => hash('sha256', 'cbc'),
                'pricing_method' => 'fixed', 'billing_unit' => 'service', 'price' => 990, 'is_active' => true]);
            $cbcId = $this->postJson('/api/admin/clinic-cases/2/services', [
                'clinic_service_id' => $nonImaging->id, 'request_key' => (string) Str::uuid(),
            ])->assertCreated()->assertJsonPath('data.charge.amount', '990.00')->json('data.id');
            $this->postJson('/api/admin/clinic-appointments/2/attachments', [
                'file' => UploadedFile::fake()->create('cbc.png', 20, 'image/png'), 'clinic_case_service_id' => $cbcId,
            ])->assertUnprocessable()->assertJsonValidationErrors('clinic_case_service_id');
            $this->deleteJson('/api/admin/clinic-cases/2/services/'.$cbcId)->assertOk();
            $this->assertDatabaseCount('clinic_case_services', 1);
            $this->assertDatabaseCount('clinic_charges', 1);
            $this->postJson('/api/admin/clinic-appointments/2/attachments', [
                'file' => UploadedFile::fake()->create('foreign.png', 20, 'image/png'), 'clinic_case_service_id' => $id,
            ])->assertUnprocessable()->assertJsonValidationErrors('clinic_case_service_id');
            foreach ([['paid' => true], ['archived_at' => now()], ['status' => 'completed']] as $locked) {
                DB::table('clinic_appointments')->where('id', 1)->update(['paid' => false, 'archived_at' => null, 'status' => 'in_consultation', ...$locked]);
                $this->postJson('/api/admin/clinic-cases/1/services', [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
                $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable();
                $this->postJson('/api/admin/clinic-appointments/1/attachments', [
                    'file' => UploadedFile::fake()->create('locked.png', 20, 'image/png'), 'clinic_case_service_id' => $id,
                ])->assertConflict();
                $this->deleteJson('/api/admin/clinic-appointments/1/attachments/'.$files[0])->assertConflict();
            }
            DB::table('clinic_appointments')->where('id', 1)->update(['paid' => false, 'archived_at' => null, 'status' => 'in_consultation']);
            $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertOk();
            $this->assertDatabaseCount('clinic_case_services', 0);
            $this->assertDatabaseCount('clinic_charges', 0);
            $this->assertDatabaseCount('clinic_attachments', 5);
            foreach ($files as $fileId) {
                $this->assertDatabaseHas('clinic_attachments', ['id' => $fileId, 'clinic_case_service_id' => null]);
                $this->getJson('/api/admin/clinic-appointments/1/attachments/'.$fileId.'/download')->assertOk();
            }
            $this->getJson('/api/admin/clinic-cases/1/charges')->assertOk()->assertJsonPath('total_amount', 0);
            foreach (['X-ray', 'Ultrasound'] as $name) {
                $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnostics' => [[
                    'key' => (string) Str::uuid(), 'diagnostic' => $name, 'status' => 'Completed',
                ]]])->assertUnprocessable()->assertJsonValidationErrors('diagnostics');
            }
        } finally {
            $imaging->down(); $services->down(); $catalogue->down();
        }
    }

    public function test_medical_record_prescriptions_persist_without_stock_queries_or_charges(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        $prescription = ['medicine' => 'Amoxicillin-clavulanate', 'strength_concentration' => '250 mg/tablet',
            'dose' => '250 mg', 'route' => 'oral', 'frequency' => 'Every 12 hours', 'duration' => '7 days',
            'quantity' => '14 tablets', 'instructions' => 'Give after food'];
        $this->postJson('/api/admin/clinic-appointments/1/record', ['prescriptions' => [$prescription]])->assertOk();
        $this->getJson('/api/admin/clinic-appointments')->assertOk()
            ->assertJsonPath('in_consultation.0.record.prescriptions.0.medicine', 'Amoxicillin-clavulanate')
            ->assertJsonPath('in_consultation.0.record.prescriptions.0.quantity', '14 tablets');
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertFalse(Schema::hasTable('inventory_transactions'));
    }

    public function test_medical_record_rejects_partial_prescriptions_invalid_vitals_and_retired_diagnostics(): void
    {
        $this->authenticateAs('staff');
        $this->insertClinicAppointment('in_consultation');
        $this->postJson('/api/admin/clinic-appointments/1/record', [
            'temperature_c' => -1, 'heart_rate_bpm' => 1.5, 'respiratory_rate_bpm' => 65536, 'body_condition_score' => 10,
            'diagnostics' => [['key' => (string) \Illuminate\Support\Str::uuid(), 'diagnostic' => 'Other', 'status' => 'Unknown']],
            'prescriptions' => [['medicine' => 'Drug', 'quantity' => '-14 tablets', 'route' => 'invalid']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm',
            'body_condition_score', 'diagnostics', 'prescriptions.0.dose',
            'prescriptions.0.strength_concentration', 'prescriptions.0.frequency', 'prescriptions.0.duration',
            'prescriptions.0.route', 'prescriptions.0.quantity']);
        $this->assertDatabaseCount('clinic_records', 0);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['diagnostics' => [null], 'prescriptions' => [null]])
            ->assertUnprocessable()->assertJsonValidationErrors(['diagnostics', 'prescriptions.0']);
        $this->postJson('/api/admin/clinic-appointments/1/record', ['prescriptions' => ['malformed' => []], 'diagnostics' => ['malformed' => []]])
            ->assertUnprocessable()->assertJsonValidationErrors(['diagnostics', 'prescriptions']);
    }

    public function test_medical_record_finish_endpoint_cannot_bypass_attending_attribution(): void
    {
        $this->authenticateAs('admin');
        $this->insertClinicAppointment('in_consultation');
        $this->postJson('/api/admin/clinic-appointments/1/record', ['weight_kg' => 24, 'temperature_c' => 38.5, 'heart_rate_bpm' => 80, 'respiratory_rate_bpm' => 20])->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/finish')->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'in_consultation']);
    }

    public function test_medical_record_migration_preserves_legacy_assessment_and_exam(): void
    {
        $migration = require database_path('migrations/2026_10_12_000002_extend_clinic_consultation_records.php');
        $migration->down();
        $this->insertClinicAppointment('in_consultation');
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'diagnosis' => 'Legacy diagnosis', 'findings' => 'Legacy exam']);
        $migration->up();
        $this->assertDatabaseHas('clinic_records', ['working_diagnosis' => 'Legacy diagnosis', 'physical_exam_findings' => 'Legacy exam', 'primary_veterinarian_id' => null]);
    }

    public function test_retired_customer_rescheduling_rejects_window_changes(): void
    {
        $this->authenticateAs('customer', 10);
        $this->postJson('/api/booking/reschedule', ['booking_id' => 1, 'new_date' => now()->toDateString(), 'new_window_id' => 1])
            ->assertStatus(410)->assertJsonPath('code', 'arrival_selection_retired');
        $this->assertDatabaseCount('bookings', 0);
    }

    private function authenticateAs(string $role, int $userId = 1, string $subrole = 'veterinarian', string $staffType = 'clinic'): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => $userId,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'role' => $role,
            'staff_type' => $role === 'staff' ? ($subrole === 'grooming_receptionist' ? 'grooming' : $staffType) : null,
            'staff_subrole' => $role === 'staff' ? $subrole : null,
        ]);
        $user->exists = true;
        DB::table('users')->updateOrInsert(['user_id' => $userId], $user->getAttributes());

        Sanctum::actingAs($user, ['*']);
    }

    private function insertClinicAppointment(
        string $status,
        int $id = 1,
        ?string $reference = null,
    ): void {
        DB::table('clinic_appointments')->insert([
            'id' => $id,
            'appointment_reference' => $reference ?? 'CL-20260722-'.str_pad((string) $id, 3, '0', STR_PAD_LEFT),
            'appointment_type' => 'walk_in',
            'status' => $status,
            'queue_number' => $id,
            'appointment_date' => now()->toDateString(),
            'chief_complaint' => 'Routine consultation',
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAttachment(
        int $appointmentId,
        string $path,
        string $contents,
        string $disk = 'local',
        bool $storeFile = true,
    ): int {
        $recordId = DB::table('clinic_records')
            ->where('clinic_appointment_id', $appointmentId)
            ->value('id');

        if (! $recordId) {
            $recordId = DB::table('clinic_records')->insertGetId([
                'clinic_appointment_id' => $appointmentId,
                'chief_complaint' => 'Attachment test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($storeFile) {
            Storage::disk($disk)->put($path, $contents);
        }

        return DB::table('clinic_attachments')->insertGetId([
            'clinic_record_id' => $recordId,
            'file_name' => basename($path),
            'file_path' => $path,
            'file_type' => 'application/pdf',
            'file_size_bytes' => strlen($contents),
            'label' => 'Test attachment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
