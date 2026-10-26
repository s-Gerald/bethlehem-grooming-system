<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require base_path('database/migrations/2026_10_25_000004_create_activity_logs_table.php'))->up();
        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            foreach (['first_name', 'last_name', 'username', 'role', 'staff_type', 'staff_subrole', 'password_hash', 'email'] as $field) $table->string($field)->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('is_archived')->default(false);
        });
        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id'); $table->string('pet_name');
        });
    }

    private function actor(string $role = 'admin', ?string $subrole = null): User
    {
        $user = User::create(['first_name' => 'Angelyn', 'last_name' => 'Reyes', 'username' => 'clinic-admin', 'role' => $role,
            'staff_type' => $subrole === 'grooming_receptionist' ? 'grooming' : ($subrole ? 'clinic' : null),
            'staff_subrole' => $subrole, 'is_active' => true]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function event(string $area = 'grooming', bool $operational = true): ActivityLog
    {
        return app(ActivityRecorder::class)->record('grooming_started', $area, 'visit', (string) random_int(1, 999999),
            'GR-123', [], $operational, auth()->user());
    }

    public function test_log_endpoints_and_page_are_admin_only(): void
    {
        $this->actor(); $event = $this->event();
        $this->getJson('/api/admin/activity-log')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/activity-log/'.$event->id)->assertOk();
        $page = $this->get('/api/admin/activity-log/page')->assertOk();
        $this->assertDoesNotMatchRegularExpression('/\b(appointments?|bookings?)\b/i', strip_tags($page->getContent()));
        $dom = new \DOMDocument;
        $dom->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame(0, (new \DOMXPath($dom))->query('//dt[normalize-space()="Role"]')->length);
        foreach (ActivityRecorder::LABELS as $label) $this->assertDoesNotMatchRegularExpression('/\b(appointments?|bookings?)\b/i', $label);
        foreach (['grooming_receptionist', 'clinic_receptionist', 'veterinarian'] as $role) {
            $this->actor('staff', $role);
            foreach (['', '/options', '/'.$event->id] as $suffix) $this->getJson('/api/admin/activity-log'.$suffix)->assertForbidden();
            $this->get('/api/admin/activity-log/page')->assertForbidden();
            $this->get('/pages/admin/activity-log.html')->assertRedirect('api/admin/activity-log/page');
        }
    }

    public function test_feed_defaults_and_private_projection_are_enforced_on_the_server(): void
    {
        $this->actor(); $this->event(); $this->event('clinic'); $this->event('system', false);
        app(ActivityRecorder::class)->record('clinical_record_updated', 'clinic', 'clinic_record', '99', 'Restricted record', [], true);
        foreach (['admin' => 'all', 'grooming_receptionist' => 'grooming', 'clinic_receptionist' => 'clinic', 'veterinarian' => 'clinic'] as $role => $area) {
            $this->actor($role === 'admin' ? 'admin' : 'staff', $role === 'admin' ? null : $role);
            $response = $this->getJson('/api/admin/dashboard/activity')->assertOk()->assertJsonPath('area', $area);
            $this->assertCount($area === 'all' ? 2 : 1, $response->json('recentActivity'));
            foreach ($response->json('recentActivity') as $entry) {
                $this->assertSame(['id', 'type', 'title', 'subtitle', 'createdAt', 'area'], array_keys($entry));
            }
        }
        $this->getJson('/api/admin/dashboard/activity?area=grooming')->assertForbidden();
        $this->getJson('/api/admin/dashboard/activity?area=all')->assertForbidden();
    }

    public function test_actor_snapshots_system_identity_deduplication_and_rollback(): void
    {
        $actor = $this->actor('staff', 'grooming_receptionist');
        $this->assertSame('system', ActivityLog::where('action', 'staff_created')->sole()->area);
        $recorder = app(ActivityRecorder::class);
        $event = $recorder->record('checked_in', 'grooming', 'visit', '1', 'GR-1', [], true, $actor, 'check-in-1');
        $recorder->record('checked_in', 'grooming', 'visit', '1', 'GR-1', [], true, $actor, 'check-in-1');
        $actor->update(['first_name' => 'Renamed']);
        $this->assertSame('Angelyn · Grooming Receptionist', $event->fresh()->actor_display);
        $this->assertSame('Grooming Receptionist', $event->fresh()->actor_role);
        $system = $recorder->record('payment_recorded', 'grooming', 'payment', '1', 'GR-1', [], false, null);
        $this->assertSame('System', $system->actor_display);
        $before = ActivityLog::count();
        try {
            DB::transaction(function () { $this->event(); throw new \RuntimeException('rollback'); });
        } catch (\RuntimeException) {}
        $this->assertSame($before, ActivityLog::count());
        $this->assertSame(1, ActivityLog::where('event_key', hash('sha256', 'check-in-1'))->count());
    }

    public function test_filters_pagination_dates_and_detail_metadata_are_safe(): void
    {
        $actor = $this->actor();
        for ($i = 0; $i < 28; $i++) $this->event($i % 2 ? 'clinic' : 'grooming');
        DB::table('activity_logs')->where('id', ActivityLog::first()->id)->update(['occurred_at' => now()->subDays(45)]);
        $this->getJson('/api/admin/activity-log?area=clinic&staff='.$actor->user_id.'&type=grooming_started&search=GR-123&per_page=10')
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 14)->assertJsonPath('last_page', 2);
        $this->getJson('/api/admin/activity-log?search=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/activity-log?area=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/activity-log?from=2026-10-25&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/admin/activity-log/options')->assertOk();
        $event = app(ActivityRecorder::class)->record('inventory_stock_adjusted', 'system', 'inventory', '1', 'Shampoo',
            ['quantity_on_hand' => ['before' => 20, 'after' => 15], 'password' => ['before' => 'secret', 'after' => 'secret']], false, $actor);
        $this->getJson('/api/admin/activity-log/'.$event->id)->assertOk()->assertJsonMissing(['secret'])
            ->assertJsonPath('changes.quantity_on_hand.before', 20)->assertJsonMissingPath('changes.password');
        $this->assertSame('clinic-admin', $event->actor_display);
    }

    public function test_real_transitions_no_op_saves_and_stock_before_after(): void
    {
        $this->actor();
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id'); $t->string('booking_reference'); $t->string('booking_type'); $t->string('status');
            $t->date('booking_date')->nullable(); $t->timestamp('dropped_off_at')->nullable();
        });
        Schema::create('booking_pets', function (Blueprint $t) {
            $t->increments('booking_pet_id'); $t->integer('booking_id'); $t->integer('pet_id'); $t->string('grooming_state');
        });
        Schema::create('clinic_appointments', function (Blueprint $t) {
            $t->id(); $t->string('appointment_reference'); $t->string('appointment_type'); $t->string('status');
            $t->boolean('paid')->default(false); $t->dateTime('consultation_finished_at')->nullable(); $t->timestamps();
        });
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->increments('item_id'); $t->string('item_name'); $t->decimal('quantity_on_hand'); $t->timestamps();
        });
        $visit = \App\Models\Booking::create(['booking_reference' => 'GR-42', 'booking_type' => 'online', 'status' => 'waiting_to_arrive']);
        $this->assertSame(1, ActivityLog::where('action', 'pre_registration_submitted')->count());
        $this->assertSame(0, ActivityLog::where('action', 'checked_in')->count());
        $visit->update(['status' => 'checked_in', 'dropped_off_at' => now()]);
        $visit->save(); $visit->refresh()->update(['status' => 'checked_in']);
        $this->assertSame(1, ActivityLog::where('action', 'checked_in')->count());
        \App\Models\Booking::create(['booking_reference' => 'WI-1', 'booking_type' => 'walk_in', 'status' => 'checked_in']);
        $this->assertSame(2, ActivityLog::where('action', 'checked_in')->count());
        $this->assertSame(0, ActivityLog::where('action', 'walk_in_registered')->count(), 'Combined walk-in intake records one check-in.');
        $pet = \App\Models\BookingPet::create(['booking_id' => $visit->getKey(), 'pet_id' => 1, 'grooming_state' => 'not_started']);
        $pet->update(['grooming_state' => 'in_progress']); $pet->save(); $pet->update(['grooming_state' => 'finished']);
        $this->assertSame(1, ActivityLog::where('action', 'grooming_started')->count());
        $this->assertSame(1, ActivityLog::where('action', 'grooming_completed')->count());
        $clinic = \App\Models\ClinicAppointment::create(['appointment_reference' => 'CL-1', 'appointment_type' => 'online', 'status' => 'waiting_to_arrive']);
        $clinic->update(['status' => 'checked_in']); $clinic->update(['status' => 'in_consultation']);
        $clinic->update(['status' => 'completed', 'consultation_finished_at' => now()]);
        $this->assertSame(1, ActivityLog::where('action', 'consultation_started')->count());
        $this->assertSame(1, ActivityLog::where('action', 'consultation_completed')->count());
        $item = \App\Models\InventoryItem::create(['item_name' => 'Shampoo', 'quantity_on_hand' => 20]);
        $item->decrement('quantity_on_hand', 5); $item->save();
        $stock = ActivityLog::where('action', 'inventory_stock_adjusted')->sole();
        $this->assertSame('shared', $stock->area);
        $this->assertEquals(['before' => 20, 'after' => 15], $stock->changes['quantity_on_hand']);
        $before = ActivityLog::count();
        try {
            DB::transaction(function () use ($visit) { $visit->update(['status' => 'cancelled']); throw new \RuntimeException('fail'); });
        } catch (\RuntimeException) {}
        $this->assertSame($before, ActivityLog::count());
        $this->assertSame('checked_in', $visit->fresh()->status);
    }

    public function test_payment_source_actors_retries_and_failed_settlements(): void
    {
        $actor = $this->actor('staff', 'clinic_receptionist');
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->integer('booking_id')->nullable(); $t->integer('processed_by')->nullable();
            $t->integer('gateway_payment_id')->nullable(); $t->string('payment_status'); $t->decimal('total_amount');
            $t->timestamp('paid_at')->nullable(); $t->timestamp('created_at')->nullable();
        });
        $cash = \App\Models\Payment::create(['payment_status' => 'paid', 'total_amount' => 500, 'processed_by' => $actor->getKey()]);
        $cash->update(['payment_status' => 'paid']); $cash->update(['paid_at' => now()]);
        $this->assertSame(1, ActivityLog::where('action', 'payment_recorded')->count());
        $this->assertSame($actor->getKey(), ActivityLog::where('action', 'payment_recorded')->sole()->actor_id);
        \App\Models\Payment::create(['payment_status' => 'paid', 'total_amount' => 600, 'processed_by' => $actor->getKey(), 'gateway_payment_id' => 9]);
        $event = ActivityLog::where('action', 'payment_recorded')->latest('id')->first();
        $this->assertSame('System', $event->actor_display); $this->assertNull($event->actor_id);
        try {
            DB::transaction(function () use ($actor) {
                \App\Models\Payment::create(['payment_status' => 'paid', 'total_amount' => 700, 'processed_by' => $actor->getKey()]);
                throw new \RuntimeException('failed settlement');
            });
        } catch (\RuntimeException) {}
        $this->assertSame(2, ActivityLog::where('action', 'payment_recorded')->count());
        $this->getJson('/api/admin/dashboard/activity?area=all')->assertOk()->assertJsonCount(0, 'recentActivity');
    }

    public function test_log_is_append_only_and_guest_cannot_load_the_page_or_api(): void
    {
        $event = $this->event();
        foreach (['/api/admin/activity-log', '/api/admin/activity-log/page', '/api/admin/activity-log/options', '/api/admin/activity-log/'.$event->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $event->update(['context' => 'changed']) : $event->delete();
                $this->fail('Activity records must reject '.$operation);
            } catch (\LogicException) {}
        }
        $this->assertSame('GR-123', $event->fresh()->context);
        $this->get('/resources/views/admin/activity-log.html')->assertNotFound();
    }

    public function test_clinical_changes_record_markers_without_medical_notes(): void
    {
        $this->actor('staff', 'veterinarian');
        Schema::create('clinic_records', function (Blueprint $t) {
            $t->id(); $t->integer('clinic_appointment_id'); $t->text('vet_notes')->nullable(); $t->timestamps();
        });
        $record = \App\Models\ClinicRecord::create(['clinic_appointment_id' => 1, 'vet_notes' => 'Confidential findings']);
        $record->update(['vet_notes' => 'Confidential updated findings']); $record->save();
        $events = ActivityLog::where('action', 'clinical_record_updated')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame([], $event->changes);
            $this->assertStringNotContainsString('Confidential', $event->toJson());
            $this->assertSame('Angelyn · Veterinarian', $event->actor_display);
        }
        $this->getJson('/api/admin/dashboard/activity')->assertOk()->assertJsonCount(0, 'recentActivity');
    }

    public function test_automatic_expiry_is_batched_and_attributed_to_system_once(): void
    {
        $this->actor();
        Schema::create('bookings', function (Blueprint $t) {
            $t->increments('booking_id'); $t->string('booking_reference'); $t->string('status');
            $t->date('booking_date'); $t->dateTime('dropped_off_at')->nullable(); $t->integer('user_id')->nullable();
        });
        for ($i = 1; $i <= 12; $i++) DB::table('bookings')->insert(['booking_reference' => 'EX-'.$i,
            'status' => 'waiting_to_arrive', 'booking_date' => now()->subDay()->toDateString()]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame(12, app(\App\Services\PreRegistrationExpiry::class)->expire());
        $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'insert into "activity_logs"'));
        $this->assertCount(1, $inserts, 'Expiry should insert its bounded batch in one query.');
        DB::disableQueryLog();
        $this->assertSame(0, app(\App\Services\PreRegistrationExpiry::class)->expire());
        $events = ActivityLog::where('action', 'pre_registration_expired')->get();
        $this->assertCount(12, $events);
        $this->assertSame(['System'], $events->pluck('actor_display')->unique()->values()->all());
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === null));
    }

    public function test_historical_transition_titles_filters_and_groups_use_the_same_labels(): void
    {
        $actor = $this->actor();
        $cases = [
            ['queue_status_changed', 'status', 'checked_in', 'cancelled', 'Queue entry cancelled', 'queue_entry_cancelled'],
            ['queue_status_changed', 'status', 'waiting_to_arrive', 'cancelled', 'Online Registration cancelled', 'pre_registration_cancelled'],
            ['queue_status_changed', 'status', 'waiting_to_arrive', 'checked_in', 'Checked in', 'checked_in'],
            ['queue_status_changed', 'status', 'checked_in', 'waiting_to_arrive', 'Check-in reversed', 'check_in_reversed'],
            ['grooming_corrected', 'grooming_state', 'not_started', 'cancelled', 'Grooming cancelled', 'grooming_cancelled'],
            ['grooming_corrected', 'grooming_state', 'in_progress', 'not_started', 'Grooming start reversed', 'grooming_start_reversed'],
            ['queue_status_changed', 'status', null, 'cancelled', 'Queue status changed', 'queue_status_changed'],
            ['grooming_corrected', 'grooming_state', 'finished', 'not_started', 'Grooming status corrected', 'grooming_corrected'],
        ];
        foreach ($cases as [$action, $field, $before, $after, $title, $filter]) {
            $event = app(ActivityRecorder::class)->record($action, 'grooming', 'grooming_visit', '1', 'GR-1',
                [$field => compact('before', 'after')], true, $actor);
            $original = $event->fresh()->getRawOriginal();
            $this->getJson('/api/admin/activity-log/'.$event->id)->assertOk()->assertJsonPath('title', $title);
            $rows = $this->getJson('/api/admin/activity-log?type='.$filter)->assertOk()->json('data');
            $this->assertContains($event->id, array_column($rows, 'id'));
            $this->assertSame($original, $event->fresh()->getRawOriginal(), 'Presentation must not rewrite history.');
        }
        $this->getJson('/api/admin/activity-log?search=Queue%20entry%20cancelled')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Queue entry cancelled');
        $groups = $this->getJson('/api/admin/activity-log/options')->assertOk()->json('type_groups');
        $options = collect($groups)->flatMap(fn ($group) => $group['options'])->pluck('label', 'value')->all();
        foreach (ActivityRecorder::LABELS as $key => $label) $this->assertSame($label, $options[$key]);
        $this->assertSame('Queue entry cancelled', $options['queue_entry_cancelled']);
        $this->assertSame(count($options), collect($groups)->sum(fn ($group) => count($group['options'])));
        $feed = $this->getJson('/api/admin/dashboard/activity')->assertOk()->json('recentActivity');
        $this->assertContains('Grooming cancelled', array_column($feed, 'title'));
    }

    public function test_drawer_uses_historical_full_names_without_exposing_account_identifiers(): void
    {
        $staff = $this->actor('staff', 'grooming_receptionist');
        $event = $this->event();
        $staff->update(['first_name' => 'Renamed']);
        $this->actor();
        $this->getJson('/api/admin/activity-log/'.$event->id)->assertOk()->assertJsonPath('account_name', 'Angelyn Reyes')
            ->assertJsonPath('actor_display', 'Angelyn · Grooming Receptionist')
            ->assertJsonMissingPath('actor_identity')->assertJsonMissingPath('actor_id')->assertJsonMissingPath('actor_role');
        $admin = $this->event();
        $this->getJson('/api/admin/activity-log/'.$admin->id)->assertOk()->assertJsonPath('actor_display', 'clinic-admin')
            ->assertJsonPath('account_name', 'Angelyn Reyes');
        DB::table('activity_logs')->where('id', $admin->id)->update(['actor_identity' => null]);
        $this->getJson('/api/admin/activity-log/'.$admin->id)->assertOk()->assertJsonPath('account_name', null);
    }

    public function test_shared_legacy_areas_and_explicit_departments_filter_consistently(): void
    {
        $actor = $this->actor();
        $recorder = app(ActivityRecorder::class);
        foreach (['system', 'shared', 'clinic', 'grooming'] as $area) {
            $recorder->record('inventory_stock_adjusted', $area, 'inventory', '1', 'Inventory', [], false, $actor);
        }
        $recorder->record('staff_deactivated', 'system', 'account', '1', 'Staff account', [], false, $actor);
        $this->getJson('/api/admin/activity-log?area=shared')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.area', 'shared');
        $this->getJson('/api/admin/activity-log?area=system')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.area', 'system');
        $this->getJson('/api/admin/activity-log?area=clinic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.area', 'clinic');
        $this->getJson('/api/admin/activity-log?area=grooming')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.area', 'grooming');
        $feedEvent = $this->event('clinic');
        $this->getJson('/api/admin/dashboard/activity')->assertOk()->assertJsonPath('recentActivity.0.area', 'clinic');
        $this->assertSame('system', ActivityLog::where('action', 'inventory_stock_adjusted')->oldest('id')->first()->area);
    }
}
