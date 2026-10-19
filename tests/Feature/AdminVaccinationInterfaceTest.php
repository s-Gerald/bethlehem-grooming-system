<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminVaccinationInterfaceTest extends TestCase
{
    private string $clinicPage;

    private string $clinicComponent;

    private string $vaccinationComponent;

    private string $apiLayer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $this->clinicComponent = file_get_contents(base_path('scripts/components/admin-clinic.js'));
        $this->vaccinationComponent = file_get_contents(
            base_path('scripts/components/admin-vaccinations.js'),
        );
        $this->apiLayer = file_get_contents(base_path('scripts/api.js'));
    }

    public function test_vaccinations_are_integrated_into_the_selected_patient_record_workflow(): void
    {
        $this->assertStringContainsString('Patient Record', $this->clinicComponent);
        $this->assertStringContainsString('Medical Record', $this->clinicPage);
        $this->assertStringContainsString("selectSection('vaccinations')", $this->clinicPage);
        $this->assertStringContainsString('x-data="adminClinicVaccinations()"', $this->clinicPage);
        $this->assertStringContainsString('Add Treatment', $this->clinicPage);
        $this->assertStringNotContainsString('Record Treatment', $this->clinicPage);
        $this->assertStringContainsString('No treatments recorded for this case.', $this->clinicPage);
        $this->assertMatchesRegularExpression(
            '/<section\b[^>]*aria-labelledby="current-treatment-title"[^>]*:aria-busy="loading"[^>]*>\s*<h3\b[^>]*>Current Visit Treatments<\/h3>\s*<admin-loader\b(?=[^>]*\bx-cloak\b)(?=[^>]*\bx-show="loading")[^>]*>/',
            $this->clinicPage,
        );
        $this->assertStringContainsString('scripts/components/admin-loading.js', $this->clinicPage);
        $loader = file_get_contents(base_path('scripts/components/admin-loading.js'));
        $this->assertStringContainsString("this.setAttribute('role', 'status')", $loader);
        $this->assertStringContainsString("this.setAttribute('aria-live', 'polite')", $loader);
        $this->assertStringContainsString("label.textContent = 'Loading...'", $loader);
        $this->assertStringContainsString('@click="loadVaccinations()"', $this->clinicPage);

        $this->assertStringContainsString(
            'section: section || (item.case_type === "vaccination" ? "vaccinations" : "medical")',
            $this->clinicComponent,
        );
        $this->assertStringContainsString(
            'weight_kg: this.form.weight_kg',
            $this->clinicComponent,
        );
    }

    public function test_central_api_layer_exposes_all_six_vaccination_operations(): void
    {
        foreach ([
            'getAdminPetVaccinations',
            'getAdminPetVaccination',
            'createAdminPetVaccination',
            'updateAdminPetVaccination',
            'publishAdminPetVaccination',
            'voidAdminPetVaccination',
        ] as $helper) {
            $this->assertStringContainsString("async function {$helper}", $this->apiLayer);
            $this->assertMatchesRegularExpression(
                '/\n\s+'.preg_quote($helper, '/').',/',
                $this->apiLayer,
            );
        }

        $this->assertStringContainsString(
            '`/admin/pets/${encodeURIComponent(petId)}/vaccinations${query}`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            '/publish`',
            $this->apiLayer,
        );
        $this->assertStringContainsString(
            '/void`',
            $this->apiLayer,
        );
    }

    public function test_component_uses_only_the_central_api_and_reuses_saved_treatment_responses(): void
    {
        $this->assertStringNotContainsString('fetch(', $this->vaccinationComponent);
        $this->assertStringContainsString(
            'API.getAdminPetVaccinations(requestedPetId, requestedCaseId)',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'API.createAdminPetVaccination(petId, payload)',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'API.updateAdminPetVaccination(',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            'this.records.unshift(saved.vaccination);',
            $this->vaccinationComponent,
        );
        $this->assertStringContainsString(
            '<template x-for="record in records"',
            $this->clinicPage,
        );
    }

    public function test_current_visit_controls_use_edit_remove_and_row_details(): void
    {
        $form = $this->sourceBetween($this->clinicPage, '<!-- Current-case treatment entry -->', '<!-- Vaccination detail dialog -->');
        $this->assertStringContainsString('<table', $form);
        $this->assertStringContainsString('@click="openDetails(record)"', $form);
        $this->assertStringContainsString('@click.stop="openEditForm(record)"', $form);
        $this->assertStringContainsString('@click.stop="removeTreatment(record)"', $form);
        $this->assertStringNotContainsString('publishRecord(', $form);
        $this->assertStringNotContainsString('openVoidDialog(', $form);
        $this->assertStringNotContainsString('stateBadgeClass(', $form);
        $this->assertStringContainsString('deleteAdminPetVaccination', $this->apiLayer.$this->vaccinationComponent);
    }

    public function test_form_mirrors_validation_and_does_not_send_server_managed_fields(): void
    {
        foreach ([
            'Dose amount must be greater than zero.',
            'Enter both Dose Amount and Dose Unit, or leave both blank.',
            'Complete the required fields marked *.',
            'Next vaccination date cannot be before today.',
            'Choose a supported administration route.',
        ] as $message) {
            $this->assertStringContainsString($message, $this->vaccinationComponent);
        }

        $payloadBuilder = $this->sourceBetween(
            $this->vaccinationComponent,
            'buildPayload() {',
            'hasUnfinishedTreatment() {',
        );

        foreach ([
            'pet_id',
            'recorded_by_user_id',
            'published_at',
            'published_by_user_id',
            'voided_at',
            'voided_by_user_id',
        ] as $serverManagedField) {
            $this->assertStringNotContainsString($serverManagedField, $payloadBuilder);
        }
    }

    public function test_vaccine_comes_from_clinic_inventory_search_with_read_only_batch_details(): void
    {
        $form = $this->sourceBetween(
            $this->clinicPage,
            '<!-- Current-case treatment entry -->',
            '<!-- Vaccination detail dialog -->',
        );

        $this->assertStringContainsString('API.getAdminVaccinationOptions()', $this->vaccinationComponent);
        $this->assertStringContainsString('async function getAdminVaccinationOptions', $this->apiLayer);
        $this->assertStringContainsString('"/admin/vaccination-options"', $this->apiLayer);
        $this->assertStringNotContainsString('getAdminInventoryItems', $this->apiLayer.$this->vaccinationComponent);

        $this->assertStringContainsString('x-model="productQuery"', $form);
        $this->assertStringContainsString('productResults', $form);
        $this->assertStringContainsString('productAvailabilityLabel(item)', $form);
        $this->assertStringContainsString('Inventory usage', $form);
        $this->assertStringContainsString('selectedProduct.next_batch?.batch_number', $form);
        $this->assertStringContainsString('selectedProduct.next_batch?.expiry_date', $form);
        $this->assertStringContainsString('Next Vaccination Date', $form);

        $inventoryForm = $this->sourceBetween($form, '<section x-show="recordKind === \'inventory\'"', '<section x-show="recordKind === \'historical\'"');
        foreach ([
            'x-model="form.vaccine_name"',
            'x-model="form.product_name"',
            'x-model="form.manufacturer"',
            'x-model="form.batch_number"',
            'x-model="form.product_expiry_date"',
            'x-model="form.clinic_appointment_id"',
            'x-model="form.administered_by_name"',
            'No inventory link',
            'Stock will not be deducted.',
            'Next Due Date',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $inventoryForm);
        }
    }

    public function test_provider_is_removed_and_payload_carries_only_treatment_facts(): void
    {
        $form = $this->sourceBetween(
            $this->clinicPage,
            '<!-- Current-case treatment entry -->',
            '<!-- Vaccination detail dialog -->',
        );
        $payloadBuilder = $this->sourceBetween(
            $this->vaccinationComponent,
            'buildPayload() {',
            'hasUnfinishedTreatment() {',
        );

        $this->assertStringNotContainsString('administered_by_', $form.$payloadBuilder);
        $this->assertStringNotContainsString('provider in veterinarians', $form);
        $this->assertStringNotContainsString('barcode', $form);
        $this->assertStringContainsString('Quantity used', $form);
        $this->assertStringContainsString('step="1"', $form);

        $this->assertStringContainsString('id="vaccination-administered-date" type="text" readonly', $form);
        $this->assertStringContainsString('id="historical-administered-date" type="date"', $form);
        $this->assertStringContainsString('this.recordKind === "historical" ? {', $payloadBuilder);
        $this->assertStringContainsString('administered_date: nullableText("administered_date")', $payloadBuilder);

        $this->assertStringContainsString('inventory_item_id: this.recordKind === "inventory" ? nullableId("inventory_item_id") : null', $payloadBuilder);
        $this->assertStringContainsString('clinic_appointment_id: this.formModal.clinicAppointmentId', $payloadBuilder);
        foreach ([
            'vaccine_name',
            'product_name',
            'manufacturer',
            'batch_number',
            'product_expiry_date',
        ] as $inventoryManagedField) {
            $this->assertStringContainsString($inventoryManagedField.': nullableText("'.$inventoryManagedField.'")', $payloadBuilder);
        }
    }

    public function test_case_level_save_is_the_only_finalization_boundary(): void
    {
        $this->assertStringContainsString('Send case to payment?', $this->clinicComponent);
        $this->assertStringContainsString('clinic-treatment-prepare', $this->clinicComponent.$this->vaccinationComponent);
        $this->assertStringNotContainsString('publishAdminPetVaccination(', $this->vaccinationComponent);
        $this->assertStringNotContainsString('stockOut(', $this->vaccinationComponent);
        $this->assertStringContainsString('finalizeVisit', file_get_contents(base_path('app/Models/ClinicAppointment.php')));
    }

    public function test_existing_medical_and_customer_vaccination_interfaces_remain_separate(): void
    {
        $clientPage = file_get_contents(base_path('pages/client/pet-details.html'));
        $clientComponent = file_get_contents(base_path('scripts/components/pet-details.js'));

        $this->assertStringContainsString('Medical Record', $this->clinicPage);
        $this->assertStringContainsString('clinicSaveRecord', $this->clinicComponent);
        $this->assertStringContainsString('clinicUploadAttachment', file_get_contents(base_path('scripts/components/admin-clinic-case-workspace.js')));
        $this->assertStringContainsString('data-pet-panel="health"', $clientPage);
        $this->assertStringContainsString(
            'Vaccinations',
            $clientPage,
        );
        $this->assertStringContainsString('API.getPetVaccinations(petId)', $clientComponent);
        $this->assertStringNotContainsString(
            'getAdminPetVaccinations',
            $clientComponent,
        );
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
