<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminCustomersCardTypographyInterfaceTest extends TestCase
{
    public function test_customer_cards_follow_the_clinic_archive_typography_hierarchy(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        $this->assertStringContainsString(
            '<h4 class="text-xl font-bold text-[#1f3850]" x-text="customer.fullName"></h4>',
            $page,
        );
        $this->assertStringContainsString(
            '<h3 class="truncate text-2xl font-bold" x-text="detailModal.customer?.fullName || \'Customer Details\'"></h3>',
            $page,
        );
        $this->assertStringContainsString(
            '<h4 class="text-lg font-semibold text-[#1f3850]">Pets</h4>',
            $page,
        );
        $this->assertStringContainsString(
            '<h5 class="text-base font-medium text-[#1f3850]" x-text="formatTextValue(pet.petName)"></h5>',
            $page,
        );
        $this->assertStringContainsString(
            '<span x-show="pet.breed"> · </span>',
            $page,
        );
    }

    public function test_customer_detail_card_labels_use_normal_case_and_medium_weight(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        foreach (['First name', 'Last name', 'Phone number', 'Email address', 'Weight', 'Color'] as $label) {
            $this->assertStringContainsString(
                sprintf('<p class="text-xs font-medium text-slate-500">%s</p>', $label),
                $page,
            );
        }

        $this->assertStringNotContainsString(
            '<p class="text-xs font-medium text-slate-500">Account status</p>',
            $page,
        );
        $this->assertStringContainsString(
            'x-text="customerStatusLabel(detailModal.customer)"',
            $page,
        );

        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">First Name</p>',
            $page,
        );
        $this->assertStringNotContainsString(
            '<p class="text-xs font-bold uppercase tracking-widest text-slate-400">Weight</p>',
            $page,
        );
    }

    public function test_account_actions_are_below_pets_in_customer_details_modal(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $ownerCard = $this->sourceBetween(
            $page,
            '<!-- Customer Cards -->',
            'x-show="confirmModal.open"',
        );
        $detailsModal = $this->sourceBetween(
            $page,
            '<!-- Customer Details Modal -->',
            'x-show="isAdmin && resetModal.open"',
        );

        $this->assertStringNotContainsString('<!-- Owner actions footer -->', $ownerCard);
        $this->assertStringNotContainsString('openResetPassword(customer)', $ownerCard);
        $this->assertStringNotContainsString("confirmAction('deactivate', customer)", $ownerCard);

        $this->assertStringContainsString('<!-- Account actions below the registered pets -->', $detailsModal);
        $this->assertStringContainsString('<footer x-show="detailModal.customer?.recordType !== \'unregistered\'" aria-label="Account actions"', $detailsModal);
        $this->assertStringContainsString(
            '<h4 class="mb-3 text-lg font-semibold text-[#1f3850]">Account actions</h4>',
            $detailsModal,
        );
        $this->assertStringNotContainsString('divide-y divide-slate-200', $detailsModal);

        foreach ([
            'Help this customer regain access to their account.',
            "Temporarily disable this customer's account.",
            'Move this customer to archived records.',
        ] as $guide) {
            $this->assertStringContainsString($guide, $detailsModal);
        }

        foreach ([
            'openResetPassword(detailModal.customer)',
            "confirmAction('deactivate', detailModal.customer)",
            "confirmAction('archive', detailModal.customer)",
            "confirmAction('reactivate', detailModal.customer)",
            "confirmAction('unarchive', detailModal.customer)",
        ] as $action) {
            $this->assertStringContainsString($action, $detailsModal);
        }

        $petsPosition = strpos($detailsModal, '>Pets</h4>');
        $actionsPosition = strpos($detailsModal, '<!-- Account actions below the registered pets -->');

        $this->assertNotFalse($petsPosition);
        $this->assertNotFalse($actionsPosition);
        $this->assertGreaterThan($petsPosition, $actionsPosition);

        $registeredActions = $this->sourceBetween(
            $detailsModal,
            '<!-- Account actions below the registered pets -->',
            'x-show="detailModal.customer?.recordType === \'unregistered\'"',
        );
        $archivePosition = strpos($registeredActions, "confirmAction('archive', detailModal.customer)");
        $deactivatePosition = strpos($registeredActions, "confirmAction('deactivate', detailModal.customer)");
        $resetPosition = strpos($registeredActions, 'openResetPassword(detailModal.customer)');

        foreach ([$archivePosition, $deactivatePosition, $resetPosition] as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertLessThan($deactivatePosition, $archivePosition);
        $this->assertLessThan($resetPosition, $deactivatePosition);
        $this->assertStringNotContainsString('Delete Account', $detailsModal);

        $styles = file_get_contents(base_path('css/custom.css'));
        $this->assertStringContainsString('.btn-deactivate       { border-color: #f2c46d;', $styles);
        $this->assertStringNotContainsString('.btn-delete-account {', $styles);

        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));

        $this->assertStringContainsString('if (this.detailModal.open) this.closeCustomerDetails();', $component);
        $this->assertStringContainsString('admin-customers.js?v=customer-actions-20260927', $page);
    }

    public function test_customer_delete_action_is_absent_from_frontend(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        $this->assertStringNotContainsString('Delete Account', $page);
        $this->assertStringNotContainsString("confirmAction('delete'", $page);
        $this->assertStringNotContainsString("confirmAction('delete_unregistered'", $page);
        $this->assertStringNotContainsString('deleteConfirmationMatches', $component);
        $this->assertStringNotContainsString('API.deleteCustomer(', $component);
        $this->assertStringNotContainsString('API.deleteUnregisteredCustomer(', $component);
        $this->assertStringNotContainsString('function deleteCustomer(', $api);
        $this->assertStringNotContainsString('function deleteUnregisteredCustomer(', $api);
    }

    public function test_customer_search_separates_pet_results_and_opens_the_matching_pet_details(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $petSearchResults = $this->sourceBetween(
            $page,
            '<!-- Pet search results -->',
            'x-show="confirmModal.open"',
        );

        foreach ([
            '>Customers</h3>',
            '>Pets</h3>',
            "x-text=\"'(' + customers.length + ')'\"",
            "x-text=\"'(' + petSearchResults.length + ')'\"",
            '@click="openPetSearchResult(pet)"',
            'Owner: <span class="font-medium text-slate-700" x-text="pet.ownerName"></span>',
            'x-text="formatMobileNumber(pet.ownerPhone)"',
            'x-text="formatTextValue(pet.ownerEmail)"',
            'placeholder="Search customer, pet, or phone..."',
        ] as $searchUi) {
            $this->assertStringContainsString($searchUi, $page);
        }

        foreach (['cat', 'dog'] as $icon) {
            $this->assertMatchesRegularExpression(
                '/<svg\b[^>]*>\s*<use\b[^>]*href="[^"#]*assets\/icons\/phosphor\.svg(?:\?[^"#]*)?#'.$icon.'"/',
                $petSearchResults,
            );
        }

        foreach ([
            'petSearchResults: []',
            'this.petSearchResults = data.pets || [];',
            'async openPetSearchResult(result)',
            'await this.openCustomerDetails({',
            'this.openPetDetail(pet);',
        ] as $searchBehavior) {
            $this->assertStringContainsString($searchBehavior, $component);
        }

        $this->assertStringNotContainsString('>CUSTOMERS</h3>', $page);
        $this->assertStringNotContainsString('>PETS</h3>', $page);
        $this->assertSame(1, substr_count($petSearchResults, '&middot;'));
    }

    public function test_pet_view_details_matches_the_customer_pet_profile_ui_without_notifications(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $petModal = $this->sourceBetween(
            $page,
            '<!-- Pet Detail Modal -->',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        $this->assertStringContainsString(
            'class="truncate text-2xl font-bold"',
            $petModal,
        );
        foreach ([
            '<span>Overview</span>',
            '<span>Grooming Records</span>',
            '<span>Medical Records</span>',
            '<span>Vaccinations</span>',
            '>Basic Information</h3>',
            '>Clinic Medical Records</h3>',
            '>Vaccination History</h3>',
            'petOverviewDetails()',
            'petModal.groomingRecords',
            'petModal.medicalRecords',
            'petModal.vaccinations',
        ] as $profileUi) {
            $this->assertStringContainsString($profileUi, $petModal);
        }

        $this->assertStringContainsString('mirrors the customer My Pets profile', $petModal);
        $this->assertStringNotContainsString('<span>Notifications</span>', $petModal);
        $this->assertStringNotContainsString('data-lucide="bell"', $petModal);
    }

    public function test_pet_basic_information_omits_duplicate_status_and_shows_neutered_date(): void
    {
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $overviewDetails = $this->sourceBetween(
            $component,
            'petOverviewDetails() {',
            'groomingStatusLabel(status) {',
        );

        $this->assertStringNotContainsString('Profile Status', $overviewDetails);
        $this->assertStringContainsString(
            'this.formatPetNeuteredStatus(pet.isNeutered, pet.neuteredDate)',
            $overviewDetails,
        );
        $this->assertStringContainsString('? `${status} \u00B7 ${this.formatDate(date)}`', $component);
    }

    public function test_owner_and_pet_detail_modals_share_a_wide_bounded_size(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $ownerModal = $this->sourceBetween(
            $page,
            '<!-- Customer Details Modal -->',
            'x-show="isAdmin && resetModal.open"',
        );
        $petModal = $this->sourceBetween(
            $page,
            '<!-- Pet Detail Modal -->',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        foreach ([$ownerModal, $petModal] as $modal) {
            $this->assertStringContainsString('max-w-8xl', $modal);
            $this->assertStringContainsString('px-6 py-6 sm:px-12 md:px-16', $modal);
            $this->assertStringContainsString('style="height:90vh"', $modal);
        }

        $this->assertStringNotContainsString('max-w-5xl', $ownerModal);
        $this->assertStringNotContainsString('max-width:42rem', $petModal);
    }

    public function test_admin_pet_edit_reuses_customer_custom_fields_and_connected_behavior(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $controller = file_get_contents(base_path('app/Http/Controllers/PetController.php'));
        $petForm = file_get_contents(base_path('pages/admin/pet-edit-form.html'));
        $this->assertStringContainsString('x-ref="petEditForm"', $page);

        foreach ([
            'adminPetSpeciesCombobox',
            'adminPetGenderCombobox',
            'adminPetBreedCombobox',
            'adminPetSizeCombobox',
            'adminPetFurTypeCombobox',
        ] as $combobox) {
            $this->assertStringContainsString("id=\"{$combobox}\"", $petForm);
        }

        $this->assertStringNotContainsString('<select', $petForm);
        $this->assertStringNotContainsString('font-bold uppercase', $petForm);

        foreach ([
            'import("./breed-combobox.js?',
            'import("./breed-coat-combobox.js?',
            'import("./fixed-option-combobox.js")',
            'import("./pet-weight-size.js?',
            'tools.createBreedCombobox({',
            'tools.createBreedCoatCombobox({',
            'tools.createFixedOptionCombobox({',
            'tools.getSizeForWeight(',
            'tools.getWeightValidationMessage(',
            'tools.syncPetWeightAndSize(',
            'tools.normalizePetSize(',
        ] as $sharedBehavior) {
            $this->assertStringContainsString($sharedBehavior, $component);
        }

        foreach (['new ValidPetSize', 'new ValidBreedCoat', 'new ValidPetWeight', 'PetWeightSize::withComputedSize($data)'] as $rule) {
            $this->assertStringContainsString($rule, $controller);
        }
    }

    public function test_customer_status_tabs_do_not_repeat_the_header_count(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $statusTabs = $this->sourceBetween(
            $page,
            '<!-- Status Tabs -->',
            '<!-- Tab description -->',
        );

        foreach (['Active', 'Unregistered', 'Inactive', 'Archive'] as $label) {
            $this->assertMatchesRegularExpression(
                '/>\s*'.preg_quote($label, '/').'\s*<\/button>/',
                $statusTabs,
            );
        }

        $this->assertStringNotContainsString('totalCount', $statusTabs);
    }

    public function test_active_customer_helper_explains_online_account_access(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        $this->assertStringContainsString(
            '<strong>Active customers</strong> have an <strong>online account</strong> and access to the <strong>Customer Dashboard</strong>.',
            $page,
        );
        $this->assertStringNotContainsString(
            'You can <strong>Deactivate</strong> to temporarily block their access',
            $page,
        );
    }

    public function test_unregistered_customer_helper_explains_lack_of_online_access(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        $this->assertStringContainsString(
            '<strong>Unregistered customers</strong> are records without an <strong>online account</strong> or access to the <strong>Customer Dashboard</strong>.',
            $page,
        );
        $this->assertStringNotContainsString(
            'These customers were added without creating an online account. Use <strong>Add Customer</strong>',
            $page,
        );
    }

    public function test_unregistered_tab_and_add_customer_use_a_separate_customers_ui(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));
        $component = file_get_contents(base_path('scripts/components/admin-customers.js'));
        $api = file_get_contents(base_path('scripts/api.js'));
        $addCustomerModal = $this->sourceBetween(
            $page,
            '<!-- Add Customer Modal -->',
            '<!-- Customer Details Modal -->',
        );

        foreach ([
            "@click=\"setStatus('unregistered')\"",
            'No unregistered customers yet.',
            'Customers added without an online account will appear here.',
            '@click="openAddCustomerModal()"',
            'customer.recordType === \'unregistered\'',
        ] as $unregisteredUi) {
            $this->assertStringContainsString($unregisteredUi, $page);
        }

        foreach ([
            'id="addCustomerFirstName"',
            'id="addCustomerLastName"',
            'id="addCustomerMiddleName"',
            'id="addCustomerPhone"',
            'id="addCustomerEmail"',
            '@submit.prevent="submitUnregisteredCustomer()"',
            'Create a customer record without an online account.',
        ] as $formUi) {
            $this->assertStringContainsString($formUi, $addCustomerModal);
        }

        $this->assertStringNotContainsString('walkInOwnerForm', $addCustomerModal);
        $this->assertStringNotContainsString('uppercase tracking', $addCustomerModal);
        $this->assertStringContainsString('async submitUnregisteredCustomer(confirmSimilarName = false)', $component);
        $this->assertStringContainsString('API.createUnregisteredCustomer(payload)', $component);
        $this->assertStringContainsString('return "Unregistered";', $component);
        $this->assertStringContainsString('async function createUnregisteredCustomer(payload)', $api);
        $this->assertStringContainsString('/admin/customers/unregistered', $api);
        $this->assertStringNotContainsString('Please review the highlighted information.', $component);

        $activePosition = strpos($page, "setStatus('active')");
        $unregisteredPosition = strpos($page, "setStatus('unregistered')");
        $inactivePosition = strpos($page, "setStatus('inactive')");
        $archivePosition = strpos($page, "setStatus('archived')");
        $this->assertLessThan($unregisteredPosition, $activePosition);
        $this->assertLessThan($inactivePosition, $unregisteredPosition);
        $this->assertLessThan($archivePosition, $inactivePosition);

        foreach ([
            'Similar customer found',
            'submitUnregisteredCustomer(true)',
            "confirmAction('archive_unregistered', detailModal.customer)",
            'openAddPetForCustomer(detailModal.customer)',
            'API.adminAddCustomerPet(',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $page.$component);
        }
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
