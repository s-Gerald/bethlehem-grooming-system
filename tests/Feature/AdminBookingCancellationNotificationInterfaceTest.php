<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminBookingCancellationNotificationInterfaceTest extends TestCase
{
    public function test_grooming_cancel_modal_requires_a_reason_for_booking_and_pet(): void
    {
        $appointments = file_get_contents(base_path('pages/admin/appointments.html'));

        foreach ([
            "x-show=\"['cancel', 'cancelPet'].includes(actionConfirmModal.action)\"",
            'for="bookingCancellationReason"',
            'Cancellation reason',
            ':required=',
            'id="bookingCancellationReason"',
            'x-model="actionConfirmModal.cancellationReason"',
            'maxlength="500"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $appointments);
        }

        $this->assertStringNotContainsString(
            'If left blank, the customer will be told that the grooming online registration was cancelled by the clinic.',
            $appointments,
        );
        $this->assertStringNotContainsString(
            'class="block text-xs font-bold uppercase',
            $appointments,
        );
    }

    public function test_reason_is_forwarded_through_the_shared_api_layer(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        $dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));

        foreach ([
            'async function adminCancelBooking(bookingId, cancellationReason = "")',
            '{ cancellation_reason: reason || null }',
        ] as $apiContract) {
            $this->assertStringContainsString($apiContract, $api);
        }

        foreach ([
            'cancel: async ({ booking, cancellationReason = "" })',
            'await API.adminCancelBooking(booking.id, cancellationReason);',
            'this.actionConfirmModal.cancellationReason',
            'await this.runBookingAction("cancel", booking, { cancellationReason });',
        ] as $dashboardContract) {
            $this->assertStringContainsString($dashboardContract, $dashboard);
        }
    }
}
