<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\BookingPet;
use App\Models\Pet;
use App\Models\User;
use App\Notifications\GroomingFinishedNotification;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardSummaryTest extends TestCase
{
    use \Tests\Support\CreatesPetGroomers;

    public function test_compact_dashboard_and_indicator_do_not_load_workflow_details(): void
    {
        DB::table('bookings')->insert([
            ['booking_reference' => 'ACTIVE-TODAY', 'booking_date' => '2026-06-24', 'number_of_pets' => 2, 'status' => 'checked_in'],
            ['booking_reference' => 'FUTURE', 'booking_date' => '2026-06-25', 'number_of_pets' => 1, 'status' => 'waiting_to_arrive'],
        ]);
        $controller = new AdminBookingController;
        DB::enableQueryLog();
        $full = $controller->index(Request::create('/api/admin/bookings'))->getData(true);
        $fullQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $board = $controller->index(Request::create('/api/admin/bookings?view=board'))->getData(true);
        $this->assertSame($full['summary']['today'], $board['summary']['today']);
        $this->assertSame($full['summary']['week'], $board['summary']['week']);
        $this->assertArrayNotHasKey('recentActivity', $board);
        $this->assertArrayNotHasKey('revenueToday', $board['summary']);
        DB::flushQueryLog();
        $summary = $controller->dashboardSummary()->getData(true);
        $summaryQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $indicator = $controller->activityIndicator()->getData(true);
        $indicatorQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($full['summary']['today'], $summary['summary']['today']);
        $this->assertSame($full['summary']['waitingNow'], $summary['summary']['waitingNow']);
        $this->assertSame($full['capacity'], $summary['capacity']);
        $this->assertCount(1, $summary['queuePreview']);
        $this->assertSame(1, $summary['activeTodayCount']);
        $this->assertSame(1, $indicator['activeTodayCount']);
        $this->assertArrayNotHasKey('incomingList', $summary);
        $this->assertArrayNotHasKey('groomingWorkload', $summary);
        $this->assertArrayNotHasKey('services', $summary['queuePreview'][0]);
        $this->assertLessThan($fullQueries, $summaryQueries);
        $this->assertSame(1, $indicatorQueries);
        fwrite(STDOUT, "\nLocal fixture: full={$fullQueries} queries/".strlen(json_encode($full))." bytes; summary={$summaryQueries} queries/".strlen(json_encode($summary))." bytes; indicator={$indicatorQueries} query/".strlen(json_encode($indicator))." bytes\n");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPetGroomers();

        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        Schema::create('clinic_settings', function (Blueprint $table) {
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

        Schema::create('users', function (Blueprint $table) {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('password_hash')->nullable();
        });

        Schema::create('time_windows', function (Blueprint $table) {
            $table->increments('window_id');
            $table->time('start_time');
            $table->time('end_time');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->increments('booking_id');
            $table->string('booking_reference')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date');
            $table->unsignedTinyInteger('number_of_pets')->default(1);
            $table->string('status');
            $table->integer('queue_number')->nullable();
            $table->dateTime('dropped_off_at')->nullable();
            $table->dateTime('grooming_started_at')->nullable();
            $table->dateTime('grooming_finished_at')->nullable();
            $table->boolean('paid')->default(false);
            $table->decimal('total_amount', 8, 2)->default(0);
        });

        Schema::create('booking_pets', function (Blueprint $table) {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
            $table->string('confirmed_size')->nullable();
            $table->string('grooming_preference')->nullable();
            $table->unsignedInteger('grooming_estimate_min')->nullable();
            $table->unsignedInteger('grooming_estimate_max')->nullable();
            $table->json('grooming_estimate_factors')->nullable();
            $table->date('pet_queue_date')->nullable();
            $table->unsignedInteger('pet_queue_number')->nullable();
            $table->text('special_instructions')->nullable();
            $table->dateTime('grooming_start_time')->nullable();
            $table->dateTime('grooming_end_time')->nullable();
            $table->string('grooming_state', 20)->default(
                BookingPet::GROOMING_STATE_NOT_STARTED,
            );
            $table->unique(['pet_queue_date', 'pet_queue_number']);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->increments('pet_id');
            $table->string('pet_name');
            $table->string('species')->nullable();
            $table->string('breed')->nullable();
            $table->string('size')->nullable();
            $table->json('clinic_verified_fields')->nullable();
            $table->string('fur_type')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->text('medical_conditions')->nullable();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->increments('service_id');
            $table->string('slug');
            $table->string('service_name');
        });

        Schema::create('booking_services', function (Blueprint $table) {
            $table->increments('booking_service_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('booking_pet_id')->nullable();
            $table->unsignedInteger('service_id')->nullable();
            $table->decimal('price_at_booking', 8, 2)->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('payment_status');
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->decimal('amount_tendered', 8, 2)->nullable();
            $table->decimal('change_amount', 8, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

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
            $table->string('type');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        (require database_path('migrations/2026_10_16_000005_create_grooming_consents.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('grooming_consents');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');
        Schema::dropIfExists('clinic_settings');
        Schema::dropIfExists('time_windows');

        Schema::dropIfExists('pet_groomers');
        parent::tearDown();
    }

    public function test_dashboard_counts_pets_in_today_and_week_summaries(): void
    {
        DB::table('bookings')->insert([
            [
                'booking_reference' => 'COMPLETED-10',
                'booking_date' => '2026-06-24',
                'number_of_pets' => 10,
                'status' => 'archived',
                'grooming_finished_at' => '2026-06-24 10:00:00',
            ],
            [
                'booking_reference' => 'WEEKLY-3',
                'booking_date' => '2026-06-25',
                'number_of_pets' => 3,
                'status' => 'archived',
                'grooming_finished_at' => null,
            ],
            [
                'booking_reference' => 'NO-SHOW-4',
                'booking_date' => '2026-06-26',
                'number_of_pets' => 4,
                'status' => 'no_show',
                'grooming_finished_at' => null,
            ],
            [
                'booking_reference' => 'CANCELLED-7',
                'booking_date' => '2026-06-24',
                'number_of_pets' => 7,
                'status' => 'cancelled',
                'grooming_finished_at' => '2026-06-24 11:00:00',
            ],
            [
                'booking_reference' => 'OUTSIDE-WEEK-9',
                'booking_date' => '2026-06-21',
                'number_of_pets' => 9,
                'status' => 'archived',
                'grooming_finished_at' => null,
            ],
        ]);

        $response = (new AdminBookingController)->index(
            Request::create('/api/admin/bookings', 'GET'),
        );
        $summary = $response->getData(true)['summary'];

        $this->assertSame(10, $summary['today']);
        $this->assertSame(17, $summary['week']);
        $this->assertSame(0, $summary['waitingNow']);
        $this->assertArrayNotHasKey('noShowWeek', $summary);
        $this->assertArrayNotHasKey('noShowWeekRate', $summary);
    }

    public function test_schedule_pet_type_summary_uses_all_pets_with_correct_pluralization(): void
    {
        $cases = [
            [['dog', 'cat'], 'Dog and Cat'],
            [['dog', 'dog', 'cat'], 'Dogs and Cat'],
            [['dog', 'cat', 'cat'], 'Dog and Cats'],
            [['dog', 'dog', 'cat', 'cat'], 'Dogs and Cats'],
        ];

        $formatBooking = new \ReflectionMethod(AdminBookingController::class, 'formatBooking');
        $controller = new AdminBookingController;

        foreach ($cases as [$species, $expected]) {
            $booking = new Booking([
                'booking_reference' => 'PET-TYPES',
                'booking_date' => '2026-06-24',
                'number_of_pets' => count($species),
                'status' => 'waiting_to_arrive',
            ]);
            $booking->setAttribute('booking_id', 1);
            $booking->setRelation('user', null);
            $booking->setRelation('timeWindow', null);
            $booking->setRelation('bookingServices', collect());
            $booking->setRelation('payments', collect());
            $booking->setRelation('bookingPets', collect($species)->map(function ($type, $index) {
                $pet = new Pet([
                    'pet_name' => 'Pet '.($index + 1),
                    'species' => $type,
                ]);
                $bookingPet = new BookingPet;
                $bookingPet->setAttribute('booking_pet_id', $index + 1);
                $bookingPet->setRelation('pet', $pet);

                return $bookingPet;
            }));

            $formatted = $formatBooking->invoke($controller, $booking);

            $this->assertSame($expected, $formatted['petType']);
            $this->assertSame(
                range(1, count($species)),
                collect($formatted['pets'])->pluck('petQueueNumber')->all(),
            );
        }
    }

    public function test_pet_queue_continues_from_highest_number_on_effective_date_and_resets_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 08:00:00'));

        DB::table('bookings')->insert([
            [
                'booking_id' => 1,
                'booking_reference' => 'DONE-7',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 7,
                'status' => 'archived',
                'queue_number' => 1,
                'dropped_off_at' => '2026-06-29 07:00:00',
            ],
            [
                'booking_id' => 2,
                'booking_reference' => 'RELEASED-5',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 5,
                'status' => 'released',
                'queue_number' => 2,
                'dropped_off_at' => '2026-06-29 07:30:00',
            ],
            [
                'booking_id' => 3,
                'booking_reference' => 'CURRENT-5',
                'booking_date' => '2026-06-29',
                'number_of_pets' => 5,
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ],
            [
                'booking_id' => 4,
                'booking_reference' => 'NEXT-DAY-1',
                'booking_date' => '2026-06-30',
                'number_of_pets' => 1,
                'status' => 'waiting_to_arrive',
                'queue_number' => null,
                'dropped_off_at' => null,
            ],
        ]);

        $pets = [];
        $bookingPets = [];
        for ($petId = 1; $petId <= 18; $petId++) {
            $pets[] = [
                'pet_id' => $petId,
                'pet_name' => "Pet {$petId}",
                'species' => 'dog',
                'size' => 'small',
            ];

            $bookingId = $petId <= 7 ? 1 : ($petId <= 12 ? 2 : ($petId <= 17 ? 3 : 4));
            $bookingPets[] = [
                'booking_pet_id' => $petId,
                'booking_id' => $bookingId,
                'pet_id' => $petId,
                'pet_queue_date' => $petId <= 12 ? '2026-06-29' : null,
                'pet_queue_number' => $petId <= 12 ? $petId : null,
                'grooming_state' => $petId <= 12 ? BookingPet::GROOMING_STATE_FINISHED : BookingPet::GROOMING_STATE_NOT_STARTED,
                'grooming_end_time' => $petId <= 12 ? '2026-06-29 07:45:00' : null,
            ];
        }

        DB::table('pets')->insert($pets);
        DB::table('booking_pets')->insert($bookingPets);
        DB::table('services')->insert(['service_id' => 1, 'slug' => 'partial_grooming', 'service_name' => 'Partial Grooming']);
        DB::table('booking_services')->insert(array_map(fn ($pet) => [
            'booking_id' => $pet['booking_id'], 'booking_pet_id' => $pet['booking_pet_id'], 'service_id' => 1,
        ], $bookingPets));


        $controller = new AdminBookingController;
        $response = $controller->checkIn(3);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [13, 14, 15, 16, 17],
            DB::table('booking_pets')
                ->where('booking_id', 3)
                ->orderBy('booking_pet_id')
                ->pluck('pet_queue_number')
                ->all(),
        );
        $this->assertSame(
            ['2026-06-29'],
            DB::table('booking_pets')
                ->where('booking_id', 3)
                ->distinct()
                ->pluck('pet_queue_date')
                ->map(fn ($date) => substr($date, 0, 10))
                ->all(),
        );

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame(
            [13, 14, 15, 16, 17],
            collect($schedule['queuedList'][0]['pets'])->pluck('petQueueNumber')->all(),
        );

        Carbon::setTestNow(Carbon::parse('2026-06-30 08:00:00'));
        $nextDayResponse = $controller->checkIn(4);

        $this->assertSame(200, $nextDayResponse->getStatusCode());
        $this->assertSame(
            1,
            DB::table('booking_pets')->where('booking_id', 4)->value('pet_queue_number'),
        );
    }

    public function test_starting_one_pet_moves_owner_card_only_to_in_progress_feed(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-START-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'pet_id' => 1],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 2],
        ]);

        $controller = new AdminBookingController;
        $firstResponse = $controller->startPetGrooming(1, 1);

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertFalse($firstResponse->getData(true)['all_pets_started']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_start_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame([], collect($schedule['queuedList'])->pluck('id')->all());
        $this->assertSame([1], collect($schedule['inProgressList'])->pluck('id')->all());
        $this->assertSame('in-progress', $schedule['inProgressList'][0]['status']);
        $this->assertSame('in-progress', $controller->dashboardSummary()->getData(true)['queuePreview'][0]['status']);
        $preview = $controller->dashboardSummary()->getData(true)['queuePreview'][0];
        foreach (['petName', 'breed', 'ownerName'] as $field) {
            $this->assertSame($schedule['inProgressList'][0][$field], $preview[$field]);
        }
        $this->assertTrue($schedule['inProgressList'][0]['pets'][0]['isGroomingStarted']);
        $this->assertFalse($schedule['inProgressList'][0]['pets'][1]['isGroomingStarted']);

        $secondResponse = $controller->startPetGrooming(1, 2);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertTrue($secondResponse->getData(true)['all_pets_started']);
        $this->assertSame('in_progress', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 1)->value('grooming_started_at'));
    }

    public function test_started_pet_customer_notification_mentions_only_that_pet(): void
    {
        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.test',
            'role' => 'customer',
            'password_hash' => 'secret',
        ]);

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'user_id' => 1,
            'booking_reference' => 'PET-START-NOTIF-3',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 3,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Max', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Bella', 'species' => 'dog'],
            ['pet_id' => 3, 'pet_name' => 'Luna', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'pet_id' => 1],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 2],
            ['booking_pet_id' => 3, 'booking_id' => 1, 'pet_id' => 3],
        ]);

        $startResponse = (new AdminBookingController)->startPetGrooming(1, 1);

        $this->assertSame(200, $startResponse->getStatusCode());
        $this->assertSame(1, DB::table('customer_notifications')->count());

        $request = Request::create('/api/customer/notifications', 'GET');
        $request->setUserResolver(fn () => (object) ['user_id' => 1]);

        $payload = (new CustomerNotificationController)->index($request)->getData(true);
        $notification = $payload['notifications'][0];

        $this->assertSame("Great news! Max has Started Grooming. We'll let you know as soon as they're ready for pickup!", $notification['display_message']);
        $this->assertStringNotContainsString('Bella', $notification['display_message']);
        $this->assertStringNotContainsString('Luna', $notification['display_message']);
        $this->assertSame(['Max'], $notification['pet_names']);
    }

    public function test_finished_pet_customer_notification_mentions_only_that_pet_while_siblings_remain(): void
    {
        NotificationFacade::fake();

        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.test',
            'role' => 'customer',
            'password_hash' => 'secret',
        ]);

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'user_id' => 1,
            'booking_reference' => 'PET-FINISH-NOTIF-3',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 3,
            'status' => 'in_progress',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Max', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Bella', 'species' => 'dog'],
            ['pet_id' => 3, 'pet_name' => 'Luna', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => '2026-06-24 10:05:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 3,
                'booking_id' => 1,
                'pet_id' => 3,
                'grooming_start_time' => '2026-06-24 10:10:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
        ]);

        $finishResponse = (new AdminBookingController)->markPetDone(1, 1);

        $this->assertSame(200, $finishResponse->getStatusCode());
        $this->assertSame('grooming_finished', DB::table('customer_notifications')->value('type'));

        $request = Request::create('/api/customer/notifications', 'GET');
        $request->setUserResolver(fn () => (object) ['user_id' => 1]);

        $payload = (new CustomerNotificationController)->index($request)->getData(true);
        $notification = $payload['notifications'][0];

        $this->assertSame("Max is Finished with grooming. We'll keep you updated on the rest of the visit.", $notification['display_message']);
        $this->assertStringNotContainsString('Bella', $notification['display_message']);
        $this->assertStringNotContainsString('Luna', $notification['display_message']);
        $this->assertSame(['Max'], $notification['pet_names']);
        $this->assertSame(['dog'], $notification['pet_types']);
        $this->assertNull($payload['pickup_alert']);

        NotificationFacade::assertSentTo(
            User::findOrFail(1),
            GroomingFinishedNotification::class,
            fn (GroomingFinishedNotification $notification, array $channels) => $channels === ['mail']
                && $notification->petNames === ['Max'],
        );
    }

    public function test_ready_for_pickup_notification_does_not_mention_a_single_pet_name(): void
    {
        NotificationFacade::fake();

        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.test',
            'role' => 'customer',
            'password_hash' => 'secret',
        ]);

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'user_id' => 1,
            'booking_reference' => 'PET-FINAL-FINISH-NOTIF-3',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 3,
            'status' => 'in_progress',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Max', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Bella', 'species' => 'dog'],
            ['pet_id' => 3, 'pet_name' => 'Luna', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => '2026-06-24 10:05:00',
                'grooming_end_time' => '2026-06-24 11:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ],
            [
                'booking_pet_id' => 3,
                'booking_id' => 1,
                'pet_id' => 3,
                'grooming_start_time' => '2026-06-24 10:10:00',
                'grooming_end_time' => '2026-06-24 11:05:00',
                'grooming_state' => BookingPet::GROOMING_STATE_FINISHED,
            ],
        ]);

        $finishResponse = (new AdminBookingController)->markPetDone(1, 1);

        $this->assertSame(200, $finishResponse->getStatusCode());
        $this->assertSame('ready_for_pickup', DB::table('customer_notifications')->value('type'));

        $request = Request::create('/api/customer/notifications', 'GET');
        $request->setUserResolver(fn () => (object) ['user_id' => 1]);

        $payload = (new CustomerNotificationController)->index($request)->getData(true);
        $notification = $payload['notifications'][0];

        $expectedPickupMessage = 'Your pets are now Ready for Pickup and looking fabulous! Please come to the clinic to pick them up.';

        $this->assertSame($expectedPickupMessage, $notification['display_message']);
        $this->assertSame($expectedPickupMessage, $payload['pickup_alert']['display_message']);
        $this->assertStringNotContainsString('Max', $notification['display_message']);
        $this->assertStringNotContainsString('Bella', $notification['display_message']);
        $this->assertStringNotContainsString('Luna', $notification['display_message']);
        $this->assertSame(['Max', 'Bella', 'Luna'], $notification['pet_names']);
        $this->assertSame(['Max', 'Bella', 'Luna'], $payload['pickup_alert']['pet_names']);

        NotificationFacade::assertSentTo(
            User::findOrFail(1),
            GroomingFinishedNotification::class,
            fn (GroomingFinishedNotification $notification, array $channels) => $channels === ['mail']
                && $notification->petNames === ['Max'],
        );
    }

    public function test_finishing_a_whole_booking_sends_one_email_for_its_completed_pets(): void
    {
        NotificationFacade::fake();

        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Jamie',
            'last_name' => 'Santos',
            'email' => 'jamie@example.test',
            'role' => 'customer',
            'password_hash' => 'secret',
        ]);

        DB::table('bookings')->insert([
            'booking_id' => 1,
            'user_id' => 1,
            'booking_reference' => 'WHOLE-BOOKING-FINISH-EMAIL',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'in_progress',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Max', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Bella', 'species' => 'dog'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => '2026-06-24 10:05:00',
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
        ]);

        $response = (new AdminBookingController)->markDone(1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ready_for_pickup', DB::table('customer_notifications')->value('type'));

        NotificationFacade::assertSentTo(
            User::findOrFail(1),
            GroomingFinishedNotification::class,
            fn (GroomingFinishedNotification $notification, array $channels) => $channels === ['mail']
                && $notification->petNames === ['Max', 'Bella'],
        );
    }

    #[DataProvider('groomerCapacities')]
    public function test_groomer_capacity_limits_active_pets_and_finishing_frees_a_slot(int $groomersOnDuty): void
    {
        $this->setPetGroomersOnDuty($groomersOnDuty);

        for ($index = 1; $index <= $groomersOnDuty + 1; $index++) {
            DB::table('bookings')->insert([
                'booking_id' => $index,
                'booking_reference' => "CAPACITY-{$groomersOnDuty}-{$index}",
                'booking_date' => '2026-06-24',
                'number_of_pets' => 1,
                'status' => 'checked_in',
                'queue_number' => $index,
            ]);
            DB::table('pets')->insert([
                'pet_id' => $index,
                'pet_name' => "Pet {$index}",
                'species' => 'dog',
            ]);
            DB::table('booking_pets')->insert([
                'booking_pet_id' => $index,
                'booking_id' => $index,
                'pet_id' => $index,
            ]);
        }

        $controller = new AdminBookingController;
        for ($index = 1; $index <= $groomersOnDuty; $index++) {
            $this->assertSame(200, $controller->startPetGrooming($index, $index)->getStatusCode());
        }

        $blockedPetId = $groomersOnDuty + 1;
        $blockedResponse = $controller->startPetGrooming($blockedPetId, $blockedPetId);
        $this->assertSame(422, $blockedResponse->getStatusCode());
        $this->assertStringContainsString('Groomer capacity is full', $blockedResponse->getData(true)['message']);

        $schedule = $controller->index(Request::create('/api/admin/bookings', 'GET'))->getData(true);
        $this->assertSame($groomersOnDuty, $schedule['groomerCapacity']['active_pets']);
        $this->assertTrue($schedule['groomerCapacity']['is_full']);

        $this->assertSame(200, $controller->markPetDone(1, 1)->getStatusCode());
        $this->assertSame(200, $controller->startPetGrooming($blockedPetId, $blockedPetId)->getStatusCode());
    }

    public static function groomerCapacities(): array
    {
        return [
            'one groomer' => [1],
            'two groomers' => [2],
            'three groomers' => [3],
        ];
    }

    public function test_groomers_on_duty_count_is_derived_from_named_records(): void
    {
        $this->setPetGroomersOnDuty(3);
        $this->assertSame(3, \App\Models\ClinicSetting::current()->groomers_on_duty);
        $this->setPetGroomersOnDuty(0);
        $this->assertSame(0, \App\Models\ClinicSetting::current()->groomers_on_duty);
    }

    public function test_booking_level_start_cannot_bypass_pet_capacity(): void
    {
        $this->setPetGroomersOnDuty(1);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'CAPACITY-WHOLE-BOOKING',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
        ]);
        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);
        DB::table('booking_pets')->insert([
            ['booking_pet_id' => 1, 'booking_id' => 1, 'pet_id' => 1],
            ['booking_pet_id' => 2, 'booking_id' => 1, 'pet_id' => 2],
        ]);

        $response = (new AdminBookingController)->startGrooming(1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_start_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
    }

    public function test_finishing_pets_keeps_booking_in_progress_until_every_pet_is_done(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-FINISH-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'in_progress',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => '2026-06-24 10:05:00',
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
        ]);

        $controller = new AdminBookingController;
        $firstResponse = $controller->markPetDone(1, 1);

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertFalse($firstResponse->getData(true)['all_pets_finished']);
        $this->assertSame('in_progress', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_end_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_end_time'));
        $this->assertSame(0, DB::table('notifications')->count());

        $secondResponse = $controller->markPetDone(1, 2);

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertTrue($secondResponse->getData(true)['all_pets_finished']);
        $this->assertSame('for_payment', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('bookings')->where('booking_id', 1)->value('grooming_finished_at'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_end_time'));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_started_pet_can_finish_while_sibling_remains_queued(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-PARTIAL-FINISH-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
        ]);

        $response = (new AdminBookingController)->markPetDone(1, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['all_pets_finished']);
        $this->assertSame('checked_in', $response->getData(true)['booking_status']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertNotNull(DB::table('booking_pets')->where('booking_pet_id', 1)->value('grooming_end_time'));
        $this->assertNull(DB::table('booking_pets')->where('booking_pet_id', 2)->value('grooming_start_time'));

        $schedule = (new AdminBookingController)->index(
            Request::create('/api/admin/bookings', 'GET'),
        )->getData(true);

        $this->assertSame([], collect($schedule['queuedList'])->pluck('id')->all());
        $this->assertSame([1], collect($schedule['inProgressList'])->pluck('id')->all());
        $this->assertTrue($schedule['inProgressList'][0]['pets'][0]['isGroomingFinished']);
        $this->assertFalse($schedule['inProgressList'][0]['pets'][1]['isGroomingStarted']);
    }

    public function test_early_payment_waits_for_every_pet_before_moving_to_pickup(): void
    {
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'PET-EARLY-PAY-2',
            'booking_date' => '2026-06-24',
            'number_of_pets' => 2,
            'status' => 'checked_in',
            'queue_number' => 1,
            'grooming_started_at' => '2026-06-24 10:00:00',
        ]);

        DB::table('pets')->insert([
            ['pet_id' => 1, 'pet_name' => 'Zeus', 'species' => 'dog'],
            ['pet_id' => 2, 'pet_name' => 'Ginger', 'species' => 'cat'],
        ]);

        DB::table('booking_pets')->insert([
            [
                'booking_pet_id' => 1,
                'booking_id' => 1,
                'pet_id' => 1,
                'grooming_start_time' => '2026-06-24 10:00:00',
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_IN_PROGRESS,
            ],
            [
                'booking_pet_id' => 2,
                'booking_id' => 1,
                'pet_id' => 2,
                'grooming_start_time' => null,
                'grooming_end_time' => null,
                'grooming_state' => BookingPet::GROOMING_STATE_NOT_STARTED,
            ],
        ]);
        DB::table('booking_services')->insert([
            [
                'booking_service_id' => 1,
                'booking_id' => 1,
                'booking_pet_id' => 1,
                'service_id' => null,
                'price_at_booking' => 250,
            ],
            [
                'booking_service_id' => 2,
                'booking_id' => 1,
                'booking_pet_id' => 2,
                'service_id' => null,
                'price_at_booking' => 250,
            ],
        ]);

        $paymentResponse = (new PaymentController)->payNow(new Request([
            'final_price' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'service_prices' => [
                ['booking_service_id' => 1, 'amount' => 250],
                ['booking_service_id' => 2, 'amount' => 250],
            ],
        ]), 1);

        $this->assertSame(200, $paymentResponse->getStatusCode());
        $this->assertFalse($paymentResponse->getData(true)['all_pets_finished']);
        $this->assertSame(2, $paymentResponse->getData(true)['remaining_pets']);
        $this->assertSame('checked_in', $paymentResponse->getData(true)['booking_status']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));
        $this->assertTrue((bool) DB::table('bookings')->where('booking_id', 1)->value('paid'));

        $bookingController = new AdminBookingController;
        $firstFinish = $bookingController->markPetDone(1, 1);

        $this->assertSame(200, $firstFinish->getStatusCode());
        $this->assertFalse($firstFinish->getData(true)['all_pets_finished']);
        $this->assertSame('checked_in', DB::table('bookings')->where('booking_id', 1)->value('status'));

        $bookingController->startPetGrooming(1, 2);
        $finalFinish = $bookingController->markPetDone(1, 2);

        $this->assertSame(200, $finalFinish->getStatusCode());
        $this->assertTrue($finalFinish->getData(true)['all_pets_finished']);
        $this->assertSame('released', $finalFinish->getData(true)['booking_status']);
        $this->assertSame('released', DB::table('bookings')->where('booking_id', 1)->value('status'));
    }

    public function test_each_parent_workflow_state_has_an_admin_destination(): void
    {
        $states = [
            1 => ['waiting_to_arrive', BookingPet::GROOMING_STATE_NOT_STARTED, null, null],
            2 => ['checked_in', BookingPet::GROOMING_STATE_NOT_STARTED, null, null],
            3 => ['in_progress', BookingPet::GROOMING_STATE_IN_PROGRESS, '2026-06-24 09:00:00', null],
            4 => ['for_payment', BookingPet::GROOMING_STATE_FINISHED, '2026-06-24 09:00:00', '2026-06-24 10:00:00'],
            5 => ['released', BookingPet::GROOMING_STATE_FINISHED, '2026-06-24 09:00:00', '2026-06-24 10:00:00'],
        ];

        foreach ($states as $id => [$status, $petState, $startedAt, $finishedAt]) {
            DB::table('bookings')->insert([
                'booking_id' => $id,
                'booking_reference' => "VISIBLE-{$id}",
                'booking_date' => '2026-06-24',
                'number_of_pets' => 1,
                'status' => $status,
                'queue_number' => $id,
                'grooming_started_at' => $startedAt,
                'grooming_finished_at' => $finishedAt,
                'paid' => $status === 'released',
            ]);
            DB::table('pets')->insert([
                'pet_id' => $id,
                'pet_name' => "Visible Pet {$id}",
                'species' => 'dog',
            ]);
            DB::table('booking_pets')->insert([
                'booking_pet_id' => $id,
                'booking_id' => $id,
                'pet_id' => $id,
                'grooming_start_time' => $startedAt,
                'grooming_end_time' => $finishedAt,
                'grooming_state' => $petState,
            ]);
        }

        $schedule = (new AdminBookingController)->index(
            Request::create('/api/admin/bookings', 'GET'),
        )->getData(true);

        $this->assertSame([1], collect($schedule['incomingList'])->pluck('id')->all());
        $this->assertSame([2], collect($schedule['queuedList'])->pluck('id')->all());
        $this->assertSame([3], collect($schedule['inProgressList'])->pluck('id')->all());
        $this->assertSame([4], collect($schedule['forPaymentList'])->pluck('id')->all());
        $this->assertSame([5], collect($schedule['releasedList'])->pluck('id')->all());
    }

}
