<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminNotificationInterfaceTest extends TestCase
{
    public function test_all_management_entry_points_load_one_shared_notification_ui_and_service(): void
    {
        $pages = array_map(fn ($page) => 'pages/admin/'.$page.'.html', [
            'dashboard', 'clients', 'appointments', 'clinic', 'inventory/inventory', 'archive',
            'transactions', 'reports', 'services', 'settings',
        ]);
        $pages = [...$pages, 'resources/views/admin/availability.html', 'resources/views/admin/activity-log.html'];
        foreach ($pages as $page) {
            $html = file_get_contents(base_path($page));
            $this->assertSame(1, substr_count($html, '<admin-notification-bell>'), $page);
            $this->assertStringContainsString('data-notification-mobile', $html);
            $this->assertStringContainsString('css/admin-notifications.css', $html);
            $service = strpos($html, 'scripts/services/admin-notification-service.js');
            $component = strpos($html, 'scripts/components/admin-notifications.js');
            $this->assertNotFalse($service);
            $this->assertNotFalse($component);
            $this->assertLessThan($component, $service);
            $this->assertStringNotContainsString('toggleNotifications()', $html);
        }
        $script = file_get_contents(base_path('scripts/components/admin-notifications.js'));
        foreach (['notification.message_parts', 'notification.context_parts', 'getAdminNotificationIcon(notification.icon)', 'See all notifications', 'aria-expanded', 'keydown.escape', 'click.outside'] as $contract) {
            $this->assertStringContainsString($contract, $script);
        }
        $dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $this->assertStringNotContainsString('loadNotifications()', $dashboard);
    }

    public function test_history_uses_native_independent_filters_and_shared_rows(): void
    {
        $html = file_get_contents(base_path('pages/admin/notifications.html'));
        foreach (['x-data="adminSidebar()"', 'x-data="adminNotifications()"', 'aria-label="Service"', 'aria-label="Notification activity"', 'All Services', 'All Activities', 'Mark all as read', 'Load More', '<admin-notification-row>', 'group in notificationGroups()'] as $contract) {
            $this->assertStringContainsString($contract, $html);
        }
        $this->assertStringNotContainsString('admin-custom-select', $html);
        $this->assertStringNotContainsString('<admin-notification-bell', $html);
        $this->assertStringNotContainsString('admin-notification-back', $html);
        $this->assertStringNotContainsString('aria-current="page"', $html);
        $sidebar = file_get_contents(base_path('scripts/components/admin-sidebar.js'));
        $this->assertStringNotContainsString('"notifications.html": "appointments"', $sidebar);
    }
}
