<?php

namespace Tests\Feature;

use Tests\TestCase;

class PetInformationVerificationInterfaceTest extends TestCase
{
    private string $myPetsPage;

    private string $myPetsComponent;

    private string $petProfilePage;

    private string $petProfileComponent;

    private string $clientDashboard;

    private string $clientDashboardPage;

    private string $notificationController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myPetsPage = file_get_contents(
            base_path('pages/client/my-pets.html'),
        );
        $this->myPetsComponent = file_get_contents(
            base_path('scripts/components/my-pets.js'),
        );
        $this->petProfilePage = file_get_contents(
            base_path('pages/client/pet-details.html'),
        );
        $this->petProfileComponent = file_get_contents(
            base_path('scripts/components/pet-details.js'),
        );
        $this->clientDashboard = file_get_contents(
            base_path('scripts/components/client-dashboard.js'),
        );
        $this->clientDashboardPage = file_get_contents(
            base_path('pages/client/dashboard.html'),
        );
        $this->notificationController = file_get_contents(
            base_path('app/Http/Controllers/CustomerNotificationController.php'),
        );
    }

    public function test_verified_pet_fields_are_visible_and_protected_by_a_custom_confirmation(): void
    {
        foreach ([
            'clinic_verified_fields',
            'breed: "Breed"',
            'fur_type: "Fur Type"',
            'weight: "Weight"',
            'size: "Size"',
            '>Verified</span>',
            'Change clinic-verified information?',
            'Some of the information you’re changing was verified by Bethlehem Animal Clinic.',
            'Clinic-verified details being changed:',
            'flex items-center justify-between gap-3',
            'role="alertdialog"',
            'aria-modal="true"',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->myPetsPage
                    .$this->myPetsComponent
                    .$this->petProfileComponent,
            );
        }
    }

    public function test_pet_archive_and_restore_use_the_customer_custom_modal(): void
    {
        foreach ([
            'id="petConfirmationModal"',
            'id="cancelPetConfirmation"',
            'id="confirmPetAction"',
            'title: "Archive pet?"',
            'confirmLabel: "Archive pet"',
            'title: "Restore pet?"',
        ] as $content) {
            $this->assertStringContainsString(
                $content,
                $this->myPetsPage.$this->myPetsComponent,
            );
        }

        $this->assertStringNotContainsString('confirm(', $this->myPetsComponent);
    }

    public function test_my_pets_modal_dialogs_have_accessible_names_and_internal_dismissal_controls(): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML($this->myPetsPage);
        $xpath = new \DOMXPath($document);

        foreach ([
            ['petModal', 'dialog', 'petModalTitle', 'closePetModal'],
            ['petConfirmationModal', 'alertdialog', 'petConfirmationTitle', 'cancelPetConfirmation'],
        ] as [$id, $role, $titleId, $dismissId]) {
            $modal = $document->getElementById($id);
            $this->assertNotNull($modal);
            $this->assertSame($role, $modal->getAttribute('role'));
            $this->assertSame('true', $modal->getAttribute('aria-modal'));
            $this->assertSame($titleId, $modal->getAttribute('aria-labelledby'));
            $this->assertNotNull($document->getElementById($titleId));
            $dismiss = $xpath->query('.//*[@id="'.$dismissId.'"]', $modal)->item(0);
            $this->assertInstanceOf(\DOMElement::class, $dismiss);
            $this->assertSame('button', $dismiss->tagName);
            $this->assertSame('button', $dismiss->getAttribute('type'));
            $this->assertFalse($dismiss->hasAttribute('disabled'));
        }
    }

    public function test_my_pets_prevents_duplicate_names_across_active_and_archived_pets(): void
    {
        $petController = file_get_contents(base_path('app/Http/Controllers/PetController.php'));

        foreach ([
            'let allKnownPets = [];',
            'API.getUserPets({ archived: archived ? 1 : 0, force })',
            '...(archivedPetsCache || [])',
            'normalizePetNameForComparison(pet.pet_name) === normalizedPetName',
            'You already have a pet with this name.',
        ] as $clientValidation) {
            $this->assertStringContainsString($clientValidation, $this->myPetsComponent);
        }

        foreach ([
            'normalizePetName($data[\'pet_name\'])',
            'ensureOwnerPetNameIsUnique(',
            "'pet_name' => ['You already have a pet with this name.']",
        ] as $serverValidation) {
            $this->assertStringContainsString($serverValidation, $petController);
        }
    }

    public function test_my_pet_profile_cards_use_species_specific_icons(): void
    {
        $petCard = $this->sourceBetween(
            $this->myPetsComponent,
            'function buildCard(pet)',
            'function openAddModal()',
        );

        foreach ([
            'const speciesIcon = getPetSpeciesIcon(pet.species);',
            'phosphor.svg#${speciesIcon}',
            'if (normalizedSpecies === "dog") return "dog";',
            'if (normalizedSpecies === "cat") return "cat";',
            'return "paw-print";',
        ] as $speciesIconBehavior) {
            $this->assertStringContainsString($speciesIconBehavior, $petCard);
        }
    }

    public function test_my_pets_matches_the_requested_header_toolbar_and_card_actions(): void
    {
        $petCard = $this->sourceBetween(
            $this->myPetsComponent,
            'function buildCard(pet)',
            'function openAddModal()',
        );

        foreach ([
            'placeholder="Search pet name..."',
            'id="notifBellBtn"',
            'data-pre-registration-button',
            'id="filterActiveBtn"',
            'id="filterArchivedBtn"',
            'id="addPetBtn"',
        ] as $control) {
            $this->assertStringContainsString($control, $this->myPetsPage);
        }

        $this->assertStringNotContainsString('["Species", pet.species]', $petCard);
        $this->assertStringContainsString('flex items-start justify-between gap-4 text-sm', $petCard);
        $this->assertStringContainsString('class="grid grid-cols-2 gap-2', $petCard);
        $this->assertStringContainsString('View profile', $petCard);
    }

    public function test_pet_profile_keeps_the_my_pets_shell_and_removes_the_extra_hero(): void
    {
        foreach ([
            'portal-theme group/portal',
            'w-64 flex-col',
            'aria-current="page"',
            'id="petProfileSearch"',
            'id="notifBellBtn"',
            'data-pre-registration-button',
        ] as $consistentProfileElement) {
            $this->assertStringContainsString(
                $consistentProfileElement,
                $this->petProfilePage,
            );
        }

        $this->assertStringContainsString('<title>My Pets | Bethlehem Animal Clinic</title>', $this->petProfilePage);
        $this->assertStringContainsString('>My Pets</h2>', $this->petProfilePage);
        $this->assertStringContainsString('Manage your registered pet profiles.', $this->petProfilePage);
        $this->assertStringNotContainsString('Back to My Pets', $this->petProfilePage);
        $this->assertStringNotContainsString('id="pageSubtitle"', $this->petProfilePage);
        $this->assertStringNotContainsString('id="petHeroName"', $this->petProfilePage);
        $this->assertStringNotContainsString('Shared pet profile', $this->petProfilePage);
        $this->assertStringNotContainsString('const profileTitle =', $this->petProfileComponent);
        $this->assertStringNotContainsString('getElementById("pageTitle").textContent', $this->petProfileComponent);
        $this->assertStringNotContainsString('sm:grid-cols-2 xl:grid-cols-3', $this->petProfilePage);
        $this->assertStringContainsString('text-sm font-bold leading-5', $this->petProfilePage);
        $this->assertStringContainsString('text-xs font-medium text-portal-muted', $this->petProfileComponent);
        $this->assertStringContainsString('min-h-10 gap-2 rounded-[14px]', $this->myPetsPage);
        $this->assertStringContainsString('min-h-8 w-full', $this->myPetsComponent);
    }

    public function test_customer_pet_tabs_prefetch_once_after_the_profile_is_visible(): void
    {
        foreach ([
            'let groomingLoadState = "idle";',
            'let medicalLoadState = "idle";',
            'let vaccinationLoadState = "idle";',
            'if (petProfileReady) void loadPetTabData(selected);',
            'const petTabLoaders = {',
            'grooming: loadGrooming,',
            'health: () => Promise.allSettled([loadMedicalRecords(), loadVaccinations()]),',
            'window.requestIdleCallback(task, { timeout: 1200 });',
            'window.requestAnimationFrame(scheduleCustomerTabPrefetch);',
        ] as $customerTabOptimization) {
            $this->assertStringContainsString(
                $customerTabOptimization,
                $this->petProfileComponent,
            );
        }

        $this->assertStringNotContainsString('await loadGrooming();', $this->petProfileComponent);
    }

    public function test_all_pet_profile_information_cards_use_compact_hierarchical_typography(): void
    {
        foreach ([
            'Basic Information',
            'Medical Conditions / Special Needs',
            'Grooming Records',
            'Clinic Visits',
            'Vaccinations',
        ] as $sectionTitle) {
            $this->assertStringContainsString(
                'text-sm font-bold leading-5 text-portal-text">'.$sectionTitle,
                $this->petProfilePage,
            );
        }

        preg_match_all(
            '/<h[45][^>]*class="([^"]*)"/',
            $this->petProfileComponent,
            $dynamicHeadingMatches,
        );

        $this->assertNotEmpty($dynamicHeadingMatches[1]);
        foreach ($dynamicHeadingMatches[1] as $headingClasses) {
            $this->assertStringContainsString('text-sm', $headingClasses);
        }

        foreach ([
            'break-words text-xs font-semibold text-portal-text',
            'break-words text-xs font-medium leading-5 text-slate-700',
            'whitespace-pre-line break-words text-xs leading-5 text-portal-text',
            'mt-1 text-xs text-portal-text',
        ] as $compactInformationText) {
            $this->assertStringContainsString(
                $compactInformationText,
                $this->petProfileComponent,
            );
        }

        $this->assertStringNotContainsString('text-lg font-bold', $this->petProfileComponent);
        $this->assertStringNotContainsString('text-xl font-bold', $this->petProfileComponent);
    }

    public function test_verified_size_is_marked_on_card_and_in_profile_and_edit_pet(): void
    {
        $petCard = $this->sourceBetween(
            $this->myPetsComponent,
            'function buildCard(pet)',
            'function openAddModal()',
        );

        $this->assertStringContainsString('" ✓"', $petCard);

        foreach ([
            'id="petBreedVerified"',
            'id="petSizeVerified"',
            'id="petFurTypeVerified"',
            'id="petWeightVerified"',
            'text-[10px]',
            'syncEditVerifiedIndicators(pet);',
            'syncEditVerifiedIndicators(null);',
            'my-pets.js?',
        ] as $editIndicator) {
            $this->assertStringContainsString(
                $editIndicator,
                $this->myPetsPage.$this->myPetsComponent,
            );
        }

        $this->assertStringContainsString(
            'verifiedIndicator(isClinicVerified(pet, field))',
            $this->petProfileComponent,
        );
    }

    public function test_pet_update_notifications_open_the_linked_pet_overview(): void
    {
        $this->assertStringContainsString(
            '"pet_information_updated"',
            $this->clientDashboard,
        );
        $this->assertStringContainsString(
            "'tab' => 'overview'",
            $this->notificationController,
        );
        $this->assertStringContainsString(
            '$updatedPet?->pet_id',
            $this->notificationController,
        );
        $this->assertStringContainsString(
            'window.location.href = notification.destination;',
            $this->clientDashboard,
        );
        $this->assertStringContainsString(
            'client-dashboard.js?',
            $this->clientDashboardPage,
        );
        $this->assertStringContainsString(
            'pet-details.js?',
            $this->petProfilePage,
        );
    }

    public function test_my_pets_uses_normal_capitalization_for_labels(): void
    {
        $this->assertStringNotContainsString(
            'uppercase',
            preg_replace('/<aside\b[^>]*>.*?<\/aside>/s', '', $this->myPetsPage)
                .$this->myPetsComponent
                .preg_replace('/<aside\b[^>]*>.*?<\/aside>/s', '', $this->petProfilePage)
                .$this->petProfileComponent,
        );
    }

    private function sourceBetween(
        string $source,
        string $start,
        string $end,
    ): string {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
