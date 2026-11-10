<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use App\Models\UnregisteredCustomer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerNameUpdateTest extends TestCase
{
    use \Tests\Support\CreatesPetBodyConditionSchema;
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'email', 'password_hash', 'role', 'staff_type', 'staff_subrole', 'username', 'phone'] as $field) {
                $table->string($field)->nullable();
            }
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('account_deleted_at')->nullable();
        });
        Schema::create('unregistered_customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read');
            $table->timestamp('created_at');
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('unregistered_customer_id')->nullable();
            $table->string('pet_name');
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->unsignedInteger('user_id');
            $table->string('status');
        });
        Schema::create('clinic_appointments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('pet_id');
            $table->string('status')->nullable();
        });
        $this->createPetBodyConditionSchema();
    }

    private function account(string $role = 'customer', ?string $subrole = null): User
    {
        return User::create([
            'first_name' => 'Jhon', 'last_name' => 'Delacruz', 'username' => 'saved-user',
            'email' => 'customer@example.com', 'phone' => '09171234567', 'password_hash' => Hash::make('unchanged'),
            'role' => $role, 'email_verified_at' => now(),
            'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : ($subrole ? 'clinic' : null),
            'staff_subrole' => $subrole,
        ]);
    }

    private function version(string $record): string
    {
        return $this->getJson($record)->assertOk()->assertJsonStructure(['customer' => ['nameVersion']])->json('customer.nameVersion');
    }

    public function test_customer_cannot_change_names_using_the_retired_endpoint(): void
    {
        $customer = $this->account();
        Sanctum::actingAs($customer);
        $this->patchJson('/api/customer/profile/name', ['first_name' => 'John', 'current_password' => 'unchanged'])->assertNotFound();
        $this->assertSame('Jhon', $customer->fresh()->first_name);
    }

    public function test_admin_and_both_receptionists_can_correct_only_the_selected_customer_type(): void
    {
        $customer = $this->account();
        $manual = UnregisteredCustomer::create(['first_name' => 'Maria', 'last_name' => 'Reyes']);
        $this->assertEquals($customer->getKey(), $manual->getKey());
        $online = '/api/admin/customers/'.$customer->getKey();
        $walkin = '/api/admin/customers/unregistered/'.$manual->getKey();
        DB::table('bookings')->insert(['user_id' => $customer->getKey(), 'status' => 'completed']);
        $petId = DB::table('pets')->insertGetId(['user_id' => $customer->getKey(), 'pet_name' => 'Mochi']);
        $manualPetId = DB::table('pets')->insertGetId(['unregistered_customer_id' => $manual->getKey(), 'pet_name' => 'Luna']);
        DB::table('clinic_appointments')->insert(['user_id' => $customer->getKey(), 'pet_id' => $petId]);

        foreach ([
            ['admin', null, 'Admin', 'John', 'María'],
            ['staff', 'clinic_receptionist', 'Clinic Receptionist', 'Juan', 'Mara'],
            ['staff', 'grooming_receptionist', 'Grooming Receptionist', 'José', 'Marie'],
        ] as [$role, $subrole, $label, $firstName, $manualName]) {
            $staff = $this->account($role, $subrole);
            Sanctum::actingAs($staff);
            $previousManualName = $manual->fresh()->first_name;
            $this->patchJson($online.'/name', ['name_version' => $this->version($online), 'first_name' => $firstName])
                ->assertOk()->assertJsonPath('customer.firstName', $firstName);
            $this->assertSame($previousManualName, $manual->fresh()->first_name);
            $this->patchJson($walkin.'/name', ['name_version' => $this->version($walkin), 'first_name' => $manualName])
                ->assertOk()->assertJsonPath('customer.firstName', $manualName);
            $this->assertSame($firstName, $customer->fresh()->first_name);
            foreach (['account', 'unregistered_customers'] as $subject) {
                $log = ActivityLog::where('action', 'customer_updated')->where('actor_id', $staff->getKey())
                    ->where('subject_type', $subject)->sole();
                $this->assertSame($label, $log->actor_role);
                $this->assertSame((string) $customer->getKey(), $log->subject_id);
                $this->assertSame($subject === 'account' ? $firstName : $manualName, $log->changes['first_name']['after']);
                $this->assertNotNull($log->occurred_at);
            }
        }
        $this->assertDatabaseCount('customer_notifications', 3);
        $this->assertDatabaseHas('bookings', ['user_id' => $customer->getKey(), 'status' => 'completed']);
        $this->assertDatabaseHas('pets', ['pet_id' => $petId, 'user_id' => $customer->getKey()]);
        $this->assertDatabaseHas('pets', ['pet_id' => $manualPetId, 'unregistered_customer_id' => $manual->getKey()]);
        $this->assertDatabaseHas('clinic_appointments', ['user_id' => $customer->getKey(), 'pet_id' => $petId]);
        $this->assertSame('saved-user', $customer->fresh()->username);
        $this->assertSame('09171234567', $customer->fresh()->phone);
    }

    public function test_other_roles_cannot_correct_names_or_receive_an_edit_version(): void
    {
        $customer = $this->account();
        $manual = UnregisteredCustomer::create(['first_name' => 'Maria', 'last_name' => 'Reyes']);
        $records = ['/api/admin/customers/'.$customer->getKey(), '/api/admin/customers/unregistered/'.$manual->getKey()];
        foreach ($records as $record) {
            $this->patchJson($record.'/name', ['first_name' => 'John'])->assertUnauthorized();
        }
        foreach ([['staff', 'veterinarian'], ['staff', null], ['customer', null]] as [$role, $subrole]) {
            Sanctum::actingAs($this->account($role, $subrole));
            foreach ($records as $record) {
                $this->patchJson($record.'/name', ['first_name' => 'John'])->assertForbidden();
                if ($role === 'staff') $this->getJson($record)->assertOk()->assertJsonMissingPath('customer.nameVersion');
            }
        }
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->assertSame('Jhon', $customer->fresh()->first_name);
    }

    public function test_changes_are_validated_concurrent_edits_conflict_and_notifications_describe_saved_changes(): void
    {
        $customer = $this->account();
        $other = $this->account();
        Sanctum::actingAs($this->account('admin'));
        $record = '/api/admin/customers/'.$customer->getKey();
        $version = $this->version($record);
        foreach (['first_name' => 'John2', 'last_name' => '', 'email' => 'other@example.com'] as $field => $value) {
            $this->patchJson($record.'/name', ['name_version' => $version, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson($record.'/name', ['name_version' => $version, 'first_name' => ' jhon '])->assertOk();
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->assertSame(0, ActivityLog::where('action', 'customer_updated')->count());

        $first = $this->patchJson($record.'/name', ['name_version' => $version, 'first_name' => 'john'])->assertOk();
        $this->patchJson($record.'/name', ['name_version' => $version, 'last_name' => 'Santos'])->assertConflict()
            ->assertJsonPath('customer.firstName', 'John');
        $this->patchJson($record.'/name', ['name_version' => $first->json('customer.nameVersion'), 'last_name' => 'Dela Cruz'])->assertOk();
        $this->patchJson($record.'/name', ['name_version' => $this->version($record), 'first_name' => 'Anne-Marie', 'last_name' => 'O’Peña'])->assertOk();
        $this->assertSame([
            'Bethlehem updated your first name from Jhon to John.',
            'Bethlehem updated your last name from Delacruz to Dela Cruz.',
            'Bethlehem updated your name: First name John → Anne-Marie; Last name Dela Cruz → O’Peña.',
        ], CustomerNotification::orderBy('id')->pluck('message')->all());
        $this->assertSame(['before' => 'Jhon', 'after' => 'John'],
            ActivityLog::where('action', 'customer_updated')->oldest('id')->firstOrFail()->changes['first_name']);
        $this->assertSame(3, ActivityLog::where('action', 'customer_updated')->count());
        $this->assertSame($customer->password_hash, $customer->fresh()->password_hash);

        Sanctum::actingAs($other);
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonPath('unread_count', 0);
        Sanctum::actingAs($customer);
        $notifications = $this->getJson('/api/customer/notifications')->assertOk()->assertJsonPath('unread_count', 3)
            ->assertJsonPath('notifications.0.title', 'Account name updated')
            ->assertJsonPath('notifications.0.destination', '/account/settings')
            ->assertJsonPath('notifications.0.type', 'account_name_updated');
        $this->patchJson('/api/customer/notifications/'.$notifications->json('notifications.0.id').'/read')->assertOk();
        $this->getJson('/api/customer/notifications')->assertOk()->assertJsonPath('unread_count', 2);
    }

    public function test_a_notification_write_failure_rolls_back_the_name_and_activity(): void
    {
        $customer = $this->account();
        Sanctum::actingAs($this->account('admin'));
        $record = '/api/admin/customers/'.$customer->getKey();
        $version = $this->version($record);
        DB::unprepared("CREATE TRIGGER reject_notification BEFORE INSERT ON customer_notifications BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->patchJson($record.'/name', ['name_version' => $version, 'first_name' => 'John'])->assertServerError();
        $this->assertSame('Jhon', $customer->fresh()->first_name);
        $this->assertSame(0, ActivityLog::where('action', 'customer_updated')->count());
        $this->assertDatabaseCount('customer_notifications', 0);
    }

    public function test_archived_records_cannot_be_edited(): void
    {
        $customer = $this->account();
        $manual = UnregisteredCustomer::create(['first_name' => 'Maria', 'last_name' => 'Reyes']);
        Sanctum::actingAs($this->account('admin'));
        foreach ([$customer, $manual] as $record) {
            $url = '/api/admin/customers/'.($record instanceof User ? '' : 'unregistered/').$record->getKey();
            $version = $this->version($url);
            DB::table($record->getTable())->where($record->getKeyName(), $record->getKey())->update(['is_archived' => true]);
            $this->getJson($url)->assertOk()->assertJsonMissingPath('customer.nameVersion');
            $this->patchJson($url.'/name', ['name_version' => $version, 'first_name' => 'New'])->assertConflict();
        }
        $this->assertDatabaseCount('customer_notifications', 0);
    }
}
