<?php

namespace Tests\Feature;

use Tests\TestCase;

class GroomingReviewAndConsentInterfaceTest extends TestCase
{
    public function test_review_pages_share_price_presentation_and_allow_nonblocking_forecast_feedback(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking-review.html'));
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));
        $pricing = file_get_contents(base_path('scripts/services/grooming-service.js'));

        $this->assertStringContainsString('disclaimer: isEstimate ? "Final price is confirmed at the clinic." : ""', $pricing);

        foreach ([$customerPage, $walkInPage] as $page) {
            $this->assertMatchesRegularExpression(
                '/id="reviewNotice"\s+class="[^"]*hidden/',
                $page,
            );
        }

        foreach ([$customerReview, $walkInReview] as $review) {
            $this->assertStringContainsString('getGroomingPricePresentation(state.reviewPayload.totalPricing)', $review);
            $this->assertStringContainsString('elements.reviewNotice.textContent = [price.disclaimer, estimateNotice].filter(Boolean).join(" ");', $review);
            $this->assertStringContainsString('${price.isEstimate || estimateNotice ? "mb-6" : "hidden"}', $review);
        }

        $this->assertStringContainsString('const hasDisplayedPlusPrice = items.some', $pricing);
        $this->assertStringContainsString('priceOption.pricingType === "plus"', $pricing);
        $this->assertStringNotContainsString('needsClinicPriceConfirmation', $pricing);

        foreach ([
            'This booking contains estimated pricing details.',
            'Estimate only: one or more pets do not have a matching saved size yet',
            'Prices with a + are minimum clinic rates.',
            'This pet does not have a saved size yet, so the package total is shown as an estimate.',
        ] as $removedCopy) {
            $this->assertStringNotContainsString($removedCopy, $customerReview.$walkInReview.$pricing);
        }
    }

    public function test_review_pages_only_show_the_matching_package_size_when_available(): void
    {
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));

        foreach ([$customerReview, $walkInReview] as $review) {
            $this->assertStringContainsString('function getVisiblePackagePriceOptions(selectedPackage, petSize)', $review);
            $this->assertStringContainsString('const normalizedSize = normalizePetSize(petSize);', $review);
            $this->assertStringContainsString('return selectedPackage.priceOptions;', $review);
            $this->assertStringContainsString(
                '(priceOption) => priceOption.sizeKey === normalizedSize',
                $review,
            );
            $this->assertStringContainsString('${visiblePriceOptions', $review);
            $this->assertStringNotContainsString('getPackagePricingNote', $review);
        }
    }

    public function test_review_summary_removes_the_duplicate_pet_summary_card(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $walkInPage = file_get_contents(base_path('pages/admin/walk-in-booking-review.html'));
        $customerReview = file_get_contents(base_path('scripts/components/booking-review-step.js'));
        $walkInReview = file_get_contents(base_path('scripts/components/walk-in-review-step.js'));

        foreach ([$customerPage, $walkInPage, $customerReview, $walkInReview] as $source) {
            $this->assertStringNotContainsString('Pet Summary', $source);
            $this->assertStringNotContainsString('petReviewText', $source);
        }

        $this->assertStringContainsString('Registration Summary', $customerPage);
        $this->assertStringContainsString('Schedule Summary', $walkInPage);
        $this->assertStringContainsString('Schedule Summary', $walkInReview);
    }

    public function test_time_and_consent_helpers_are_removed_and_step_five_heading_is_unique(): void
    {
        $customerPage = file_get_contents(base_path('pages/client/grooming-pre-registration.html'));
        $customerConsent = explode('data-grooming-step="confirmed"', explode('data-grooming-step="consent"', $customerPage, 2)[1], 2)[0];
        $walkInConsent = file_get_contents(base_path('pages/admin/walk-in-consent.html'));
        $walkInConsentStep = file_get_contents(base_path('scripts/components/walk-in-consent-step.js'));

        $this->assertStringNotContainsString('data-grooming-step="schedule"', $customerPage);

        foreach ([$customerConsent, $walkInConsent, $walkInConsentStep] as $consent) {
            $this->assertStringContainsString(
                '<h1 class="text-3xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h1>',
                $consent,
            );
            $this->assertStringNotContainsString('Select your Pet Drop-off Date and Time', $consent);
            $this->assertStringNotContainsString(
                '<h2 class="text-2xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h2>',
                $consent,
            );
        }

        $this->assertStringNotContainsString('sedationConsentHelp', $customerConsent);
    }
}
