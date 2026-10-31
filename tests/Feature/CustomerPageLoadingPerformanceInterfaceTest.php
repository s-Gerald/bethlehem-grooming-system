<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerPageLoadingPerformanceInterfaceTest extends TestCase
{
    public function test_dashboard_defers_nonessential_work_and_guards_background_refreshes(): void
    {
        $dashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));

        foreach ([
            'function scheduleCustomerDashboardIdleTask(task)',
            'let notificationsLoading = false;',
            'let appointmentsLoading = false;',
            'let groomingCapacityLoading = false;',
            'document.visibilityState === "visible"',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $dashboard);
        }
    }

    public function test_my_pets_loads_the_visible_collection_then_prefetches_and_caches_the_other(): void
    {
        $myPets = file_get_contents(base_path('scripts/components/my-pets.js'));

        foreach ([
            'let activePetsCache = null;',
            'let archivedPetsCache = null;',
            'let petCollectionGeneration = 0;',
            'async function loadPetCollection(archived, { force = false } = {})',
            'if (!force && cachedPets !== null && shared?.fresh) return cachedPets;',
            'API.loadCustomerData(endpoint,',
            'function schedulePetCollectionPrefetch()',
            'loadPetCollection(!showingArchived)',
            'function invalidatePetCollections()',
            'if (showingArchived !== archived) return;',
            'let notificationsLoaded = false;',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $myPets);
        }

        $this->assertStringNotContainsString(
            'const [activeData, archivedData] = await Promise.all([',
            $myPets,
        );
    }

    public function test_grooming_history_prioritizes_history_and_reuses_the_loaded_result(): void
    {
        $history = file_get_contents(base_path('pages/client/grooming-history.html'));

        foreach ([
            'let historyLoadState = "idle";',
            'let historyLoadPromise = null;',
            'if (historyLoadState === "loaded") return Promise.resolve(allHistory);',
            'void loadHistory();',
            'scheduleCustomerHistoryIdleTask(loadCustomerProfile)',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $history);
        }
    }

    public function test_settings_and_pet_profile_defer_secondary_customer_data(): void
    {
        $settings = file_get_contents(base_path('scripts/components/client-settings.js'));
        $petProfile = file_get_contents(base_path('scripts/components/pet-details.js'));

        foreach ([
            'let profileLoadPromise = null;',
            'scheduleSettingsIdleTask(loadProfile)',
            'if (profileLoadPromise) return profileLoadPromise;',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $settings);
        }

        foreach ([
            'const petTabLoaders = {',
            'scheduleCustomerTabPrefetch',
            'void loadPet();',
            'scheduleIdleTask(loadProfile)',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $petProfile);
        }
    }

    public function test_shared_customer_controls_do_not_compete_with_first_paint(): void
    {
        $preRegistration = file_get_contents(
            base_path('scripts/components/customer-pre-registration-button.js'),
        );

        foreach ([
            'let accessRequest = null;',
            'window.requestIdleCallback(start, { timeout: 1200 });',
            'window.requestAnimationFrame(scheduleAccessCheck);',
        ] as $optimization) {
            $this->assertStringContainsString($optimization, $preRegistration);
        }
    }

    public function test_customer_pages_share_session_scoped_data_and_background_revalidation(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        foreach ([
            'bethlehem.customer.data.v1',
            'function readCustomerCache(endpoint)',
            'async function loadCustomerData(endpoint, load, render, { force = false } = {})',
            'if (cached) render(cached.data);',
            'if (customerRequests.has(endpoint)) return customerRequests.get(endpoint);',
            'function invalidateCustomerMutation(endpoint)',
            'epoch !== customerCacheEpoch',
            'clearCustomerCache();',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $api);
        }

        foreach ([
            'scripts/components/client-dashboard.js' => '/pets?archived=0',
            'scripts/components/client-settings.js' => '/me',
            'pages/client/grooming-history.html' => '/booking/history',
        ] as $path => $endpoint) {
            $this->assertStringContainsString(
                'API.loadCustomerData("'.$endpoint.'"',
                file_get_contents(base_path($path)),
            );
        }
        $dashboard = file_get_contents(base_path('scripts/components/client-dashboard.js'));
        $this->assertStringContainsString('loadAppointments({ force: true })', $dashboard);
        $this->assertStringContainsString('loadGroomingCapacity({ force: true })', $dashboard);
    }
}
