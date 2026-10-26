<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerPreRegistrationAccessInterfaceTest extends TestCase
{
    public function test_customer_dashboard_and_both_service_choices_share_the_access_check(): void
    {
        $dashboard = file_get_contents(base_path('pages/client/dashboard.html'));
        $buttonScript = file_get_contents(base_path('scripts/components/customer-pre-registration-button.js'));
        $choicePage = file_get_contents(base_path('pages/client/pre-register.html'));
        $choiceScript = file_get_contents(base_path('scripts/components/pre-registration-options.js'));

        $this->assertStringContainsString('id="preRegisterButton"', $dashboard);
        $this->assertStringContainsString('data-pre-registration-button', $dashboard);
        $this->assertStringContainsString('API.getPreRegistrationAccess()', $buttonScript);
        $this->assertStringNotContainsString('preRegistrationAccessNotice', $dashboard);
        $this->assertStringContainsString('"Pre-registration ongoing"', $buttonScript);
        $this->assertStringContainsString('event.preventDefault()', $buttonScript);
        $this->assertStringContainsString('id="groomingOption"', $choicePage);
        $this->assertStringContainsString('id="clinicVisitOption"', $choicePage);
        $this->assertStringContainsString(
            'setPreRegistrationLinkAccess(groomingOption, access.allowed, access.message)',
            $choiceScript,
        );
        $this->assertStringContainsString(
            'setPreRegistrationLinkAccess(clinicVisitOption, access.allowed, access.message)',
            $choiceScript,
        );
    }

    public function test_old_device_local_twenty_four_hour_limiter_is_removed(): void
    {
        $guard = file_get_contents(base_path('scripts/services/booking-form-access-guard.js'));
        $consent = file_get_contents(base_path('scripts/components/booking-consent-step.js'));

        $this->assertStringNotContainsString('bookingFormLock', $guard);
        $this->assertStringNotContainsString('24 * 60 * 60 * 1000', $guard);
        $this->assertStringNotContainsString('setBookingFormLock', $consent);
        $this->assertStringContainsString('API.getPreRegistrationAccess()', $guard);
        $this->assertStringContainsString('API.getPreRegistrationAccess({ force: true })', $guard);
    }

    public function test_both_direct_entry_pages_recheck_server_access(): void
    {
        $groomingFlow = file_get_contents(base_path('scripts/components/grooming-pre-registration.js'));
        $clinicFlow = file_get_contents(base_path('scripts/components/booking-pet-step.js'));

        $this->assertStringContainsString('await initBookingFormAccessGuard', $groomingFlow);
        $this->assertStringContainsString('await initBookingFormAccessGuard', $clinicFlow);
    }

    public function test_grooming_history_uses_the_same_guarded_pre_registration_button(): void
    {
        $history = file_get_contents(base_path('pages/client/grooming-history.html'));

        $this->assertStringContainsString('data-pre-registration-button', $history);
        $this->assertStringContainsString('aria-disabled="true"', $history);
        $this->assertStringContainsString('customer-pre-registration-button.js', $history);
    }
}
