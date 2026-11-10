<?php

namespace Tests\Feature;

use App\Models\ClinicAppointment;
use App\Models\ClinicCaseService;
use App\Models\ClinicConfinement;
use App\Models\ClinicService;
use App\Models\ClinicServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicConfinementTest extends TestCase
{
    public function test_clinic_activity_counts_distinct_current_patients_and_excludes_terminal_cases(): void
    {
        $response = $this->getJson('/api/admin/clinic/activity')->assertOk()->assertExactJson(['activeClinicCount' => 1]);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['waiting_to_arrive', 'checked_in', 'in_consultation', 'for_payment', 'completed', 'cancelled', 'no_show', 'expired', 'archived'] as $status) {
            DB::table('clinic_appointments')->where('id', 1)->update(['status' => $status, 'appointment_date' => now()->toDateString()]);
            $expected = in_array($status, ['waiting_to_arrive', 'checked_in', 'in_consultation', 'for_payment']) ? 1 : 0;
            $this->getJson('/api/admin/clinic/activity')->assertOk()->assertExactJson(['activeClinicCount' => $expected]);
        }
        DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'waiting_to_arrive', 'appointment_date' => now()->addDay()->toDateString()]);
        $this->getJson('/api/admin/clinic/activity')->assertJsonPath('activeClinicCount', 0);
        DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'in_consultation', 'appointment_date' => now()->subDay()->toDateString()]);
        $service = $this->service();
        $this->postJson('/api/admin/clinic-cases/1/confinement', ['base_service_id' => $service->id, 'request_key' => (string) Str::uuid(), 'reason' => 'Observation'])->assertSuccessful();
        DB::table('clinic_confinements')->where('clinic_appointment_id', 1)->update(['admitted_at' => now()->subDay()]);
        $this->getJson('/api/admin/clinic/activity')->assertOk()->assertExactJson(['activeClinicCount' => 1]);
        DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'waiting_to_arrive']);
        $this->getJson('/api/admin/clinic/activity')->assertJsonPath('activeClinicCount', 1);
        DB::table('clinic_confinements')->where('clinic_appointment_id', 1)->update(['discharged_at' => now()]);
        $this->getJson('/api/admin/clinic/activity')->assertJsonPath('activeClinicCount', 0);
        DB::table('clinic_confinements')->where('clinic_appointment_id', 1)->update(['discharged_at' => null]);
        DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'completed']);
        $this->getJson('/api/admin/clinic/activity')->assertJsonPath('activeClinicCount', 0);
        $this->signIn('grooming_receptionist');
        $this->getJson('/api/admin/clinic/activity')->assertOk(); // Existing receptionists have Clinic access.
        $this->app['auth']->forgetGuards();
        $user = User::findOrFail(1);
        $user->forceFill(['staff_type' => 'grooming', 'staff_subrole' => null]);
        DB::table('users')->where('user_id', 1)->update(['staff_type' => 'grooming', 'staff_subrole' => null]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/clinic/activity')->assertForbidden();
    }
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id'); $table->string('role'); $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable(); $table->string('first_name')->nullable();
            $table->string('last_name')->nullable(); $table->boolean('is_active')->default(true);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->json('customer_locked_fields')->nullable();
            $table->increments('pet_id'); $table->string('pet_name'); $table->string('species');
            $table->decimal('weight', 5, 2)->nullable(); $table->string('size')->nullable();
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id(); $table->string('appointment_reference'); $table->string('status');
            $table->string('case_type')->default('consultation'); $table->unsignedInteger('pet_id');
            $table->unsignedInteger('user_id')->nullable(); $table->date('appointment_date')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0); $table->boolean('paid')->default(false);
            $table->timestamp('archived_at')->nullable(); $table->timestamp('consultation_finished_at')->nullable(); $table->timestamps();
        });
        Schema::create('clinic_records', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique();
            $table->unsignedInteger('primary_veterinarian_id')->nullable(); $table->string('primary_veterinarian_name')->nullable();
        });
        Schema::create('clinic_vitals', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('clinic_appointment_id')->unique(); $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable(); $table->unsignedSmallInteger('heart_rate_bpm')->nullable();
            $table->unsignedSmallInteger('respiratory_rate_bpm')->nullable(); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id'); $table->string('type'); $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable(); $table->text('message');
            $table->boolean('is_read')->default(false); $table->timestamp('created_at')->nullable();
        });
        Schema::create('clinic_attachments', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('clinic_record_id'); });
        foreach (['2026_10_10_000001_create_clinic_charges_table.php', '2026_10_13_000001_create_clinic_services_table.php',
            '2026_10_13_000002_create_clinic_case_services_table.php', '2026_10_13_000004_create_clinic_confinements.php',
            '2026_10_14_000001_remove_clinic_service_type.php', '2026_10_14_000002_configure_confinement_charges.php',
            '2026_11_10_120002_add_verification_history_to_confinement_observations.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->signIn();
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi', 'species' => 'dog', 'weight' => 1, 'size' => 'small']);
        ClinicAppointment::create(['appointment_reference' => 'CL-1', 'status' => 'in_consultation', 'pet_id' => 1]);
        DB::table('clinic_records')->insert(['clinic_appointment_id' => 1, 'primary_veterinarian_id' => 1, 'primary_veterinarian_name' => 'Attending Vet']);
        DB::table('clinic_vitals')->insert(['clinic_appointment_id' => 1, 'weight_kg' => 18.4, 'temperature_c' => 38,
            'heart_rate_bpm' => 90, 'respiratory_rate_bpm' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signIn(string $subrole = 'veterinarian'): void
    {
        $user = new User;
        $user->forceFill(['user_id' => 1, 'role' => $subrole === 'admin' ? 'admin' : 'staff', 'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : 'clinic',
            'staff_subrole' => $subrole, 'is_active' => true, 'first_name' => 'Attending', 'last_name' => 'Vet']);
        DB::table('users')->updateOrInsert(['user_id' => 1], $user->getAttributes());
        Sanctum::actingAs($user);
    }

    private function service(array $changes = []): ClinicService
    {
        return ClinicService::create(array_replace(['name' => 'Confinement',
            'category_id' => ClinicServiceCategory::where('name', 'Confinement')->value('id'),
            'identity_key' => hash('sha256', Str::uuid()), 'pricing_method' => 'tiered', 'billing_unit' => 'day',
            'charge_behavior' => 'base_confinement', 'tier_basis' => 'weight_kg',
            'tiers' => array_map(fn ($size, $max) => ['size' => $size, 'price' => 500, 'max_weight_kg' => $max],
                ['small', 'medium', 'large', 'extra_large'], [10, 25, 40, null]),
            'price' => null, 'is_active' => true], $changes));
    }

    private function admit(?ClinicService $service = null): array
    {
        $service ??= $this->service();
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertCreated();
        return $payload;
    }

    private function enableUndoAudit(): void
    {
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        $this->app->forgetInstance(\App\Services\ActivityRecorder::class);
    }

    private function undoPayload(string $reason = 'Admitted the wrong patient by mistake'): array
    {
        return ['confinement_id' => ClinicConfinement::sole()->id, 'reason' => $reason];
    }

    public function test_undo_admission_reverses_only_owned_billing_preserves_consultation_and_allows_readmission(): void
    {
        $this->enableUndoAudit();
        Schema::table('clinic_appointments', fn (Blueprint $table) => $table->timestamp('consultation_started_at')->nullable());
        Schema::table('clinic_records', function (Blueprint $table) {
            $table->json('prescriptions')->nullable(); $table->text('working_diagnosis')->nullable();
        });
        DB::table('clinic_appointments')->update(['consultation_started_at' => now()->subHour()]);
        DB::table('clinic_records')->update(['prescriptions' => '[{"medicine":"Existing prescription"}]', 'working_diagnosis' => 'Existing diagnosis']);
        DB::table('clinic_attachments')->insert(['clinic_record_id' => 1]);
        $consultation = $this->service(['name' => 'Consultation', 'category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id'),
            'charge_behavior' => null, 'pricing_method' => 'fixed', 'price' => 500, 'billing_unit' => 'service']);
        $unrelated = $this->postJson('/api/admin/clinic-cases/1/services', ['clinic_service_id' => $consultation->id,
            'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $lab = ClinicAppointment::findOrFail(1)->charges()->create(['kind' => 'service', 'description' => 'Laboratory',
            'quantity' => 1, 'unit_price' => 700, 'amount' => 700]);
        $this->service(['name' => 'Professional Fee', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 450, 'billing_unit' => 'service']);
        $this->service(['name' => 'Variable automatic charge', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'starting_at', 'min_price' => 50, 'billing_unit' => 'service']);
        $this->admit();
        $payload = $this->undoPayload(str_repeat('é', 200));
        $owned = ClinicCaseService::where('id', '!=', $unrelated)->pluck('id');
        $before = [DB::table('clinic_records')->get()->toJson(), DB::table('clinic_vitals')->get()->toJson(), DB::table('clinic_attachments')->get()->toJson()];
        $started = ClinicAppointment::find(1)->consultation_started_at->toIso8601String();
        $this->assertDatabaseCount('clinic_charges', 3);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('can_undo_admission', true);
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertOk()
            ->assertJsonPath('data', null)->assertJsonPath('case.status', 'in_consultation')->assertJsonPath('case.total_amount', 1200);
        $this->assertDatabaseCount('clinic_confinements', 0);
        $this->assertDatabaseCount('clinic_confinement_charges', 0);
        $this->assertDatabaseCount('clinic_charges', 2);
        $this->assertDatabaseHas('clinic_charges', ['id' => $lab->id, 'amount' => 700]);
        $this->assertDatabaseHas('clinic_case_services', ['id' => $unrelated, 'status' => 'performed']);
        foreach ($owned as $id) {
            $entry = ClinicCaseService::findOrFail($id);
            $this->assertSame('cancelled', $entry->status);
            $this->assertNotNull($entry->cancelled_at);
            $this->assertSame(1, $entry->cancelled_by_user_id);
            $this->assertNull($entry->charge);
        }
        $case = ClinicAppointment::findOrFail(1);
        $this->assertSame($started, $case->consultation_started_at->toIso8601String());
        $this->assertNull($case->consultation_finished_at);
        $this->assertFalse($case->paid); $this->assertNull($case->paid_at);
        $this->assertSame($before, [DB::table('clinic_records')->get()->toJson(), DB::table('clinic_vitals')->get()->toJson(), DB::table('clinic_attachments')->get()->toJson()]);
        $this->assertDatabaseCount('notifications', 0);
        $event = \App\Models\ActivityLog::where('action', 'confinement_admission_undone')->sole();
        $this->assertSame('Reason for correction: '.$payload['reason'], $event->context);
        $this->assertSame('clinic_visit', $event->subject_type); $this->assertSame('1', $event->subject_id);
        $this->assertSame(1, $event->pet_id); $this->assertSame(1, $event->actor_id);
        $this->assertSame('Veterinarian', $event->actor_role); $this->assertSame('success', $event->result);
        $this->assertTrue($event->occurred_at->equalTo(now()));
        $this->assertArrayNotHasKey('reason', $event->changes ?? []);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)->assertJsonPath('cases.0.id', 1);
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->signIn('admin');
        $this->getJson('/api/admin/activity-log/'.$event->id)->assertOk()->assertJsonPath('title', 'Confinement admission undone')
            ->assertJsonPath('context', 'Mochi · Reason for correction: '.$payload['reason']);
        $this->getJson('/api/admin/activity-log?type=confinement_admission_undone')->assertOk()->assertJsonPath('total', 1);
        $this->admit(ClinicService::where('charge_behavior', 'base_confinement')->sole());
        $this->assertDatabaseCount('clinic_confinements', 1);
    }

    public function test_undo_admission_requires_reason_target_and_clinical_permission(): void
    {
        $this->enableUndoAudit(); $this->admit();
        $url = '/api/admin/clinic-cases/1/confinement/undo-admission';
        foreach ([null, '', '  ', str_repeat('x', 201), [], true] as $reason) {
            $this->postJson($url, array_replace($this->undoPayload(), ['reason' => $reason]))
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->postJson($url, ['reason' => 'Wrong admission'])->assertUnprocessable()->assertJsonValidationErrors('confinement_id');
        foreach (['clinic_receptionist', 'grooming_receptionist'] as $role) {
            $this->signIn($role); $this->postJson($url, $this->undoPayload())->assertForbidden();
            try {
                app(\App\Services\ClinicConfinementService::class)->undoAdmission(1, $this->undoPayload(), auth()->user());
                $this->fail('Service must enforce clinical permission independently.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertSame(0, \App\Models\ActivityLog::where('action', 'confinement_admission_undone')->count());
    }

    public function test_monitoring_and_removed_manual_confinement_activity_permanently_block_undo(): void
    {
        $this->enableUndoAudit();
        $additional = $this->service(['name' => 'Optional care', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 300, 'billing_unit' => 'service']);
        $this->admit(); $payload = $this->undoPayload();
        $chargeId = $this->postJson('/api/admin/clinic-cases/1/confinement/charges', ['clinic_service_id' => $additional->id,
            'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable()->assertJsonValidationErrors('confinement');
        $this->deleteJson('/api/admin/clinic-cases/1/confinement/charges/'.$chargeId)->assertOk();
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('can_undo_admission', false);
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable()->assertJsonValidationErrors('confinement');
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload())->assertCreated();
        $before = DB::table('clinic_confinement_observations')->get()->toJson();
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable();
        $this->assertSame($before, DB::table('clinic_confinement_observations')->get()->toJson());
        $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertDatabaseHas('clinic_appointments', ['total_amount' => 0, 'status' => 'in_consultation']);
    }

    public function test_monitoring_alone_blocks_undo_without_changing_billing_or_vitals(): void
    {
        $this->enableUndoAudit(); $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload())->assertCreated();
        $before = DB::table('clinic_vitals')->first();
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $this->undoPayload())->assertUnprocessable()
            ->assertJsonValidationErrors('confinement');
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('can_undo_admission', false);
        $this->assertDatabaseCount('clinic_confinements', 1); $this->assertDatabaseCount('clinic_confinement_observations', 1);
        $this->assertEquals($before, DB::table('clinic_vitals')->first());
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_financial_terminal_and_discharged_cases_reject_undo(): void
    {
        $this->enableUndoAudit(); $this->admit(); $payload = $this->undoPayload();
        foreach ([['status' => 'for_payment'], ['status' => 'completed'], ['paid' => true], ['archived_at' => now()],
            ['paid_at' => now()], ['consultation_finished_at' => now()]] as $change) {
            DB::table('clinic_appointments')->where('id', 1)->update($change);
            $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable();
            DB::table('clinic_appointments')->where('id', 1)->update(['status' => 'in_consultation', 'paid' => false,
                'archived_at' => null, 'paid_at' => null, 'consultation_finished_at' => null]);
        }
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id(); $table->string('context'); $table->string('payable_type'); $table->unsignedBigInteger('payable_id');
            $table->string('status'); $table->string('reconciliation_reason')->nullable();
        });
        $attemptId = DB::table('gateway_payments')->insertGetId(['context' => 'clinic', 'payable_type' => (new ClinicAppointment)->getMorphClass(),
            'payable_id' => 1, 'status' => 'pending']);
        foreach (['pending', 'paid'] as $status) {
            DB::table('gateway_payments')->where('id', $attemptId)->update(['status' => $status]);
            $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable();
        }
        DB::table('gateway_payments')->delete();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $before = DB::table('clinic_charges')->get()->toJson();
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $payload)->assertUnprocessable();
        $this->assertNotNull(ClinicConfinement::sole()->discharged_at);
        $this->assertSame($before, DB::table('clinic_charges')->get()->toJson());
    }

    public function test_duplicate_and_stale_undo_requests_cannot_affect_a_later_readmission(): void
    {
        $this->enableUndoAudit(); $this->admit(); $payload = $this->undoPayload();
        $url = '/api/admin/clinic-cases/1/confinement/undo-admission';
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data', null);
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'confinement_admission_undone')->count());
        $this->admit(ClinicService::where('charge_behavior', 'base_confinement')->sole());
        $newId = ClinicConfinement::sole()->id;
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('confinement');
        $this->assertSame($newId, ClinicConfinement::sole()->id);
        $this->assertSame('ordered', ClinicConfinement::sole()->caseService->status);
    }

    public function test_failure_after_undo_mutations_rolls_back_charges_services_total_and_audit(): void
    {
        $this->enableUndoAudit();
        $this->service(['name' => 'Automatic fee', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 450, 'billing_unit' => 'service']);
        $this->admit();
        $before = [DB::table('clinic_case_services')->get()->toJson(), DB::table('clinic_charges')->get()->toJson(),
            DB::table('clinic_confinement_charges')->get()->toJson(), DB::table('clinic_appointments')->get()->toJson(), DB::table('activity_logs')->count()];
        DB::statement("CREATE TRIGGER reject_confinement_delete BEFORE DELETE ON clinic_confinements BEGIN SELECT RAISE(ABORT, 'forced undo failure'); END");
        $this->postJson('/api/admin/clinic-cases/1/confinement/undo-admission', $this->undoPayload())->assertStatus(500);
        $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertSame($before, [DB::table('clinic_case_services')->get()->toJson(), DB::table('clinic_charges')->get()->toJson(),
            DB::table('clinic_confinement_charges')->get()->toJson(), DB::table('clinic_appointments')->get()->toJson(), DB::table('activity_logs')->count()]);
        $this->assertSame(0, \App\Models\ActivityLog::where('action', 'confinement_admission_undone')->count());
    }

    public static function undoAdmissionRaces(): array
    {
        return [['monitor', 'undo'], ['charge', 'undo'], ['discharge', 'undo'], ['undo', 'monitor'], ['undo', 'charge'], ['undo', 'undo']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('undoAdmissionRaces')]
    public function test_concurrent_care_discharge_and_undo_serialize_without_erasing_activity(string $firstAction, string $secondAction): void
    {
        $this->enableUndoAudit();
        $additional = $this->service(['name' => 'Optional care', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 300, 'billing_unit' => 'service']);
        $this->admit(); $episodeId = ClinicConfinement::sole()->id;
        $directory = sys_get_temp_dir().'/bethlehem-undo-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $database = $directory.'/undo.sqlite';
        $worker = <<<'PHP'
            require 'tests/bootstrap.php';
            $database = $argv[1]; $directory = realpath(dirname($database));
            if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                || !preg_match('/\Abethlehem-undo-[a-f0-9]{12}\z/', basename($directory))
                || basename($database) !== 'undo.sqlite' || !is_file($database)) throw new RuntimeException('An owned disposable Undo database is required.');
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 10000,
                'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
            Illuminate\Support\Facades\DB::purge('sqlite');
            $connection = Illuminate\Support\Facades\DB::connection();
            if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) throw new RuntimeException('Unexpected Undo database.');
            $role = $argv[2]; $action = $argv[3]; $contended = false;
            $wait = function ($file) {
                $deadline = microtime(true) + 5;
                while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                if (!is_file($file)) throw new RuntimeException('Undo concurrency barrier timed out.');
            };
            $connection->beforeStartingTransaction(function () use ($directory, $database, $role, $wait, &$contended) {
                if ($role !== 'second') return;
                $wait($directory.'/locked');
                $probe = new PDO('sqlite:'.$database); $probe->exec('PRAGMA busy_timeout=0');
                try {
                    $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK');
                    throw new RuntimeException('The first operation must still own the writer lock.');
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                    $contended = true;
                }
                file_put_contents($directory.'/attempted', '1');
            });
            $connection->listen(function ($query) use ($connection, $directory, $role, $wait) {
                if ($role === 'first' && $connection->transactionLevel() && str_contains($query->sql, 'from "clinic_appointments"')) {
                    file_put_contents($directory.'/locked', '1'); $wait($directory.'/attempted');
                }
            });
            $actor = App\Models\User::findOrFail(1); auth()->setUser($actor);
            $service = app(App\Services\ClinicConfinementService::class);
            try {
                match ($action) {
                    'undo' => $service->undoAdmission(1, ['confinement_id' => (int) $argv[4], 'reason' => 'Accidental admission'], $actor),
                    'monitor' => $service->recordObservation(1, ['request_key' => (string) Illuminate\Support\Str::uuid(),
                        'temperature_c' => '38.5', 'heart_rate_bpm' => '90', 'respiratory_rate_bpm' => '20'], $actor),
                    'charge' => $service->addCharge(1, ['request_key' => (string) Illuminate\Support\Str::uuid(), 'clinic_service_id' => (int) $argv[5]], $actor),
                    'discharge' => $service->discharge(1, [], $actor),
                };
                $status = 200;
            } catch (Illuminate\Validation\ValidationException) { $status = 422;
            } catch (Illuminate\Database\Eloquent\ModelNotFoundException) { $status = 404; }
            echo json_encode(['status' => $status, 'contended' => $contended]);
            PHP;
        $processes = [];
        try {
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            foreach (['first' => $firstAction, 'second' => $secondAction] as $role => $action) {
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, $role, $action, (string) $episodeId, (string) $additional->id], base_path(), [
                    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => storage_path('framework/testing-config-unused.php'),
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
                    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(15); $process->start(); $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertTrue($results[1]['contended'], 'The competing operation must encounter an overlapping writer lock.');
            $this->assertSame(200, $results[0]['status']);
            $this->assertSame($firstAction === 'undo' ? ($secondAction === 'undo' ? 200 : 404) : 422, $results[1]['status']);
            $pdo = new \PDO('sqlite:'.$database);
            $this->assertEquals($firstAction === 'undo' ? 0 : 1, $pdo->query('SELECT COUNT(*) FROM clinic_confinements')->fetchColumn());
            $this->assertEquals($firstAction === 'monitor' ? 1 : 0, $pdo->query('SELECT COUNT(*) FROM clinic_confinement_observations')->fetchColumn());
            $this->assertEquals($firstAction === 'undo' ? 1 : 0, $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'confinement_admission_undone'")->fetchColumn());
            if ($firstAction === 'charge') $this->assertEquals(1, $pdo->query('SELECT COUNT(*) FROM clinic_confinement_charges')->fetchColumn());
            if ($firstAction === 'discharge') $this->assertNotNull($pdo->query('SELECT discharged_at FROM clinic_confinements')->fetchColumn());
            $pdo = null;
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_carry_over_confined_case_is_discoverable_without_appearing_in_active_cases(): void
    {
        $this->admit();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')->assertJsonPath('confined_count', 1);
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.status', 'in_consultation');
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()->assertJsonPath('case.id', 1)
            ->assertJsonPath('case.confinement.discharged_at', null);

        ClinicAppointment::find(1)->update(['status' => 'completed']);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonCount(0, 'cases')->assertJsonPath('confined_count', 0);
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/pets/1/clinic-case')->assertOk()->assertJsonPath('case', null);
    }

    public function test_active_list_omits_workspace_data_and_details_remain_available_on_demand(): void
    {
        $controller = new \App\Http\Controllers\AdminClinicController;
        $full = $controller->formatAppointment(ClinicAppointment::findOrFail(1));
        DB::enableQueryLog(); DB::flushQueryLog();
        $list = $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('cases.0.pet.name', 'Mochi');
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $row = $list->json('cases.0');
        $this->assertArrayNotHasKey('record', $row);
        $this->assertArrayNotHasKey('vitals', $row);
        $this->assertArrayNotHasKey('charges', $row);
        $this->assertArrayNotHasKey('medical_conditions', $row['pet']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select .* from ["`](clinic_records|clinic_vitals|clinic_attachments|clinic_charges)["`]/i', $query['query']);
        }
        $this->assertLessThan(strlen(json_encode($full)), strlen(json_encode($row)));
        $this->getJson('/api/admin/clinic-cases/1')->assertOk()
            ->assertJsonPath('case.vitals.weight_kg', '18.40')
            ->assertJsonPath('case.record.primary_veterinarian_name', $full['record']['primary_veterinarian_name']);
        $this->signIn('grooming_receptionist');
        $this->getJson('/api/admin/clinic-cases/1')->assertOk()
            ->assertJsonPath('case.vitals.weight_kg', '18.40')
            ->assertJsonPath('case.record.primary_veterinarian_name', $full['record']['primary_veterinarian_name']);
        fwrite(STDOUT, "\nClinic fixture list=".strlen(json_encode($row))." bytes; full=".strlen(json_encode($full))." bytes; list queries=".count($queries)."\n");
    }

    public function test_admission_links_one_ordered_service_to_the_same_case_and_retries_are_idempotent(): void
    {
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_appointments', 1); $this->assertDatabaseCount('clinic_confinements', 1);
        $this->assertDatabaseCount('clinic_case_services', 1); $this->assertDatabaseCount('clinic_charges', 0);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'in_consultation']);
        $this->assertDatabaseHas('clinic_case_services', ['status' => 'ordered']);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.attending_veterinarian_name', 'Attending Vet');
    }

    public function test_admission_requires_ongoing_case_primary_veterinarian_and_appropriate_catalogue_configuration(): void
    {
        $service = $this->service();
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'];
        foreach (['checked_in', 'for_payment', 'completed'] as $status) {
            ClinicAppointment::find(1)->update(['status' => $status]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
        }
        ClinicAppointment::find(1)->update(['status' => 'in_consultation']);
        foreach (['paid' => true, 'archived_at' => now()] as $field => $value) {
            ClinicAppointment::find(1)->update([$field => $value]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
            ClinicAppointment::find(1)->update([$field => $field === 'paid' ? false : null]);
        }
        DB::table('clinic_records')->update(['primary_veterinarian_id' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('primary_veterinarian_id');
        DB::table('clinic_records')->update(['primary_veterinarian_id' => 1]);
        foreach ([['billing_unit' => 'sample'], ['billing_unit' => 'day', 'is_active' => false],
            ['is_active' => true, 'category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]] as $change) {
            $service->update($change);
            $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable()->assertJsonValidationErrors('confinement');
        }
        $this->assertDatabaseCount('clinic_confinements', 0); $this->assertDatabaseCount('clinic_case_services', 0);
    }

    public function test_monitoring_is_append_only_chronological_idempotent_and_does_not_overwrite_vitals(): void
    {
        $this->admit(); $before = DB::table('clinic_vitals')->first();
        $notes = str_repeat('x', 500);
        $payload = $this->monitoringPayload(['temperature_c' => '39.1', 'notes' => $notes]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', [...$payload, 'notes' => $notes.'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk();
        Carbon::setTestNow(now()->addHour());
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload(['heart_rate_bpm' => '95']))->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk()->assertJsonPath('data.0.notes', $notes)->assertJsonPath('data.1.heart_rate_bpm', 95);
        $this->assertEquals($before, DB::table('clinic_vitals')->first()); $this->assertDatabaseCount('clinic_charges', 0);
    }

    private function monitoringPayload(array $changes = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'temperature_c' => '38.5',
            'heart_rate_bpm' => '90', 'respiratory_rate_bpm' => '20'], $changes);
    }

    public function test_monitoring_requires_each_vital_and_notes_alone_cannot_create_an_observation(): void
    {
        $this->admit();
        foreach (['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm'] as $field) {
            foreach ([null, '', '   '] as $missing) {
                $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload([$field => $missing]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', ['request_key' => (string) Str::uuid(), 'notes' => 'Alert'])
            ->assertUnprocessable()->assertJsonValidationErrors(['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm']);
        $this->assertDatabaseCount('clinic_confinement_observations', 0);
    }

    public function test_monitoring_rejects_invalid_format_precision_and_unverified_exceptional_values(): void
    {
        $this->admit();
        foreach (['temperature_c' => ['38.55', '3.85e1', '-1', '1000', '24.9', '45.1', 'abc', true, []],
            'heart_rate_bpm' => ['80.5', '8e1', '-1', '65536', '0', '401', 'abc'],
            'respiratory_rate_bpm' => ['20.5', '2e1', '-1', '65536', '0', '201', 'abc']] as $field => $values) {
            foreach ($values as $value) {
                $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload([$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseCount('clinic_confinement_observations', 0);
    }

    public function test_monitoring_accepts_standard_boundaries_and_species_unusual_readings_without_verification(): void
    {
        $this->admit();
        foreach ([['25', '1', '1'], ['45', '400', '200'], ['40.5', '180', '45']] as [$temperature, $heart, $respiratory]) {
            $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload([
                'temperature_c' => $temperature, 'heart_rate_bpm' => $heart, 'respiratory_rate_bpm' => $respiratory,
            ]))->assertCreated();
        }
        DB::table('pets')->update(['species' => 'cat']);
        $this->signIn('admin');
        $payload = $this->monitoringPayload(['temperature_c' => '37.5', 'heart_rate_bpm' => '100', 'respiratory_rate_bpm' => '20']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_confinement_observations', 4);
    }

    public function test_monitoring_exception_requires_a_veterinarian_and_current_value_with_a_valid_reason(): void
    {
        $this->admit();
        $payload = $this->monitoringPayload(['heart_rate_bpm' => '401',
            'vital_exceptions' => ['heart_rate_bpm' => ['value' => '401', 'reason' => 'Confirmed by auscultation']]]);
        $this->signIn('admin');
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('heart_rate_bpm');
        $this->signIn();
        foreach ([null, ['value' => '401'], ['value' => '401', 'reason' => ''],
            ['value' => '401', 'reason' => 'abcd'], ['value' => '401', 'reason' => '    a    '],
            ['value' => '401', 'reason' => str_repeat('x', 501)],
            ['value' => '402', 'reason' => 'Confirmed by auscultation']] as $evidence) {
            $this->postJson('/api/admin/clinic-cases/1/confinement/observations', array_replace($payload,
                ['vital_exceptions' => ['heart_rate_bpm' => $evidence]]))
                ->assertUnprocessable()->assertJsonValidationErrors('heart_rate_bpm');
        }
        $this->assertDatabaseCount('clinic_confinement_observations', 0);
    }

    public function test_monitoring_evidence_is_observation_specific_server_generated_and_idempotent(): void
    {
        $this->admit();
        $payload = $this->monitoringPayload(['temperature_c' => '46', 'heart_rate_bpm' => '0', 'respiratory_rate_bpm' => '201',
            'vital_exceptions' => ['temperature_c' => ['value' => '46', 'reason' => 'Confirmed temperature'],
                'heart_rate_bpm' => ['value' => '0', 'reason' => str_repeat('x', 500)],
                'respiratory_rate_bpm' => ['value' => '201', 'reason' => 'Confirmed respiratory rate']]]);
        $first = $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertCreated()->json('data');
        $history = $first['verification_history'];
        $this->assertCount(3, $history);
        foreach ($history as $entry) {
            $this->assertSame($payload[$entry['field']], $entry['value']);
            $this->assertSame($payload['vital_exceptions'][$entry['field']]['reason'], $entry['reason']);
            $this->assertSame(1, $entry['verified_by_user_id']);
            $this->assertSame('Attending Vet', $entry['verified_by_name']);
            $this->assertSame(now()->toIso8601String(), $entry['verified_at']);
        }
        $this->assertSame($history, \App\Models\ClinicConfinementObservation::first()->verification_history);
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk()->assertJsonPath('data.0.verification_history', $history);
        Carbon::setTestNow(now()->addHour());
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk()
            ->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.verification_history', $history);
        $this->assertDatabaseCount('clinic_confinement_observations', 1);

        (require database_path('migrations/2026_10_27_000001_add_clinic_vital_verification_history.php'))->up();
        DB::table('clinic_vitals')->update(['temperature_c' => 46, 'verification_history' => json_encode($history)]);
        $unverified = $payload;
        unset($unverified['vital_exceptions']);
        $unverified['request_key'] = (string) Str::uuid();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $unverified)->assertUnprocessable()
            ->assertJsonValidationErrors(['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $payload)->assertOk()->assertJsonPath('data.verification_history', $history);
        $this->assertDatabaseCount('clinic_confinement_observations', 1);
    }

    public function test_monitoring_rejects_client_supplied_audit_identity_and_history(): void
    {
        $this->admit();
        foreach (['verification_history' => [['field' => 'heart_rate_bpm', 'value' => '401']],
            'recorded_by_user_id' => 999, 'recorded_at' => '2000-01-01'] as $field => $value) {
            $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload([$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload([
            'heart_rate_bpm' => '401', 'vital_exceptions' => ['heart_rate_bpm' => ['value' => '401',
                'reason' => 'Confirmed by auscultation', 'verified_by_user_id' => 999, 'verified_at' => '2000-01-01']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('vital_exceptions.heart_rate_bpm');
        $this->assertDatabaseCount('clinic_confinement_observations', 0);
    }

    public function test_monitoring_verification_migration_preserves_legacy_partial_history_and_pagination(): void
    {
        $this->admit();
        $migration = require database_path('migrations/2026_11_10_120002_add_verification_history_to_confinement_observations.php');
        $migration->down();
        $legacy = [];
        for ($i = 0; $i < 21; $i++) {
            $legacy[] = ['clinic_confinement_id' => 1, 'request_key' => (string) Str::uuid(), 'recorded_at' => now()->addMinutes($i),
                'temperature_c' => $i === 0 ? 12.3 : null, 'notes' => 'Legacy observation '.$i, 'recorded_by_user_id' => 1];
        }
        DB::table('clinic_confinement_observations')->insert($legacy);
        $migration->up();
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk()->assertJsonPath('total', 21)
            ->assertJsonPath('last_page', 2)->assertJsonCount(20, 'data')->assertJsonPath('data.0.temperature_c', '12.3')
            ->assertJsonPath('data.0.heart_rate_bpm', null)->assertJsonPath('data.0.verification_history', null)
            ->assertJsonPath('data.0.notes', 'Legacy observation 0')->assertJsonPath('data.19.notes', 'Legacy observation 19');
        $this->getJson('/api/admin/clinic-cases/1/confinement/observations?page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.notes', 'Legacy observation 20');
        $this->assertDatabaseCount('clinic_confinement_observations', 21);
    }

    public function test_receptionist_can_view_but_cannot_admit_monitor_or_discharge_and_confined_cases_cannot_be_paid(): void
    {
        $this->admit();
        foreach (['clinic_receptionist', 'grooming_receptionist'] as $subrole) {
            $this->signIn($subrole);
            $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk();
            $this->getJson('/api/admin/clinic-cases/1/confinement/observations')->assertOk();
            foreach (['', '/observations', '/discharge'] as $suffix) $this->postJson('/api/admin/clinic-cases/1/confinement'.$suffix)->assertForbidden();
        }
        $this->signIn();
        $this->postJson('/api/admin/clinic-appointments/1/pay', ['total_amount' => 0, 'payment_method' => 'cash'])->assertUnprocessable();
    }

    public function test_normal_finish_and_direct_confinement_service_mutations_are_blocked(): void
    {
        $this->admit();
        foreach (['/api/admin/clinic-cases/1/finish', '/api/admin/clinic-appointments/1/finish-consultation'] as $url)
            $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = ClinicCaseService::first()->id;
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable()->assertJsonValidationErrors('services');
        $entry = ClinicCaseService::findOrFail($id);
        $this->postJson('/api/admin/clinic-cases/1/services', ['clinic_service_id' => $entry->clinic_service_id,
            'request_key' => $entry->request_key])->assertUnprocessable();
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame('ordered', $entry->fresh()->status);
        $this->assertDatabaseCount('clinic_charges', 0);
    }

    public function test_discharged_confinement_service_cannot_be_removed_through_services_tab(): void
    {
        $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $id = ClinicCaseService::first()->id;
        $this->signIn('admin');
        $this->deleteJson('/api/admin/clinic-cases/1/services/'.$id)->assertUnprocessable()->assertJsonValidationErrors('services');
        $this->getJson('/api/admin/clinic-cases/1/services')->assertOk()->assertJsonPath('data.0.confinement_exists', true);
        $this->assertDatabaseHas('clinic_case_services', ['id' => $id, 'status' => 'performed']);
        $this->assertDatabaseHas('clinic_charges', ['clinic_case_service_id' => $id, 'amount' => 500]);
        $this->assertEquals(500, ClinicAppointment::find(1)->total_amount);
        $this->assertNotNull(ClinicConfinement::first()->discharged_at);
    }

    public function test_discharge_finalizes_one_service_one_charge_and_one_payment_due_transition(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addHours(6));
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['discharge_note' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('discharge_note');
        $note = str_repeat('x', 500);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['applied_price' => 1, 'discharge_note' => $note])->assertOk()
            ->assertJsonPath('data.case_service.applied_price', '500.00')->assertJsonPath('data.case_service.quantity', '1.00')
            ->assertJsonPath('data.discharge_note', $note);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()->assertJsonPath('data.discharge_note', $note);
        $this->assertDatabaseCount('clinic_charges', 1); $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'total_amount' => 500]);
        $this->assertNotNull(ClinicConfinement::first()->discharged_at);
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload(['notes' => 'Late']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_exact_elapsed_duration_rules(): void
    {
        $rule = app(\App\Services\ConfinementBillingDuration::class); $start = now();
        foreach ([0 => 1, 6 * 3600 => 1, 86400 => 1, 86460 => 2, 172800 => 2] as $seconds => $days)
            $this->assertSame($days, $rule->billableDays($start, $start->copy()->addSeconds($seconds)));
    }

    public function test_day_quantity_is_derived_by_server_and_cannot_be_supplied_by_client(): void
    {
        $this->admit(); Carbon::setTestNow(now()->addDay()->addMinute());
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()->assertJsonPath('quantity', 2);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['quantity' => 1])->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()->assertJsonPath('data.case_service.charge.amount', '1000.00');
    }

    public function test_admission_preview_uses_live_weight_without_saving_it(): void
    {
        $this->service();
        DB::table('clinic_vitals')->update(['weight_kg' => null]);
        $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg=18.4')->assertOk()
            ->assertJsonPath('pricing.pet_size', 'medium')->assertJsonPath('pricing.resolved_price', '500.00');
        $this->assertDatabaseHas('clinic_vitals', ['clinic_appointment_id' => 1, 'weight_kg' => null]);
        $this->assertDatabaseCount('clinic_confinements', 0);
        foreach (['0.01', '0.10'] as $weight) {
            $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg='.$weight)
                ->assertOk()->assertJsonPath('pricing.pet_size', 'small');
        }
        foreach (['0', '155.61', '0.001'] as $weight) {
            $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg='.$weight)
                ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        }
        DB::table('clinic_vitals')->update(['weight_kg' => 18.4]);
        $response = $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview?weight_kg=')->assertOk();
        $this->assertNotNull($response->json('pricing.error'), 'A cleared live weight does not fall back to saved weight');
    }

    public function test_automatic_base_selection_weight_resolution_and_historical_prices(): void
    {
        $service = $this->service();
        $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview')->assertOk()
            ->assertJsonPath('data.id', $service->id)->assertJsonPath('pricing.pet_size', 'medium');
        DB::table('clinic_vitals')->update(['weight_kg' => null]);
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Sample'];
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertUnprocessable();
        DB::table('clinic_vitals')->update(['weight_kg' => 18.4]);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertCreated();
        $snapshot = ClinicCaseService::first()->service_snapshot;
        $this->assertSame('medium', $snapshot['pet_size']);
        $this->assertEquals(18.4, $snapshot['pet_weight_kg']);
        DB::table('clinic_vitals')->update(['weight_kg' => 50]);
        $service->update(['tiers' => array_map(fn ($size) => ['size' => $size, 'price' => 650], ['small', 'medium', 'large', 'extra_large']), 'is_active' => false]);
        $this->service(['name' => 'Replacement base']);
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00')->assertJsonPath('pricing.pet_size', 'medium');
        $this->getJson('/api/admin/clinic-cases/1/confinement/discharge-preview')->assertOk()
            ->assertJsonPath('pricing.resolved_price', '500.00')->assertJsonPath('pricing.pet_weight_kg', '18.40');
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk()
            ->assertJsonPath('data.case_service.clinic_service_id', $service->id)
            ->assertJsonPath('data.case_service.applied_price', '500.00')->assertJsonPath('data.case_service.service_snapshot.pet_size', 'medium');
        $this->assertSame($snapshot, ClinicCaseService::first()->service_snapshot);
        $service->update(['pricing_method' => 'fixed', 'price' => 900, 'name' => 'Renamed']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertSame($snapshot, ClinicCaseService::first()->service_snapshot);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 500]);
    }

    public function test_clinic_cutoffs_are_inclusive_continuous_and_independent_of_species(): void
    {
        $rates = ['small' => 101, 'medium' => 202, 'large' => 303, 'extra_large' => 404];
        $this->service(['tiers' => array_map(fn ($size, $max) => ['size' => $size, 'price' => $rates[$size], 'max_weight_kg' => $max],
            ['small', 'medium', 'large', 'extra_large'], [10, 25, 40, null])]);
        foreach (['dog', 'cat'] as $species) {
            DB::table('pets')->update(['species' => $species]);
            foreach (['0.01' => 'small', '10' => 'small', '10.01' => 'medium', '25' => 'medium',
                '25.01' => 'large', '40' => 'large', '40.01' => 'extra_large', '155.6' => 'extra_large'] as $weight => $size) {
                DB::table('clinic_vitals')->update(['weight_kg' => $weight]);
                $this->getJson('/api/admin/clinic-cases/1/confinement/admission-preview')->assertOk()->assertJsonPath('pricing.pet_size', $size)
                    ->assertJsonPath('pricing.resolved_price', number_format($rates[$size], 2, '.', ''));
            }
        }
        foreach ([null, 0, -1] as $weight) {
            DB::table('clinic_vitals')->update(['weight_kg' => $weight]);
            $this->postJson('/api/admin/clinic-cases/1/confinement', ['request_key' => (string) Str::uuid(), 'reason' => 'Observation'])
                ->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        }
        $this->assertDatabaseCount('clinic_confinements', 0);
    }

    public function test_upgrade_snapshots_legacy_active_rates_once_without_changing_catalogue_or_billing(): void
    {
        $service = $this->service(); $this->admit($service);
        $entry = ClinicCaseService::first();
        $entry->forceFill(['applied_price' => null, 'service_snapshot' => null])->save();
        $service->update(['tier_basis' => 'pet_size', 'tiers' => array_map(fn ($size) => ['size' => $size, 'price' => 650],
            ['small', 'medium', 'large', 'extra_large'])]);
        $migration = require database_path('migrations/2026_10_15_000001_snapshot_active_confinement_rates.php');
        $migration->up();
        $snapshot = $entry->fresh()->service_snapshot;
        $this->assertSame('650.00', $entry->fresh()->applied_price);
        $this->assertSame('pet_size', $service->fresh()->tier_basis);
        $this->assertDatabaseCount('clinic_charges', 0);
        $service->update(['price' => 900, 'pricing_method' => 'fixed']); $migration->up();
        $this->assertSame($snapshot, $entry->fresh()->service_snapshot);
    }

    public function test_missing_duplicate_and_invalid_base_configuration_blocks_admission(): void
    {
        $url = '/api/admin/clinic-cases/1/confinement';
        $payload = ['request_key' => (string) Str::uuid(), 'reason' => 'Sample'];
        $this->postJson($url, $payload)->assertUnprocessable();
        $base = $this->service(); $other = $this->service(['name' => 'Another base']);
        $this->postJson($url, $payload)->assertUnprocessable();
        $other->update(['is_active' => false]); $base->update(['tiers' => [['size' => 'medium', 'price' => 500]]]);
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('clinic_confinements', 0);
    }

    private function configureIvInsertionPolicy(): void
    {
        (require database_path('migrations/2026_10_31_000002_make_iv_insertion_an_additional_confinement_charge.php'))->up();
    }

    public function test_iv_insertion_is_optional_and_manual_charges_retry_remove_and_discharge_correctly(): void
    {
        $professional = $this->service(['name' => 'Professional Fee', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 475, 'billing_unit' => null]);
        $iv = $this->service(['name' => 'IV Insertion', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 985, 'billing_unit' => null]);
        $this->configureIvInsertionPolicy();
        $this->assertSame('additional_charge', $iv->fresh()->charge_behavior);
        $this->assertSame('service', $iv->fresh()->billing_unit);
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()
            ->assertJsonCount(1, 'data.confinement_charges')
            ->assertJsonPath('data.confinement_charges.0.charge_behavior', 'admission_charge')
            ->assertJsonPath('data.confinement_charges.0.case_service.clinic_service_id', $professional->id);
        $this->assertDatabaseMissing('clinic_case_services', ['clinic_service_id' => $iv->id]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'total_amount' => 475]);
        $this->getJson('/api/admin/clinic-confinement-service-options')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $iv->id)
            ->assertJsonPath('data.0.price', '985.00')->assertJsonPath('data.0.billing_unit', 'service');

        $url = '/api/admin/clinic-cases/1/confinement/charges';
        $add = ['clinic_service_id' => $iv->id, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $add)->assertCreated()
            ->assertJsonPath('data.charge_behavior', 'additional_charge')
            ->assertJsonPath('data.case_service.charge.amount', '985.00')->json('data.id');
        $this->postJson($url, $add)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('clinic_charges', 2);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'total_amount' => 1460]);
        $this->signIn('clinic_receptionist');
        $this->deleteJson($url.'/'.$id)->assertForbidden();
        $this->signIn();
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->postJson($url, $add)->assertOk();
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'total_amount' => 475]);
        $this->assertDatabaseCount('clinic_charges', 1);
        $this->assertDatabaseHas('clinic_case_services', ['request_key' => $add['request_key'], 'status' => 'cancelled']);

        $this->postJson($url, [...$add, 'request_key' => (string) Str::uuid()])->assertCreated();
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 3);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 500, 'quantity' => 2, 'amount' => 1000]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'total_amount' => 2460]);
    }

    public function test_iv_policy_migration_preserves_catalogue_prices_and_active_and_completed_charges(): void
    {
        $iv = $this->service(['name' => 'IV Insertion', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 970, 'billing_unit' => null]);
        $this->service(['name' => 'Other admission fee', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 200]);
        $this->service(['name' => 'IV Insertion', 'category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]);
        $payload = $this->admit();
        $catalogue = DB::table('clinic_services')->orderBy('id')->get()->keyBy('id');
        $tables = ['clinic_confinements', 'clinic_confinement_charges', 'clinic_case_services', 'clinic_charges', 'clinic_appointments'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $this->configureIvInsertionPolicy();
        $this->configureIvInsertionPolicy();
        $this->assertSame('additional_charge', $iv->fresh()->charge_behavior);
        $this->assertSame('service', $iv->fresh()->billing_unit);
        foreach ($catalogue as $id => $row) {
            $expected = clone $row;
            if ($id === $iv->id) { $expected->charge_behavior = 'additional_charge'; $expected->billing_unit = 'service'; }
            $this->assertEquals($expected, DB::table('clinic_services')->find($id));
        }
        foreach ($tables as $table) $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray(), $table);
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_confinement_charges', 2);
        $historicalIv = DB::table('clinic_confinement_charges')->whereIn('clinic_case_service_id',
            ClinicCaseService::where('clinic_service_id', $iv->id)->select('id'))->first();
        $this->assertSame('admission_charge', $historicalIv->charge_behavior);
        $this->deleteJson('/api/admin/clinic-cases/1/confinement/charges/'.$historicalIv->id)->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        ClinicAppointment::findOrFail(1)->update(['paid' => true, 'status' => 'completed']);
        $completed = [];
        foreach ($tables as $table) $completed[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $this->configureIvInsertionPolicy();
        foreach ($tables as $table) $this->assertEquals($completed[$table], DB::table($table)->orderBy('id')->get()->toArray(), $table);
    }

    public function test_iv_policy_migration_does_not_create_a_missing_service(): void
    {
        $this->configureIvInsertionPolicy();
        $this->assertDatabaseCount('clinic_services', 0);
    }

    public function test_discharge_without_manually_adding_iv_insertion_bills_only_base_and_professional_fee(): void
    {
        $this->service(['name' => 'Professional Fee', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 460, 'billing_unit' => null]);
        $iv = $this->service(['name' => 'IV Insertion', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'fixed', 'price' => 990, 'billing_unit' => null]);
        $this->configureIvInsertionPolicy();
        $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 2);
        $this->assertDatabaseMissing('clinic_case_services', ['clinic_service_id' => $iv->id]);
        $this->assertDatabaseHas('clinic_appointments', ['id' => 1, 'status' => 'for_payment', 'total_amount' => 960]);
    }

    public function test_iv_policy_migration_preserves_variable_pricing_and_inactive_status(): void
    {
        $iv = $this->service(['name' => 'IV Insertion', 'charge_behavior' => 'admission_charge',
            'pricing_method' => 'range', 'min_price' => 800, 'max_price' => 1200, 'billing_unit' => null, 'is_active' => false]);
        $before = $iv->fresh()->getAttributes();
        $this->configureIvInsertionPolicy();
        $this->assertEquals(array_replace($before, ['charge_behavior' => 'additional_charge', 'billing_unit' => 'service']), $iv->fresh()->getAttributes());
    }

    public function test_iv_policy_migration_rejects_unexpected_configuration_without_changing_it(): void
    {
        $iv = $this->service(['name' => 'IV Insertion']);
        $before = $iv->fresh()->getAttributes();
        try {
            $this->configureIvInsertionPolicy();
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Unexpected Confinement IV Insertion', $error->getMessage());
            $this->assertEquals($before, $iv->fresh()->getAttributes());
            return;
        }
        $this->fail('Unexpected IV Insertion configuration must require administrator review.');
    }

    public function test_iv_policy_migration_rejects_ambiguous_records_without_changing_them(): void
    {
        $this->service(['name' => 'IV Insertion', 'charge_behavior' => 'admission_charge']);
        $this->service(['name' => '  iv   insertion ', 'charge_behavior' => 'admission_charge', 'is_active' => false]);
        $before = DB::table('clinic_services')->get()->toArray();
        try {
            $this->configureIvInsertionPolicy();
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('IV Insertion', $error->getMessage());
            $this->assertEquals($before, DB::table('clinic_services')->get()->toArray());
            return;
        }
        $this->fail('Ambiguous IV Insertion identities must require administrator review.');
    }

    public function test_admission_extras_and_retry_protection_preserve_other_case_charges(): void
    {
        $this->service(['name' => 'Automatic A', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 450, 'billing_unit' => null]);
        $this->service(['name' => 'Automatic B', 'charge_behavior' => 'admission_charge', 'pricing_method' => 'fixed', 'price' => 200]);
        $one = $this->service(['name' => 'Optional', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 350, 'billing_unit' => null]);
        $daily = $this->service(['name' => 'Daily', 'charge_behavior' => 'additional_charge', 'pricing_method' => 'fixed', 'price' => 800]);
        $payload = $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement', $payload)->assertOk();
        $this->assertDatabaseCount('clinic_charges', 2);
        $url = '/api/admin/clinic-cases/1/confinement/charges';
        $add = ['clinic_service_id' => $one->id, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $add)->assertCreated()->json('data.id');
        $this->postJson($url, $add)->assertOk();
        $this->deleteJson($url.'/'.$id)->assertOk(); $this->deleteJson($url.'/'.$id)->assertOk();
        $this->postJson($url, $add)->assertOk(); // Removed requests remain tombstoned.
        $this->assertDatabaseCount('clinic_charges', 2);
        $add = ['clinic_service_id' => $daily->id, 'request_key' => (string) Str::uuid()];
        $id = $this->postJson($url, $add)->assertCreated()->json('data.id');
        $this->postJson($url, $add)->assertOk();
        $this->postJson($url, [...$add, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($url, [...$add, 'quantity' => 3])->assertUnprocessable();
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->postJson($url.'/'.$id.'/stop')->assertOk()->assertJsonPath('data.case_service.quantity', '2.00');
        $this->postJson($url.'/'.$id.'/stop')->assertOk();
        $daily->update(['price' => 900]);
        $this->postJson($url, ['clinic_service_id' => $daily->id, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->assertDatabaseCount('clinic_charges', 5);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 800, 'quantity' => 2, 'amount' => 1600]);
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 900, 'quantity' => 1, 'amount' => 900]);
        $this->assertSame(0, \App\Models\ClinicConfinementCharge::whereNotNull('started_at')->whereNull('stopped_at')->whereNull('removed_at')->count());
        $this->assertDatabaseHas('clinic_appointments', ['total_amount' => 4150, 'status' => 'for_payment']);
    }

    public function test_variable_extras_require_final_price_only_when_finalized(): void
    {
        $service = $this->service(['charge_behavior' => 'additional_charge', 'pricing_method' => 'range', 'min_price' => 500, 'max_price' => 1000]);
        $this->admit();
        $url = '/api/admin/clinic-cases/1/confinement/charges';
        $id = $this->postJson($url, ['clinic_service_id' => $service->id, 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson($url.'/'.$id.'/stop')->assertUnprocessable();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable();
        $this->assertDatabaseCount('clinic_charges', 0);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge', ['charge_prices' => [$id => 750]])->assertOk();
        $this->assertDatabaseHas('clinic_charges', ['unit_price' => 750]);
    }

    public function test_failed_discharge_rolls_back_episode_service_charge_and_total(): void
    {
        $this->admit();
        DB::table('clinic_records')->update(['primary_veterinarian_id' => null]);
        DB::table('clinic_vitals')->update(['temperature_c' => null, 'heart_rate_bpm' => 401, 'respiratory_rate_bpm' => null]);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_veterinarian_id', 'temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm'])
            ->assertJsonMissingValidationErrors('weight_kg');
        $this->assertNull(ClinicConfinement::first()->discharged_at); $this->assertSame('ordered', ClinicCaseService::first()->status);
        $this->assertDatabaseCount('clinic_charges', 0); $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('clinic_appointments', ['status' => 'in_consultation', 'total_amount' => 0]);
    }

    public function test_database_prevents_duplicate_episodes(): void
    {
        $this->admit(); $attributes = ClinicConfinement::first()->getAttributes(); unset($attributes['id']);
        $this->expectException(QueryException::class); DB::table('clinic_confinements')->insert($attributes);
    }

    public function test_confined_list_and_count_use_active_episodes_and_discharge_retains_viewable_history(): void
    {
        $this->admit();
        foreach ([now()->toDateString(), now()->subDay()->toDateString()] as $date) {
            ClinicAppointment::find(1)->update(['appointment_date' => $date]);
            $before = ClinicAppointment::find(1)->getAttributes();
            $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 1)
                ->assertJsonCount(0, 'cases');
            $this->assertSame($before, ClinicAppointment::find(1)->getAttributes());
        }
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.status', 'in_consultation')
            ->assertJsonPath('data.0.confinement.case_service.status', 'ordered')
            ->assertJsonPath('data.0.record.primary_veterinarian_id', 1)
            ->assertJsonPath('data.0.vitals.weight_kg', '18.40');
        $this->assertDatabaseCount('clinic_appointments', 1);
        $this->assertDatabaseCount('clinic_records', 1);
        $this->assertDatabaseCount('clinic_vitals', 1);
        $this->assertDatabaseHas('clinic_case_services', ['clinic_appointment_id' => 1, 'status' => 'ordered']);
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)->assertJsonPath('cases.0.status', 'for_payment');
        $this->signIn('clinic_receptionist');
        $this->getJson('/api/admin/clinic-cases/1/confinement')->assertOk()->assertJsonPath('data.case_service.status', 'performed');
    }

    public function test_a_discharged_episode_does_not_exclude_a_current_consultation(): void
    {
        $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/discharge')->assertOk();
        ClinicAppointment::find(1)->update(['status' => 'in_consultation', 'appointment_date' => now()->toDateString()]);
        $this->getJson('/api/admin/clinic-cases')->assertOk()->assertJsonPath('confined_count', 0)
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', 1)
            ->assertJsonPath('cases.0.status', 'in_consultation');
        $this->getJson('/api/admin/clinic-confinements')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_catalogue_options_filter_category_active_state_and_supported_units_without_name_matching(): void
    {
        $eligible = $this->service(['name' => 'Renamed hospital stay', 'charge_behavior' => 'additional_charge']);
        $this->service(['is_active' => false]); $this->service(['charge_behavior' => 'additional_charge', 'billing_unit' => 'sample']);
        $this->service(['category_id' => ClinicServiceCategory::where('name', 'Laboratory')->value('id')]);
        $this->getJson('/api/admin/clinic-confinement-service-options')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $eligible->id);
        $this->signIn('admin'); $this->admit();
        $this->postJson('/api/admin/clinic-cases/1/confinement/observations', $this->monitoringPayload(['notes' => 'Admin monitoring']))->assertCreated();
        $this->postJson('/api/admin/clinic-appointments/1/cancel')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
