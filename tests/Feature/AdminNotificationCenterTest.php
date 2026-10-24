<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminClinicController;
use App\Http\Controllers\NotificationController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminNotificationCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-21 12:30:00');

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        Schema::create('walkins', function (Blueprint $table): void {
            $table->id();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
        });

        Schema::create('time_windows', function (Blueprint $table): void {
            $table->increments('window_id');
            $table->string('window_label')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
        });

        Schema::create('pets', function (Blueprint $table): void {
            $table->increments('pet_id');
            $table->string('pet_name');
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->increments('booking_id');
            $table->string('booking_reference');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('booking_date')->nullable();
            $table->text('cancellation_reason')->nullable();
        });

        Schema::create('booking_pets', function (Blueprint $table): void {
            $table->increments('booking_pet_id');
            $table->unsignedInteger('booking_id');
            $table->unsignedInteger('pet_id')->nullable();
        });

        Schema::create('clinic_appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('appointment_reference');
            $table->string('status')->default('checked_in');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('walkin_id')->nullable();
            $table->unsignedInteger('pet_id')->nullable();
            $table->unsignedInteger('window_id')->nullable();
            $table->date('appointment_date')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->increments('notification_id');
            $table->string('type', 50);
            $table->unsignedInteger('booking_id')->nullable();
            $table->unsignedBigInteger('clinic_appointment_id')->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        DB::table('users')->insert([
            'user_id' => 1,
            'first_name' => 'Gerald',
            'last_name' => 'Senining',
        ]);
        DB::table('time_windows')->insert([
            'window_id' => 1,
            'window_label' => '1',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
        DB::table('pets')->insert(['pet_id' => 1, 'pet_name' => 'Mochi']);
        DB::table('bookings')->insert([
            'booking_id' => 1,
            'booking_reference' => 'BAC-20260821-0001',
            'user_id' => 1,
            'window_id' => 1,
            'booking_date' => '2026-08-21',
        ]);
        DB::table('booking_pets')->insert([
            'booking_pet_id' => 1,
            'booking_id' => 1,
            'pet_id' => 1,
        ]);
        DB::table('clinic_appointments')->insert([
            'id' => 1,
            'appointment_reference' => 'CLN-20260821-0001',
            'status' => 'checked_in',
            'user_id' => 1,
            'pet_id' => 1,
            'window_id' => 1,
            'appointment_date' => '2026-08-21',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('clinic_appointments');
        Schema::dropIfExists('booking_pets');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('time_windows');
        Schema::dropIfExists('walkins');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_category_and_unread_filters_return_only_matching_notifications(): void
    {
        $this->insertNotification('cancelled', false, bookingId: 1);
        $this->insertNotification('clinic_cancelled', true, clinicAppointmentId: 1);
        $this->insertNotification('clinic_booked', false, clinicAppointmentId: 1);
        $this->insertNotification('payment_due', false, bookingId: 1);

        $cancellations = $this->notificationPayload([
            'mode' => 'full',
            'status' => 'unread',
            'category' => 'cancellations',
        ]);

        $this->assertCount(1, $cancellations['notifications']);
        $this->assertSame('cancelled', $cancellations['notifications'][0]['type']);
        $this->assertSame('cancellations', $cancellations['notifications'][0]['category']);

        $clinic = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'clinic',
        ]);

        $this->assertCount(1, $clinic['notifications']);
        $this->assertSame('clinic_booked', $clinic['notifications'][0]['type']);

        $payments = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'payments',
        ]);

        $this->assertCount(1, $payments['notifications']);
        $this->assertSame('payment_due', $payments['notifications'][0]['type']);
        $this->assertSame(3, $payments['unread_count']);
    }

    public function test_full_view_paginates_and_returns_structured_emphasis_parts(): void
    {
        $this->insertNotification('payment_due', false, bookingId: 1);
        $this->insertNotification('payment_confirmed', false, bookingId: 1);

        for ($index = 1; $index <= 11; $index++) {
            $this->insertNotification(
                'booked',
                $index % 2 === 0,
                bookingId: 1,
                createdAt: now()->subMinutes($index),
            );
        }

        $firstPage = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'grooming',
            'per_page' => 10,
            'page' => 1,
        ]);
        $secondPage = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'grooming',
            'per_page' => 10,
            'page' => 2,
        ]);
        $payments = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'payments',
        ]);

        $this->assertCount(10, $firstPage['notifications']);
        $this->assertTrue($firstPage['pagination']['has_more']);
        $this->assertSame(11, $firstPage['pagination']['total']);
        $this->assertCount(1, $secondPage['notifications']);
        $this->assertFalse($secondPage['pagination']['has_more']);
        $this->assertSame(
            'New pre-registration BAC-20260821-0001 by Gerald Senining on Aug 21 at 8:00 AM - 9:00 AM.',
            $firstPage['notifications'][0]['message'],
        );
        $this->assertSame('calendar-days', $firstPage['notifications'][0]['icon']);

        $payment = collect($payments['notifications'])->firstWhere('type', 'payment_due');
        $received = collect($payments['notifications'])->firstWhere('type', 'payment_confirmed');

        $this->assertSame('Grooming done', $payment['title']);
        $this->assertSame(
            'Grooming done for Gerald Senining. Pet is ready - please collect payment.',
            $payment['message'],
        );
        $this->assertNull($payment['context']);
        $this->assertSame('circle-check', $payment['icon']);
        $this->assertTrue(collect($payment['message_parts'])->contains(
            fn (array $part) => $part['text'] === 'Gerald Senining'
                && $part['emphasized'] === true,
        ));

        $this->assertSame(
            'Payment received from Gerald Senining for booking BAC-20260821-0001.',
            $received['message'],
        );
        $this->assertSame('credit-card', $received['icon']);
        $this->assertTrue(collect($received['message_parts'])->contains(
            fn (array $part) => $part['text'] === 'Gerald Senining'
                && $part['emphasized'] === true,
        ));
        $this->assertTrue(collect($received['message_parts'])->contains(
            fn (array $part) => $part['text'] === 'BAC-20260821-0001'
                && $part['emphasized'] === true,
        ));
    }

    public function test_grooming_schedule_notifications_keep_their_event_time_snapshots(): void
    {
        DB::table('time_windows')->insert([
            'window_id' => 2,
            'window_label' => '2',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);
        DB::table('bookings')->where('booking_id', 1)->update(['window_id' => 2]);

        $originalMessage = 'New Pre-registration BAC-20260821-0001 by Gerald Senining on 2026-08-21 at 8:00 AM - 9:00 AM.';
        $rescheduledMessage = 'Pre-registration BAC-20260821-0001 was rescheduled by Gerald Senining from Aug 21 at 8:00 AM - 9:00 AM to Aug 21 at 10:00 AM - 11:00 AM.';

        $this->insertNotification(
            'booked',
            false,
            bookingId: 1,
            createdAt: now()->subMinute(),
            message: $originalMessage,
        );
        $this->insertNotification(
            'rescheduled',
            false,
            bookingId: 1,
            message: $rescheduledMessage,
        );

        $notifications = $this->notificationPayload(['mode' => 'full'])['notifications'];
        $booked = collect($notifications)->firstWhere('type', 'booked');
        $rescheduled = collect($notifications)->firstWhere('type', 'rescheduled');

        $this->assertSame(
            'New pre-registration BAC-20260821-0001 by Gerald Senining on Aug 21 at 8:00 AM - 9:00 AM.',
            $booked['message'],
        );
        $this->assertStringNotContainsString('10:00 AM', $booked['message']);
        $this->assertSame($rescheduledMessage, $rescheduled['message']);
        $this->assertStringContainsString(
            'from Aug 21 at 8:00 AM - 9:00 AM',
            $rescheduled['message'],
        );
        $this->assertStringContainsString(
            'to Aug 21 at 10:00 AM - 11:00 AM',
            $rescheduled['message'],
        );
    }

    public function test_staff_clinic_cancellation_creates_a_cancellation_notification(): void
    {
        $request = Request::create('/api/admin/clinic-appointments/1/cancel', 'POST');
        $request->setUserResolver(fn () => new User([
            'role' => 'staff', 'staff_type' => 'clinic', 'staff_subrole' => 'clinic_receptionist',
        ]));
        $response = (new AdminClinicController)->cancel(
            $request,
            1,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('clinic_appointments', [
            'id' => 1,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => 'clinic_cancelled',
            'booking_id' => null,
            'clinic_appointment_id' => 1,
            'is_read' => false,
        ]);

        $cancellations = $this->notificationPayload([
            'mode' => 'full',
            'category' => 'cancellations',
        ]);

        $this->assertCount(1, $cancellations['notifications']);
        $this->assertSame('Clinic visit cancelled', $cancellations['notifications'][0]['title']);
    }

    public function test_service_and_activity_filters_are_independent_and_combinable(): void
    {
        foreach (['booked', 'rescheduled', 'payment_due', 'payment_confirmed', 'cancelled'] as $type) {
            $this->insertNotification($type, false, bookingId: 1);
        }
        foreach (['clinic_booked', 'clinic_walk_in', 'clinic_payment_due', 'clinic_payment_confirmed', 'clinic_cancelled'] as $type) {
            $this->insertNotification($type, $type === 'clinic_payment_confirmed', clinicAppointmentId: 1);
        }

        foreach (['clinic', 'grooming'] as $service) {
            $all = $this->notificationPayload(['mode' => 'full', 'service' => $service]);
            $this->assertCount(5, $all['notifications']);
            foreach (['payments' => 2, 'cancellations' => 1, 'pre_registrations' => 1] as $activity => $count) {
                $filtered = $this->notificationPayload(compact('service', 'activity') + ['mode' => 'full']);
                $this->assertCount($count, $filtered['notifications']);
                $this->assertSame($service, $filtered['notifications'][0]['service']);
                $this->assertSame($activity, $filtered['notifications'][0]['activity']);
            }
        }
        $this->assertCount(2, $this->notificationPayload(['mode' => 'full', 'activity' => 'pre_registrations'])['notifications']);
        $unread = $this->notificationPayload(['mode' => 'full', 'status' => 'unread', 'service' => 'clinic', 'activity' => 'payments']);
        $this->assertSame(['clinic_payment_due'], array_column($unread['notifications'], 'type'));
        $this->assertSame(9, $unread['unread_count']);
        $this->assertSame('clinic', $unread['filters']['service']);
        $this->assertSame('payments', $unread['filters']['activity']);
    }

    public function test_count_mode_uses_one_count_query_without_loading_records(): void
    {
        $this->insertNotification('booked', false, bookingId: 1);
        $this->insertNotification('clinic_cancelled', true, clinicAppointmentId: 1);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $payload = $this->notificationPayload(['mode' => 'count']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(1, $payload['unread_count']);
        $this->assertArrayNotHasKey('notifications', $payload);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(*)', $queries[0]['query']);
    }

    public function test_no_show_filters_include_both_services_and_keep_legacy_activity_consumers(): void
    {
        $this->insertNotification('no_show', false, bookingId: 1, message: '');
        $this->insertNotification('clinic_no_show', true, clinicAppointmentId: 1, message: '');
        foreach (['walk_in_registered', 'status_update', 'queue_update'] as $type) {
            $this->insertNotification($type, false, bookingId: 1);
        }
        $this->insertNotification('clinic_walk_in', false, clinicAppointmentId: 1);

        $payload = $this->notificationPayload(['mode' => 'full', 'activity' => 'expired']);
        $this->assertCount(2, $payload['notifications']);
        $this->assertSame(['Pre-registrations', 'Schedule changes', 'Payments', 'Cancellations', 'No show'], array_column($payload['activity_options'], 'label'));
        foreach (['grooming' => 'no_show', 'clinic' => 'clinic_no_show'] as $service => $type) {
            $filtered = $this->notificationPayload(['mode' => 'full', 'service' => $service, 'activity' => 'expired']);
            $this->assertSame([$type], array_column($filtered['notifications'], 'type'));
            $row = $filtered['notifications'][0];
            $this->assertSame('expired', $row['activity']);
            $this->assertSame($service, $row['category']);
            foreach (['No show', ucfirst($service), 'Gerald Senining', 'Mochi', 'Aug 21', 'No check-in recorded'] as $term) {
                $this->assertStringContainsString($term, $row['message']);
            }
            $this->assertSame('history', $row['icon']);
        }
        $unread = $this->notificationPayload(['mode' => 'full', 'status' => 'unread', 'activity' => 'expired']);
        $this->assertSame(['no_show'], array_column($unread['notifications'], 'type'));
        $this->assertSame(5, $unread['unread_count']);
        $this->assertCount(6, $this->notificationPayload(['mode' => 'full'])['notifications']);
        $this->assertCount(4, $this->notificationPayload(['service' => 'grooming'])['notifications']);
        $this->assertCount(2, $this->notificationPayload(['service' => 'clinic'])['notifications']);
        $this->assertCount(2, $this->notificationPayload(['activity' => 'walk_ins'])['notifications']);
        $this->assertCount(2, $this->notificationPayload(['activity' => 'status_updates'])['notifications']);
    }

    public function test_read_mutations_report_the_authoritative_shared_unread_count(): void
    {
        $this->insertNotification('booked', false, bookingId: 1);
        $this->insertNotification('clinic_booked', false, clinicAppointmentId: 1);
        $controller = new NotificationController;
        $this->assertSame(1, $controller->markRead(1)->getData(true)['unread_count']);
        $this->assertSame(1, $controller->markRead(1)->getData(true)['unread_count']);
        $this->assertSame(0, $controller->markAllRead()->getData(true)['unread_count']);
        $this->assertSame(0, $this->notificationPayload(['mode' => 'count'])['unread_count']);
    }

    public function test_no_show_snapshots_remain_readable_after_related_records_are_deleted(): void
    {
        $message = 'No show — Grooming: Gerald Senining · Mochi. Pre-registration for Aug 21 · No check-in recorded';
        $this->insertNotification('no_show', false, bookingId: 1, message: $message);
        $this->insertNotification('clinic_no_show', false, clinicAppointmentId: 1, message: str_replace('Grooming', 'Clinic', $message));
        DB::table('bookings')->delete();
        DB::table('clinic_appointments')->delete();
        $this->insertNotification('no_show', true, message: 'Historical no show record.');
        $rows = $this->notificationPayload(['mode' => 'full', 'activity' => 'expired'])['notifications'];
        $this->assertCount(3, $rows);
        $this->assertSame('Historical no show record.', $rows[0]['message']);
        $this->assertSame(str_replace('Grooming', 'Clinic', $message), $rows[1]['message']);
        $this->assertSame($message, $rows[2]['message']);
    }

    public function test_invalid_service_or_activity_is_rejected(): void
    {
        foreach (['service' => 'payments', 'activity' => 'appointments'] as $field => $value) {
            try {
                $this->notificationPayload([$field => $value]);
                $this->fail("Invalid {$field} should be rejected");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    /** @param array<string, mixed> $filters */
    private function notificationPayload(array $filters): array
    {
        $request = Request::create('/api/admin/notifications', 'GET', $filters);

        return (new NotificationController)->index($request)->getData(true);
    }

    private function insertNotification(
        string $type,
        bool $isRead,
        ?int $bookingId = null,
        ?int $clinicAppointmentId = null,
        ?Carbon $createdAt = null,
        ?string $message = null,
    ): void {
        DB::table('notifications')->insert([
            'type' => $type,
            'booking_id' => $bookingId,
            'clinic_appointment_id' => $clinicAppointmentId,
            'message' => $message ?? match ($type) {
                'booked' => 'New pre-registration BAC-20260821-0001 by Gerald Senining on Aug 21 at 8:00 AM - 9:00 AM.',
                default => "Stored {$type} notification.",
            },
            'is_read' => $isRead,
            'created_at' => ($createdAt ?? now())->toDateTimeString(),
        ]);
    }
}
