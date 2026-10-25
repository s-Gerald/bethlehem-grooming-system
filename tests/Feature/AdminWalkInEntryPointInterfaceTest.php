<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminWalkInEntryPointInterfaceTest extends TestCase
{
    public function test_clinic_walk_in_entry_defaults_the_shared_flow_to_clinic(): void
    {
        $clinicPage = file_get_contents(base_path('pages/admin/clinic.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $clinicComponent = file_get_contents(base_path('scripts/components/admin-clinic.js'));

        $this->assertStringContainsString(
            '@click="startWalkIn()"',
            $clinicPage,
        );
        $this->assertStringContainsString(
            '(flowParams.get("flow") || flowParams.get("source")) === "clinic"',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'const isClinic = appointmentType === "clinic";',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'window.location.href = "./walk-in-booking.html?flow=clinic&new=1";',
            $clinicComponent,
        );
        $this->assertSame(
            1,
            substr_count($walkInPage, 'id="walkInOwnerBackLink"'),
        );
        $this->assertStringContainsString(
            'document.getElementById("walkInOwnerBackLink").setAttribute(',
            $ownerStep,
        );
        $this->assertStringContainsString(
            '"href", isClinic ? "./clinic.html" : "./appointments.html",',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'return appointmentType;',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'sessionStorage.setItem(WALK_IN_OWNER_STORAGE_KEY, JSON.stringify({ appointmentType }));',
            $ownerStep,
        );
        $this->assertStringContainsString(
            'walk-in-owner-step.js?',
            $walkInPage,
        );
        $this->assertStringContainsString(
            'initializeWalkInFlow();',
            $ownerStep,
        );
    }

    public function test_other_walk_in_entries_keep_grooming_as_the_default(): void
    {
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $appointmentsPage = file_get_contents(base_path('pages/admin/appointments.html'));
        $dashboardComponent = file_get_contents(base_path('scripts/components/admin-dashboard.js'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));

        $this->assertStringNotContainsString(
            'id="typeGrooming"',
            $walkInPage,
        );
        $this->assertMatchesRegularExpression('/\?\s*"clinic"\s*:\s*"grooming";/', $ownerStep);
        $this->assertSame(
            1,
            substr_count($walkInPage, 'href="./appointments.html"'),
        );
        $this->assertStringContainsString(
            '@click="handleWalkInBooking()"',
            $appointmentsPage,
        );
        $this->assertStringContainsString(
            'walkInBookingUrl: "./walk-in-booking.html?flow=grooming&new=1"',
            $dashboardComponent,
        );
    }

    public function test_active_walk_in_pages_have_a_native_home_link_above_the_heading(): void
    {
        foreach (['walk-in-booking', 'walk-in-pet-details', 'walk-in-grooming-services', 'walk-in-booking-review', 'walk-in-consent'] as $page) {
            $html = file_get_contents(base_path('pages/admin/'.$page.'.html'));
            preg_match('/<main\b[^>]*>(.*?)<h1\b/s', $html, $header);
            preg_match('/<a\b([^>]*)>(.*?)<\/a>/s', $header[1] ?? '', $link);
            $this->assertStringContainsString('href="./dashboard.html"', $link[1] ?? '', $page);
            $this->assertSame('← Back to Home', trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($link[2] ?? '')))), $page);
            $this->assertStringNotContainsString('data-walk-in-back-link', $html, $page);
        }
    }

    public function test_grooming_walk_in_can_select_existing_owners_and_their_pets(): void
    {
        $ownerPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $petPage = file_get_contents(base_path('pages/admin/walk-in-pet-details.html'));
        $petStep = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));
        $servicesStep = file_get_contents(base_path('scripts/components/walk-in-services-step.js'));
        $consentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        foreach ([
            'Find an existing customer',
            'Search active and unregistered customers by name, mobile number, or email.',
            'id="existingCustomerSearch"',
            'placeholder="Enter at least 2 characters"',
            'New owner information',
        ] as $content) {
            $this->assertStringContainsString($content, $ownerPage);
        }

        $this->assertStringNotContainsString(
            'Use this form when the customer is not in the search results.',
            $ownerPage,
        );

        foreach ([
            'API.searchWalkInCustomers(query)',
            'ownerRecordType: selectedCustomer?.recordType || "new"',
            'existingPets: Array.isArray(selectedCustomer?.pets)',
            'window.location.href = "./walk-in-pet-details.html";',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $ownerStep);
        }

        $this->assertStringContainsString('id="existingPetsSection"', $petPage);
        $this->assertStringContainsString('data-select-existing-pet', $petStep);
        $this->assertStringContainsString('petId: pet.id', $petStep);
        $this->assertStringContainsString('...pet,', $servicesStep);
        $this->assertStringContainsString('pet_id:              item.pet.petId || null', $consentStep);
        $this->assertStringContainsString('owner_record_type: owner.ownerRecordType || "new"', $consentStep);
    }

    public function test_clinic_walk_in_can_select_existing_owners_and_their_pets(): void
    {
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $petStep = file_get_contents(base_path('scripts/components/walk-in-pet-step.js'));
        $clinicConsentStep = file_get_contents(base_path('scripts/components/walk-in-clinic-consent-step.js'));
        $ownerPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));

        $this->assertStringContainsString(
            '<section id="existingCustomerSection" class="mb-6',
            $ownerPage,
        );
        $this->assertStringNotContainsString(
            'document.getElementById("typeGrooming").checked = true;',
            $ownerStep,
        );
        $this->assertStringContainsString(
            '`./walk-in-booking.html?flow=${getOwnerAppointmentType()}`',
            $petStep,
        );

        foreach ([
            'owner_record_type: owner.ownerRecordType || "new"',
            'customer_user_id: owner.customerUserId || null',
            'unregistered_customer_id: owner.unregisteredCustomerId || null',
            'pet_id:             state.pet?.petId || null',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $clinicConsentStep);
        }
    }

    public function test_new_owner_validation_uses_a_bottom_left_similar_name_warning(): void
    {
        $ownerPage = file_get_contents(base_path('pages/admin/walk-in-booking.html'));
        $ownerStep = file_get_contents(base_path('scripts/components/walk-in-owner-step.js'));
        $consentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        foreach ([
            'id="similarOwnerWarning"',
            'fixed bottom-5 left-5',
            'Similar customer found',
            'id="reviewSimilarOwnerBtn"',
            'id="continueSimilarOwnerBtn"',
            'Continue anyway',
            'api.js?v=session-inactivity-20260828',
        ] as $content) {
            $this->assertStringContainsString($content, $ownerPage);
        }

        $this->assertStringContainsString('API.validateWalkInNewOwner({', $ownerStep);
        $this->assertStringContainsString('error.code === "similar_customer_name"', $ownerStep);
        $this->assertStringContainsString('showSimilarOwnerWarning(values', $ownerStep);
        $this->assertStringContainsString('confirm_similar_name: Boolean(owner.confirmSimilarName)', $consentStep);
    }
}
