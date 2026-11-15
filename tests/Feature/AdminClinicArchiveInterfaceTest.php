<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminClinicArchiveInterfaceTest extends TestCase
{
    public function test_clinic_history_keeps_shared_controls_and_uses_visit_rows(): void
    {
        $page = file_get_contents(base_path('pages/admin/archive.html'));
        $this->assertStringContainsString("selectArchiveType('grooming')", $page);
        $this->assertStringContainsString("selectArchiveType('clinic')", $page);
        foreach (['Search history', 'Year', 'Month', 'Sort records', 'group.label', 'clinic-row', 'View Visit'] as $label) {
            $this->assertStringContainsString($label, $page);
        }
        $this->assertStringNotContainsString('View Full Record', $page);
    }

    public function test_clinic_visit_is_an_accessible_encounter_without_profile_or_audit_sections(): void
    {
        $page = file_get_contents(base_path('pages/admin/archive.html'));
        $modal = substr($page, strpos($page, '<!-- Read-only historical encounter -->'));
        foreach (['role="dialog"', 'aria-modal="true"', 'aria-labelledby="clinic-visit-title"', 'Clinic Record', 'Visit Summary', 'Vitals',
            'Care Provided', 'Clinic Services', 'Medical Record Attachments', 'openClinicAttachment(file, $event.currentTarget)',
            'Follow-up &amp; Discharge', 'clinicHasPayment(patient.visit)', 'trapClinicFocus($event)'] as $content) {
            $this->assertStringContainsString($content, $modal);
        }
        foreach (['Not recorded', 'Data is currently unavailable', 'Owner and Pet Information', 'Appointment Details',
            'Record Activity', 'Signed corrections', 'queue_number', 'payment_method', 'transaction_reference'] as $content) {
            $this->assertStringNotContainsString($content, $modal);
        }
        $this->assertStringContainsString('clinicSummaryFields(patient.visit).length || clinicMedicalAttachments(patient.visit, patient.care).length', $modal);
        $this->assertStringContainsString('x-show="clinicVitals(patient.visit).length"', $modal);
        $this->assertStringContainsString('x-show="clinicHasCare(patient.visit, patient.care)"', $modal);
    }

    public function test_history_and_current_clinic_use_the_same_attachment_preview(): void
    {
        foreach (['archive', 'clinic'] as $page) {
            $this->assertStringContainsString('scripts/components/clinic-attachments.js', file_get_contents(base_path("pages/admin/{$page}.html")));
        }
    }
}
