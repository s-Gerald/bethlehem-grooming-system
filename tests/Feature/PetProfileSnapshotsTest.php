<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\BookingController;
use App\Models\BookingPet;
use App\Models\Pet;
use App\Models\User;
use App\Support\PetProfileSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PetProfileSnapshotsTest extends TestCase
{
    use \Tests\Support\CreatesGroomingVisitFixture;
    use \Tests\Support\CreatesRegistrationAvailability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGroomingVisitFixture();
        $this->createRegistrationAvailability();
        Schema::table('pets', function (Blueprint $t) {
            $t->integer('user_id')->nullable();
            foreach (['breed', 'fur_type', 'body_condition', 'medical_conditions'] as $field) $t->string($field)->nullable();
            $t->float('weight')->nullable();
            $t->json('clinic_verified_fields')->nullable();
        });
        Schema::table('booking_services', function (Blueprint $t) {
            $t->string('service_name_snapshot')->nullable();
            $t->string('service_kind_snapshot')->nullable();
        });
        DB::table('pets')->where('pet_id', 1)->update([
            'user_id' => 1, 'pet_name' => 'Helio', 'species' => 'Cat', 'breed' => 'Siamese', 'size' => 'small',
            'weight' => 7, 'fur_type' => 'Short Hair', 'body_condition' => 'obese',
            'medical_conditions' => 'Sensitive to shampoo', 'clinic_verified_fields' => '["size","body_condition","password"]',
        ]);
    }

    private function request(array $query = []): Request
    {
        $request = Request::create('/history', 'GET', $query);
        $request->setUserResolver(fn () => User::find(1));
        return $request;
    }

    public function test_registration_and_intake_freeze_values_and_verification_without_refreshing(): void
    {
        $pet = Pet::find(1);
        $snapshot = PetProfileSnapshot::registration($pet);
        $this->assertSame(['size', 'body_condition'], $snapshot['registration']['verified_fields']);
        $pet->body_condition = 'overweight';
        $snapshot = PetProfileSnapshot::intake($snapshot, $pet);
        $pet->body_condition = 'thin';
        $same = PetProfileSnapshot::intake($snapshot, $pet);
        $this->assertSame($snapshot, $same);
        $this->assertSame('obese', $snapshot['registration']['body_condition']);
        $this->assertSame('overweight', PetProfileSnapshot::resolve($snapshot)['body_condition']);
        $this->assertSame([], PetProfileSnapshot::resolve(null));
    }

    public function test_customer_admin_search_and_payment_labels_remain_historical_after_pet_edits(): void
    {
        $snapshot = PetProfileSnapshot::registration(Pet::find(1), intake: true);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'pet_profile_snapshot' => json_encode($snapshot), 'registered_size' => 'small', 'confirmed_size' => 'small',
        ]);
        DB::table('booking_pets')->update(['grooming_state' => 'finished', 'grooming_end_time' => now()]);
        DB::table('bookings')->update(['status' => 'archived', 'paid' => true, 'booking_date' => now()->toDateString()]);
        DB::table('booking_services')->update(['service_name_snapshot' => 'Recorded grooming', 'service_kind_snapshot' => 'package']);
        DB::table('services')->update(['service_name' => 'Renamed catalogue']);
        Pet::find(1)->update(['pet_name' => 'Renamed pet', 'breed' => 'Mixed Breed', 'size' => 'medium',
            'weight' => 24, 'body_condition' => 'thin', 'fur_type' => 'Long Hair', 'medical_conditions' => 'New care notes']);

        $current = Pet::find(1);
        $this->assertSame('Renamed pet', $current->pet_name);
        $this->assertFalse($current->hasVerifiedBodyCondition());
        $profile = app(\App\Http\Controllers\PetController::class)->show($this->request(), 1)->getData(true)['pet'];
        $this->assertSame('Renamed pet', $profile['pet_name']);
        $this->assertSame('thin', $profile['body_condition']);
        $this->assertEquals(24, $profile['weight']);
        $customer = app(BookingController::class)->history($this->request())->getData(true)['history'][0]['pets'][0];
        $this->assertSame('Helio', $customer['pet_name']);
        $this->assertSame('Siamese', $customer['breed']);
        $this->assertEquals(7, $customer['weight']);
        $this->assertSame('small', $customer['size']);
        $this->assertSame('obese', $customer['body_condition']);
        $this->assertTrue($customer['body_condition_verified']);
        $this->assertSame('Sensitive to shampoo', $customer['medical_conditions']);
        $this->assertSame('Recorded grooming', $customer['services'][0]['service_name']);

        $admin = app(AdminBookingController::class)->archivedIndex($this->request(['search' => 'Helio']))->getData(true);
        $this->assertSame(1, $admin['total']);
        $record = $admin['archived'][0];
        $this->assertSame('Helio +1 more', $record['petName']);
        $this->assertSame('Siamese', $record['pets'][0]['breed']);
        $this->assertSame('7 kg', $record['pets'][0]['weight']);
        $this->assertSame('Short Hair', $record['pets'][0]['furType']);
        $this->assertSame('obese', $record['pets'][0]['bodyCondition']);
        $this->assertTrue($record['pets'][0]['bodyConditionVerified']);
        $this->assertSame('Sensitive to shampoo', $record['pets'][0]['medicalConditions']);
        $this->assertSame('Helio', $record['payment_summary']['pets'][0]['pet_name']);
        $this->assertSame('Recorded grooming', $record['services'][0]['serviceName']);
        $this->assertSame(0, app(AdminBookingController::class)->archivedIndex($this->request(['search' => 'Renamed pet']))->getData(true)['total']);
        $summary = app(BookingController::class)->show($this->request(), 1)->getData(true)['booking'];
        $this->assertSame('Helio', $summary['booking_pets'][0]['pet']['pet_name']);
    }

    public function test_missing_snapshot_does_not_fall_back_to_current_pet(): void
    {
        DB::table('bookings')->update(['status' => 'cancelled']);
        $pet = app(BookingController::class)->history($this->request())->getData(true)['history'][0]['pets'][0];
        $this->assertNotSame('Helio', $pet['pet_name']);
        $this->assertNull($pet['body_condition']);
        $this->assertFalse($pet['body_condition_verified']);
    }

    public function test_migration_freezes_legacy_rows_idempotently_without_inventing_verification(): void
    {
        $migration = require database_path('migrations/2026_11_10_000002_add_pet_profile_snapshots.php');
        $migration->up();
        $before = BookingPet::find(1)->pet_profile_snapshot;
        $this->assertSame('legacy_baseline', $before['legacy']['source']);
        $this->assertSame([], $before['legacy']['verified_fields']);
        $this->assertSame('large', $before['legacy']['size']);
        DB::table('pets')->update(['pet_name' => 'Changed after backfill']);
        $migration->up();
        $this->assertSame($before, BookingPet::find(1)->pet_profile_snapshot);
        $this->assertSame('Helio', PetProfileSnapshot::resolve($before)['pet_name']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('booking_pets', 'pet_profile_snapshot'));
    }

    public function test_cancelled_before_intake_uses_registration_and_missing_pet_remains_readable(): void
    {
        $snapshot = PetProfileSnapshot::registration(Pet::find(1));
        DB::table('booking_pets')->where('booking_pet_id', 1)->update([
            'pet_profile_snapshot' => json_encode($snapshot), 'registered_size' => 'small', 'confirmed_size' => null,
        ]);
        DB::table('bookings')->update(['status' => 'cancelled', 'dropped_off_at' => null]);
        DB::table('pets')->where('pet_id', 1)->delete();
        $pet = app(BookingController::class)->history($this->request())->getData(true)['history'][0]['pets'][0];
        $this->assertSame('Helio', $pet['pet_name']);
        $this->assertSame('small', $pet['size']);
        $this->assertSame('obese', $pet['body_condition']);
    }

    public function test_reverted_check_in_clears_intake_and_confirmation_before_cancellation(): void
    {
        (require database_path('migrations/2026_10_29_000001_add_grooming_intake_sequences.php'))->up();
        $snapshot = PetProfileSnapshot::registration(Pet::find(1));
        $pet = Pet::find(1);
        $pet->pet_name = 'Intake name';
        $snapshot = PetProfileSnapshot::intake($snapshot, $pet);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['pet_profile_snapshot' => json_encode($snapshot), 'registered_size' => 'small']);
        DB::table('bookings')->update(['booking_date' => now()->toDateString()]);
        $this->assertSame(200, app(AdminBookingController::class)->revertCheckIn(1)->getStatusCode());
        $bp = BookingPet::find(1);
        $this->assertArrayNotHasKey('intake', $bp->pet_profile_snapshot);
        $this->assertNull($bp->confirmed_size);
        DB::table('bookings')->update(['status' => 'cancelled']);
        $pet = app(BookingController::class)->history($this->request())->getData(true)['history'][0]['pets'][0];
        $this->assertSame('Helio', $pet['pet_name']);
        $this->assertSame('small', $pet['size']);
    }

    public function test_backfill_reads_in_bounded_chunks_handles_orphans_and_preserves_existing_snapshots(): void
    {
        $original = PetProfileSnapshot::registration(Pet::find(1));
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['pet_profile_snapshot' => json_encode($original)]);
        $original = BookingPet::find(1)->pet_profile_snapshot;
        foreach (range(3, 405) as $id) {
            DB::table('booking_pets')->insert(['booking_pet_id' => $id, 'booking_id' => 1, 'pet_id' => 999]);
        }
        DB::enableQueryLog();
        (require database_path('migrations/2026_11_10_000002_add_pet_profile_snapshots.php'))->up();
        $reads = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'left join "pets"'));
        DB::disableQueryLog();
        $this->assertCount(3, $reads);
        $this->assertSame(0, BookingPet::whereNull('pet_profile_snapshot')->count());
        $this->assertSame($original, BookingPet::find(1)->pet_profile_snapshot);
        $this->assertNull(PetProfileSnapshot::resolve(BookingPet::find(405)->pet_profile_snapshot)['pet_name']);
    }

    public function test_historical_search_resolves_whole_stages_without_mixing_nulls_or_older_names(): void
    {
        $snapshot = PetProfileSnapshot::registration(Pet::find(1));
        $pet = Pet::find(1);
        $pet->pet_name = 'Arrival name';
        $snapshot = PetProfileSnapshot::intake($snapshot, $pet);
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['pet_profile_snapshot' => json_encode($snapshot)]);
        $this->assertSame(1, BookingPet::where(fn ($q) => PetProfileSnapshot::whereIdentity($q, 'Arrival', ['pet_name']))->count());
        $this->assertSame(0, BookingPet::where(fn ($q) => PetProfileSnapshot::whereIdentity($q, 'Helio', ['pet_name']))->count());
        $snapshot['intake']['pet_name'] = null;
        DB::table('booking_pets')->where('booking_pet_id', 1)->update(['pet_profile_snapshot' => json_encode($snapshot)]);
        $this->assertSame(0, BookingPet::where(fn ($q) => PetProfileSnapshot::whereIdentity($q, 'Helio', ['pet_name']))->count());
    }
}
