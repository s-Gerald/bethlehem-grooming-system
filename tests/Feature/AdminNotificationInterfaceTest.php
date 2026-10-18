<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminNotificationInterfaceTest extends TestCase
{
    public function test_notification_dropdowns_use_structured_readable_messages(): void
    {
        foreach (['dashboard.html', 'appointments.html'] as $page) {
            $html = file_get_contents(base_path("pages/admin/{$page}"));

            $this->assertStringContainsString('notif.message_parts', $html);
            $this->assertStringContainsString('notif.context_parts', $html);
            $this->assertStringContainsString('getAdminNotificationIcon(notif.icon)', $html);
            $this->assertStringContainsString("part.emphasized ? 'font-semibold' : ''", $html);
            $this->assertStringContainsString('href="./notifications.html"', $html);
            $this->assertStringNotContainsString('x-text="notif.message"></p>', $html);
            $this->assertStringNotContainsString('font-bold uppercase tracking-wider text-slate-400">Today', $html);
        }
    }

    public function test_see_all_notifications_page_has_the_sidebar_and_requested_controls(): void
    {
        $html = file_get_contents(base_path('pages/admin/notifications.html'));
        $script = file_get_contents(base_path('scripts/components/admin-notifications.js'));
        $sidebar = file_get_contents(base_path('scripts/components/admin-sidebar.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        foreach ([
            'x-data="adminSidebar()"',
            'x-data="adminNotifications()"',
            '>All</button>',
            'Unread',
            'Mark all as read',
            "loadingMore ? 'Loading...' : 'Load More'",
            'getAdminNotificationIcon(notification.icon)',
            'class="admin-custom-select-trigger"',
            'class="admin-custom-select-options"',
            'role="listbox"',
            'role="option"',
            'assets/icons/phosphor.svg?v=admin-icons-20261018#funnel',
            'assets/icons/phosphor.svg?v=admin-icons-20261018#caret-down',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $html);
        }

        $this->assertStringNotContainsString('<select', $html);

        foreach (['All Types', 'Grooming', 'Clinic', 'Payments', 'Cancellations'] as $type) {
            $this->assertStringContainsString("label: \"{$type}\"", $script);
        }

        $this->assertStringContainsString('status: this.statusFilter', $script);
        $this->assertStringContainsString('category: this.categoryFilter', $script);
        $this->assertStringContainsString('page: this.page', $script);
        $this->assertStringContainsString('per_page: 10', $script);
        $this->assertStringContainsString('await API.markAllNotificationsRead()', $script);
        $this->assertStringContainsString('await API.markNotificationRead(', $script);
        $this->assertStringContainsString('toggleCategoryMenu()', $script);
        $this->assertStringContainsString('selectCategory(value)', $script);
        $this->assertStringContainsString('moveCategoryFocus(currentOption, offset)', $script);
        $this->assertStringContainsString('notificationGroups()', $script);
        $this->assertStringContainsString('return "Yesterday"', $script);
        $this->assertStringContainsString('year !== currentYear', $script);
        $this->assertStringContainsString('toLocaleTimeString("en-PH"', $script);
        $this->assertStringContainsString('group in notificationGroups()', $html);
        $this->assertStringNotContainsString('>Earlier</p>', $html);
        $this->assertStringContainsString('"notifications.html": "appointments"', $sidebar);
        $this->assertStringContainsString('params.set("category", filters.category)', $api);
        $this->assertStringContainsString('params.set("page", String(filters.page))', $api);
    }
}
