<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardScriptDependenciesTest extends TestCase
{
    public function test_dashboard_loads_the_shared_dashboard_component(): void
    {
        $page = file_get_contents(base_path('pages/admin/dashboard.html'));

        $dashboardPosition = strpos(
            $page,
            'scripts/components/admin-dashboard.js?',
        );

        $this->assertNotFalse($dashboardPosition);

        $this->assertStringContainsString(
            'scripts/api.js?',
            $page,
        );
    }

    public function test_staff_dashboard_has_a_stable_title_and_keeps_identity_in_the_sidebar(): void
    {
        $page = file_get_contents(base_path('pages/admin/dashboard.html'));
        $sidebar = file_get_contents(base_path('scripts/components/admin-sidebar.js'));

        $this->assertStringContainsString(
            'data-admin-title-staff>Staff Dashboard</span>',
            $page,
        );
        $this->assertStringContainsString(
            "x-text=\"isAdmin ? 'Admin' : staffDisplayName\"",
            $page,
        );
        $this->assertStringContainsString(
            'scripts/components/admin-sidebar.js?',
            $page,
        );
        $this->assertStringContainsString('async loadLoggedInIdentity()', $sidebar);
        $this->assertStringContainsString('API.getStaffIdentity ? API.getStaffIdentity() : API.getMe("staff")', $sidebar);
        $this->assertStringContainsString('this.staffDisplayName = fullName', $sidebar);
    }

    public function test_dashboard_search_groups_minimal_customer_and_pet_results_and_links_to_details(): void
    {
        $page = file_get_contents(base_path('pages/admin/dashboard.html'));
        $schedulesPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $dashboard = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $api = file_get_contents(base_path('scripts/api.js'));
        $customerComponent = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $routes = file_get_contents(base_path('routes/api.php'));

        foreach ([
            'x-model="dashboardSearchQuery"',
            '@input.debounce.300ms="searchDashboard()"',
            '>Customers</p>',
            '>Pets</p>',
            'x-text="customer.name"',
            "'customer-' + customer.recordType + '-' + customer.id",
            "customer.status === 'Unregistered'",
            'x-text="customer.status"',
            'x-text="formatMobileNumber(customer.phone)"',
            'x-text="pet.name"',
            'Owner: <span x-text="pet.ownerName"></span>',
            'x-text="pet.species || \'Not provided\'"',
            "dashboardSearchCustomers.length > 0 ? 'mt-2 border-t border-slate-200 pt-2' : ''",
            'class="divide-y divide-slate-100"',
        ] as $searchUi) {
            $this->assertStringContainsString($searchUi, $page);
            $this->assertStringContainsString($searchUi, $schedulesPage);
        }

        $dashboardSearch = $this->sourceBetween(
            $page,
            '<!-- Shared customer and pet search -->',
            '<!-- End shared customer and pet search -->',
        );
        $schedulesSearch = $this->sourceBetween(
            $schedulesPage,
            '<!-- Shared customer and pet search -->',
            '<!-- End shared customer and pet search -->',
        );

        $this->assertSame($dashboardSearch, $schedulesSearch);
        $this->assertStringContainsString('Search customers, pets, or phone...', $dashboardSearch);
        $this->assertStringContainsString('style="padding-left:3rem;padding-right:1.5rem"', $dashboardSearch);
        $this->assertStringNotContainsString('px-11 py-3', $dashboardSearch);

        foreach ([
            'async searchDashboard()',
            'API.searchAdminDashboard(search)',
            'openDashboardCustomer(customer)',
            'openDashboardPet(pet)',
            'customer_id: String(pet.ownerId)',
            'pet_id: String(pet.id)',
            'record_type: customer.recordType || "registered"',
            'record_type: pet.ownerRecordType || "registered"',
        ] as $searchBehavior) {
            $this->assertStringContainsString($searchBehavior, $dashboard);
        }

        $this->assertStringContainsString('function searchAdminDashboard(search = "")', $api);
        $this->assertStringContainsString('/admin/dashboard/search?', $api);
        $this->assertStringContainsString("Route::get('/admin/dashboard/search'", $routes);
        $this->assertStringContainsString('params.get("pet_id")', $customerComponent);
        $this->assertStringContainsString('openCustomerSearchDestination', $customerComponent);
        $this->assertStringContainsString('params.get("record_type")', $customerComponent);
        $this->assertStringContainsString('API.getUnregisteredCustomerDetails(customerId)', $customerComponent);
        $this->assertStringNotContainsString('>CUSTOMERS</p>', $page);
        $this->assertStringNotContainsString('>PETS</p>', $page);
        $this->assertStringContainsString('scripts/api.js?', $schedulesPage);
        $this->assertStringContainsString('scripts/components/admin-dashboard.js?', $schedulesPage);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
