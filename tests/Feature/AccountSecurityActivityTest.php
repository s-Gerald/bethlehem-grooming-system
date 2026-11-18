<?php

namespace Tests\Feature;

use App\Models\AccountSecurityIncident;
use App\Models\ActivityLog;
use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountSecurityActivityTest extends TestCase
{
    use \Tests\Support\CreatesAuthenticationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthenticationSchema();
        Schema::table('users', function (Blueprint $table): void {
            $table->string('admin_type', 20)->nullable();
            $table->string('staff_type')->nullable();
            $table->string('staff_subrole')->nullable();
        });
        foreach (['2026_08_28_000001_create_privileged_credential_changes_table',
            '2026_08_28_000002_replace_credential_change_links_with_codes',
            '2026_10_25_000004_create_activity_logs_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        Notification::fake();
        $this->travelTo(now()->utc()->setTime(6, 0));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('privileged_credential_changes');
        $this->dropAuthenticationSchema();
        parent::tearDown();
    }

    private function incident(User $user, int $days = 0, array $attributes = []): AccountSecurityIncident
    {
        return AccountSecurityIncident::create($attributes + ['user_id' => $user->getKey(),
            'incident_day' => now()->subDays($days)->toDateString(), 'failure_count' => 3,
            'first_occurred_at' => now()->subDays($days), 'last_occurred_at' => now()->subDays($days)]);
    }

    public function test_manager_lifecycle_and_operational_activity_use_account_and_manager_labels(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'admin_type' => 'manager', 'first_name' => 'Mara', 'last_name' => 'Santos']);
        $created = ActivityLog::where('action', 'staff_created')->where('subject_id', (string) $manager->getKey())->first();
        $this->assertNotNull($created);
        $this->assertSame('Account created', \App\Support\ActivityPresentation::title($created));
        app(\App\Services\ActivityRecorder::class)->record('settings_updated', 'system', 'settings', '1', 'Settings', [], false, $manager);
        $event = ActivityLog::where('action', 'settings_updated')->sole();
        $this->assertSame('Manager', $event->actor_role);
        $this->assertSame('Mara · Manager', $event->actor_display);
    }

    public function test_forward_migration_preserves_legacy_history_and_allows_same_day_terminal_records(): void
    {
        Schema::drop('account_security_incidents');
        (require database_path('migrations/2026_10_30_000002_create_account_security_incidents.php'))->up();
        $user = User::factory()->create(['role' => 'admin']);
        // Seed the old schema directly: filling a model here would cache the old
        // guardable columns across tests in this long-lived PHPUnit process.
        $id = DB::table('account_security_incidents')->insertGetId(['user_id' => $user->getKey(),
            'incident_day' => now()->toDateString(), 'first_occurred_at' => now(), 'last_occurred_at' => now(),
            'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('account_security_incidents')->where('id', $id)->first();
        $migration = require database_path('migrations/2026_11_18_000001_preserve_security_incident_history.php');
        $migration->up();
        $migration->up(); // Safe after a partially applied MariaDB deployment.
        $legacy = AccountSecurityIncident::findOrFail($id);
        foreach ($before as $key => $value) $this->assertSame($value, $legacy->fresh()->getRawOriginal($key));
        $this->assertSame('Reviewed', $legacy->fresh()->payload()['status']);
        $this->assertNull($legacy->fresh()->recognized_at);
        $this->assertNull($legacy->fresh()->resolved_at);
        $this->incident($user, 0, ['recognized_at' => now()]);
        $this->incident($user, 0, ['resolved_at' => now()]);
        $this->incident($user);
        $migration->down();
        $this->assertDatabaseCount('account_security_incidents', 4);
        $this->assertTrue(Schema::hasIndex('account_security_incidents', 'security_incidents_owner_history'));
        $this->assertTrue(Schema::hasIndex('account_security_incidents', 'security_incidents_owner_active'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_pagination_position_survives_new_failures_and_terminal_state_changes(string $role): void
    {
        $user = User::factory()->create(['role' => $role === 'manager' ? 'admin' : $role, 'admin_type' => $role === 'manager' ? 'manager' : null]);
        $records = collect(range(0, 6))->map(fn ($days) => $this->incident($user, $days));
        Sanctum::actingAs($user);
        $first = $this->getJson('/api/settings/security/activity')->assertOk();
        $records[5]->update(['last_occurred_at' => now()]);
        $this->postJson('/api/settings/security/activity/'.$records[4]->id.'/recognize')->assertOk();
        $second = $this->getJson('/api/settings/security/activity?cursor='.urlencode($first->json('next_cursor')))->assertOk();
        $this->assertSame([$records[5]->id, $records[6]->id], array_column($second->json('incidents'), 'id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_recognition_is_owned_idempotent_and_does_not_change_credentials_or_sessions(string $role): void
    {
        $user = User::factory()->create(['role' => $role === 'manager' ? 'admin' : $role, 'admin_type' => $role === 'manager' ? 'manager' : null]);
        $selected = $this->incident($user);
        $other = $this->incident($user, 1);
        $foreign = $this->incident(User::factory()->create(['role' => 'staff']));
        $token = $user->createToken('active')->accessToken;
        $hash = $user->password_hash;
        Sanctum::actingAs($user);
        $url = '/api/settings/security/activity/'.$selected->id.'/recognize';
        $this->postJson('/api/settings/security/activity/'.$foreign->id.'/recognize')->assertNotFound();
        $this->postJson($url)->assertOk()->assertJsonPath('incident.status', 'Recognized')
            ->assertJsonPath('incident.recognized_at', now()->toIso8601String());
        $this->travel(2)->minutes();
        $this->postJson($url)->assertOk()->assertJsonPath('incident.status', 'Recognized');
        $this->assertSame(now()->subMinutes(2)->timestamp, $selected->fresh()->recognized_at->timestamp);
        $this->assertNull($selected->fresh()->resolved_at);
        $this->assertNull($other->fresh()->recognized_at);
        $this->assertNull($other->fresh()->resolved_at);
        $this->assertSame($hash, $user->fresh()->password_hash);
        $this->assertNotNull($token->fresh());
        $this->assertNull($token->fresh()->replaced_at);
        $this->assertSame(1, ActivityLog::where('action', 'security_incident_recognized')->count());
        if ($role === 'manager') $this->assertSame('Manager', ActivityLog::where('action', 'security_incident_recognized')->value('actor_role'));
    }

    public function test_terminal_history_is_truthful_and_cannot_be_recognized_again(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        foreach (['reviewed_at' => 'Reviewed', 'recognized_at' => 'Recognized', 'resolved_at' => 'Resolved'] as $field => $status) {
            $record = $this->incident($user, AccountSecurityIncident::count(), [$field => now()]);
            Sanctum::actingAs($user);
            $this->postJson('/api/settings/security/activity/'.$record->id.'/recognize')->assertOk()
                ->assertJsonPath('incident.status', $status);
            if ($field !== 'recognized_at') $this->assertNull($record->fresh()->recognized_at);
        }
        $this->assertSame(0, ActivityLog::where('action', 'security_incident_recognized')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_cursor_pages_are_bounded_chronological_and_focus_does_not_move_old_history_first(string $role): void
    {
        $user = User::factory()->create(['role' => $role === 'manager' ? 'admin' : $role, 'admin_type' => $role === 'manager' ? 'manager' : null]);
        $records = collect(range(0, 11))->map(fn ($days) => $this->incident($user, $days));
        Sanctum::actingAs($user);
        $first = $this->getJson('/api/settings/security/activity?incident='.$records[10]->id)->assertOk()
            ->assertJsonCount(5, 'incidents')->assertJsonPath('incidents.0.id', $records[0]->id)
            ->assertJsonPath('focused_incident.id', $records[10]->id)->assertJsonPath('has_more', true);
        $ids = array_column($first->json('incidents'), 'id');
        $cursor = $first->json('next_cursor');
        do {
            $next = $this->getJson('/api/settings/security/activity?cursor='.urlencode($cursor))->assertOk();
            $this->assertLessThanOrEqual(5, count($next->json('incidents')));
            array_push($ids, ...array_column($next->json('incidents'), 'id'));
            $cursor = $next->json('next_cursor');
        } while ($next->json('has_more'));
        $this->assertSame($records->pluck('id')->all(), $ids);
        $this->assertNull($cursor);
        $this->getJson('/api/settings/security/activity?cursor=invalid')->assertUnprocessable();
        $foreign = $this->incident(User::factory()->create());
        $this->getJson('/api/settings/security/activity?incident='.$foreign->id)->assertOk()->assertJsonPath('focused_incident', null);
    }

    public function test_tied_occurrences_page_by_id_and_payload_contains_no_private_metadata(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        foreach (range(1, 7) as $i) $this->incident($user);
        Sanctum::actingAs($user);
        $first = $this->getJson('/api/settings/security/activity')->assertOk()->assertJsonCount(5, 'incidents');
        $this->assertSame([7, 6, 5, 4, 3], array_column($first->json('incidents'), 'id'));
        $this->getJson('/api/settings/security/activity?cursor='.urlencode($first->json('next_cursor')))
            ->assertOk()->assertJsonCount(2, 'incidents')->assertJsonPath('incidents.0.id', 2)->assertJsonPath('has_more', false);
        $this->assertSame(['id', 'title', 'type', 'severity', 'first_occurred_at', 'last_occurred_at',
            'failure_count', 'status', 'recognized_at', 'resolved_at', 'reviewed_at'], array_keys($first->json('incidents.0')));
    }

    public function test_new_same_day_evidence_preserves_each_terminal_row_and_aggregates_only_active_activity(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $service = app(AccountSecurityService::class);
        foreach (['recognized_at', 'resolved_at', 'reviewed_at'] as $field) {
            $terminal = $this->incident($user, 0, [$field => now()]);
            $before = $terminal->fresh()->getAttributes();
            $this->travel(1)->hours();
            RateLimiter::clear('login-code-account:'.$user->getKey());
            foreach (range(1, 3) as $i) RateLimiter::hit('login-code-account:'.$user->getKey(), 3600);
            $service->failed($user);
            $active = AccountSecurityIncident::latest('id')->first();
            $this->assertNotSame($terminal->id, $active->id);
            $this->assertSame('Needs review', $active->payload()['status']);
            $service->failed($user);
            $this->assertSame(4, $active->fresh()->failure_count);
            $this->assertSame($before, $terminal->fresh()->getAttributes());
            $active->update([$field => now()]);
        }
        $this->assertDatabaseCount('account_security_incidents', 6);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_only_successful_password_confirmation_resolves_prior_unresolved_incidents(string $role): void
    {
        $user = User::factory()->create(['role' => $role === 'manager' ? 'admin' : $role, 'admin_type' => $role === 'manager' ? 'manager' : null, 'username' => 'SecurityOwner',
            'staff_type' => $role === 'staff' ? 'clinic' : null, 'staff_subrole' => $role === 'staff' ? 'veterinarian' : null]);
        $old = $this->incident($user, 2);
        $recent = $this->incident($user, 1);
        $recognized = $this->incident($user, 3, ['recognized_at' => now()->subDay()]);
        $reviewed = $this->incident($user, 4, ['reviewed_at' => now()->subDay()]);
        $future = $this->incident($user, 0, ['last_occurred_at' => now()->addHour(), 'first_occurred_at' => now()->addHour()]);
        $foreign = $this->incident(User::factory()->create());
        Sanctum::actingAs($user);
        $requestUrl = $role !== 'admin' ? '/api/settings/security/password-change' : '/api/admin/security/account/credential-change';
        $confirmBase = $role !== 'admin' ? '/api/settings/security/credential-changes/' : '/api/admin/security/credential-changes/';
        $this->postJson($requestUrl, ['current_password' => 'password', 'password' => 'violet orchard meadow',
            'password_confirmation' => 'violet orchard meadow'])->assertAccepted();
        $change = PrivilegedCredentialChange::sole();
        $code = Notification::sent($user, \App\Notifications\ConfirmPrivilegedCredentialChangeCodeNotification::class)->last()->code;
        $this->assertNull($old->fresh()->resolved_at);
        $this->postJson($confirmBase.$change->id.'/confirm', ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
        $this->assertNull($recent->fresh()->resolved_at);
        $this->postJson($confirmBase.$change->id.'/confirm', ['code' => $code])->assertOk()
            ->assertJsonPath('security_resolved_at', now()->toIso8601String())
            ->assertJsonPath('requires_reauthentication', $role === 'admin');
        $this->assertTrue(Hash::check('violet orchard meadow', $user->fresh()->password_hash));
        foreach ([$old, $recent] as $record) {
            $this->assertSame('Resolved', $record->fresh()->payload()['status']);
            $this->assertSame($change->fresh()->confirmed_at->timestamp, $record->fresh()->resolved_at->timestamp);
        }
        foreach ([$recognized, $reviewed, $future, $foreign] as $record) $this->assertNull($record->fresh()->resolved_at);
        $this->assertSame('Recognized', $recognized->fresh()->payload()['status']);
        $this->assertSame(1, ActivityLog::where('action', 'security_incidents_resolved')->count());
        $this->postJson($confirmBase.$change->id.'/confirm', ['code' => $code])->assertUnprocessable();
        $this->assertSame(1, ActivityLog::where('action', 'security_incidents_resolved')->count());
    }

    public static function roles(): array { return [['admin'], ['staff'], ['manager']]; }

    public function test_username_only_confirmation_leaves_incidents_unresolved(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'username' => 'SecurityOwner']);
        $incident = $this->incident($user);
        Sanctum::actingAs($user);
        $this->postJson('/api/admin/security/account/credential-change', ['current_password' => 'password', 'username' => 'NewSecurityOwner'])->assertAccepted();
        $change = PrivilegedCredentialChange::sole();
        $code = Notification::sent($user, \App\Notifications\ConfirmPrivilegedCredentialChangeCodeNotification::class)->last()->code;
        $this->postJson('/api/admin/security/credential-changes/'.$change->id.'/confirm', ['code' => $code])->assertOk();
        $this->assertNull($incident->fresh()->resolved_at);
        $this->assertSame(0, ActivityLog::where('action', 'security_incidents_resolved')->count());
    }
}
