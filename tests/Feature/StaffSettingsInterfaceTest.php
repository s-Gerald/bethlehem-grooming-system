<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaffSettingsInterfaceTest extends TestCase
{
    public function test_settings_page_exposes_role_specific_staff_views(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));

        foreach ([
            '>SYSTEM SETTINGS</p>',
            '>General</span>',
            '>Security</span>',
            'These settings are managed by an administrator.',
            'x-show="isStaff"',
            'x-show="isAdmin"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $page);
        }
    }

    public function test_obsolete_notification_settings_interface_is_absent(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));

        foreach ([
            '>Notifications</span>',
            "activeSettingsTab === 'notifications'",
            "activeSettingsTab = 'notifications'",
            'Alert Preferences',
            'Pre-register Reminders',
            'Payment Receipt',
            'Active Alerts',
            'Save Notifications',
            'appointmentReminders',
            'paymentReceipt',
            'notification preferences',
        ] as $obsolete) {
            $this->assertStringNotContainsString($obsolete, $page);
        }
    }

    public function test_groomer_control_moved_without_moving_queue_information(): void
    {
        $settings = file_get_contents(base_path('pages/admin/settings.html'));
        $appointments = file_get_contents(base_path('pages/admin/appointments.html'));

        $availability = file_get_contents(base_path('resources/views/admin/availability.html'));
        $this->assertStringContainsString('>Groomers on Duty</h3>', $availability);
        $this->assertStringNotContainsString("activeSettingsTab === 'availability'", $settings);
        $this->assertStringNotContainsString("activeSettingsTab === 'capacity'", $settings);
        $this->assertStringNotContainsString('Advance Pre-register Window', $availability);
        $this->assertStringContainsString('person.on_duty', $availability);
        $this->assertStringNotContainsString('>Groomers on duty</span>', $appointments);
        $this->assertStringContainsString('Queued (', $appointments);
    }

    public function test_availability_page_redirect_preserves_the_legacy_capacity_destination(): void
    {
        $this->get('/pages/admin/availability.html?service=grooming')->assertRedirect('api/admin/availability/page?service=grooming');
    }

    public function test_staff_can_reach_settings_from_the_shared_admin_shell(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));

        $this->assertStringNotContainsString(
            "\"reports.html\",\n    \"settings.html\"",
            $api,
        );
        // Settings is now visible in HTML; the browser sidebar regression verifies
        // Staff access on cold paint and through pending session checks.
    }
}
