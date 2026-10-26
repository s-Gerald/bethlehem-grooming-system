<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class GroomingSinglePageInterfaceTest extends TestCase
{
    public function test_all_grooming_steps_share_one_page(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));

        foreach (['pets', 'services', 'review', 'consent', 'confirmed'] as $step) {
            $this->assertStringContainsString('data-grooming-step="'.$step.'"', $page);
        }

        $this->assertSame(1, substr_count($page, 'grooming-pre-registration.js'));
        $this->assertStringContainsString('id="bookingConsentForm"', $page);
        $this->assertStringContainsString('<main id="confirmationRoot" class="confirmation-page"></main>', $page);

        preg_match_all('/\bid="([^"]+)"/', $page, $matches);
        $this->assertCount(count(array_unique($matches[1])), $matches[1]);
    }

    public function test_grooming_navigation_stays_on_the_combined_page(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $controller = file_get_contents(base_path('scripts/components/grooming-pre-registration.js'));
        $choice = file_get_contents(base_path('pages/client/pre-register.html'));

        $this->assertStringContainsString('href="./grooming-pre-registration.html"', $choice);
        $this->assertStringContainsString('window.history.pushState', $controller);
        $this->assertStringContainsString('window.addEventListener("popstate"', $controller);
        $this->assertStringContainsString('section.hidden = !visible', $controller);
        $this->assertStringNotContainsString('href="./booking-', $page);
        $this->assertStringNotContainsString('href="./booking.html"', $page);
    }

    public function test_clinic_visit_keeps_its_separate_pet_step(): void
    {
        $clinicDate = file_get_contents(base_path('pages/client/pre-register.html'));
        $petStep = file_get_contents(base_path('scripts/components/booking-pet-step.js'));

        $this->assertStringContainsString('./booking-pet-details.html?flow=clinic', $clinicDate);
        $this->assertStringContainsString('IS_CLINIC_VISIT', $petStep);
    }

    public function test_editable_steps_have_a_top_link_directly_to_client_home(): void
    {
        $page = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));

        foreach (['pets', 'services', 'review', 'consent'] as $step) {
            $section = explode('data-grooming-step="'.$step.'"', $page, 2)[1];
            $section = explode('data-grooming-step="', $section, 2)[0];
            preg_match('/<a\b[^>]*>.*?<\/a>/s', $section, $matches);
            $topLink = $matches[0] ?? '';

            $this->assertStringContainsString('href="./dashboard.html"', $topLink, $step);
            $this->assertStringNotContainsString('data-grooming-target', $topLink, $step);
            $this->assertSame('← Back to Home', trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($topLink)))), $step);
        }

        $this->assertStringContainsString('id="backBtn"', $page);
        foreach (['pets', 'services', 'review'] as $previousStep) {
            $this->assertStringContainsString('data-grooming-target="'.$previousStep.'"', $page);
        }
    }

    public function test_confirmation_renders_details_dashboard_navigation_and_working_print_controls(): void
    {
        $process = new Process(['node', '--input-type=module', '--eval', <<<'JS'
import { initBookingConfirmedStep } from './scripts/components/booking-confirmed-step.js';

let focused = false;
let printCallback;
let afterPrint;
let printed = 0;
const root = { innerHTML: '', querySelector(selector) {
  return selector === '#confirmationHeading'
    ? { focus() { focused = true; } }
    : { addEventListener(event, callback) { if (event === 'click') printCallback = callback; } };
} };
const confirmation = {
  booking_reference: 'BAC-20261016-0001', booking_date: '2026-10-16',
  booking_time: '1:00 PM - 2:00 PM', submitted_at: '2026-10-16T10:00:00+08:00',
  status: 'waiting_to_arrive', owner: { fullName: 'Maria Santos', phone: '09171234567' },
  pets: [{ id: 101, petName: 'Coco', petType: 'dog', breed: 'Shih Tzu', size: 'small', weight: 5 }],
  review: { totalPricing: { minAmount: 250, maxAmount: 250 }, pets: [{ petId: 101,
    selectedServiceNames: ['Partial Grooming'], pricing: { minAmount: 250, maxAmount: 250 },
    groomingEstimate: { formatted: '45 min - 1 hr' }, specialInstructions: 'Keep the face tidy',
  }] },
  consent: { groomingAgreementAccepted: true, digitalSignature: 'Maria Santos' },
};
globalThis.document = { title: '', body: { dataset: {}, classList: { add() {} } },
  getElementById: (id) => id === 'confirmationRoot' ? root : null };
globalThis.sessionStorage = { getItem: (key) => key === 'bookingConfirmation' ? JSON.stringify(confirmation) : null };
globalThis.window = {
  addEventListener(event, callback) { if (event === 'afterprint') afterPrint = callback; },
  removeEventListener() {}, print() { printed++; }, setTimeout() {},
};
initBookingConfirmedStep();
printCallback();
afterPrint();
console.log(JSON.stringify({ html: root.innerHTML, focused, printed, title: document.title }));
JS], base_path());
        $process->mustRun();
        $rendered = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $html = $rendered['html'];

        foreach ([
            'Online Registration Received', 'BAC-20261016-0001', 'Waiting to Arrive',
            'Owner Information', 'Maria Santos', 'Coco', 'Partial Grooming',
            'Registration Details', 'Visit day', 'Queue number', 'Submitted online',
            'Grooming Consent', 'Keep the face tidy',
        ] as $detail) {
            $this->assertStringContainsString($detail, $html);
        }
        $this->assertStringContainsString('<article class="confirmation-document" aria-label="Confirmation details">', $html);
        $this->assertMatchesRegularExpression('/<a[^>]+href="\.\/dashboard\.html">Return to Dashboard<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]+id="printConfirmationButton"[^>]*>Print Confirmation<\/button>/', $html);
        $this->assertStringContainsString('<dt>Queue number</dt><dd>Not assigned</dd>', $html);
        $this->assertTrue($rendered['focused']);
        $this->assertSame(1, $rendered['printed']);
        $this->assertSame('Online Registration Received', $rendered['title']);
    }
}
