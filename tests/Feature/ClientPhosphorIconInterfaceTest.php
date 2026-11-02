<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ClientPhosphorIconInterfaceTest extends TestCase
{
    public function test_client_pages_and_customer_components_do_not_include_lucide(): void
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('pages/client')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $files[] = $file->getPathname();
            }
        }

        foreach ([
            'scripts/auth/sign-in.js',
            'scripts/auth/forgot-password.js',
            'scripts/auth/client/signup.js',
            'scripts/auth/client/verify-email.js',
            'scripts/components/client-dashboard.js',
            'scripts/components/client-settings.js',
            'scripts/components/customer-header-notifications.js',
            'scripts/components/customer-pre-registration-button.js',
            'scripts/components/my-pets.js',
            'scripts/components/pet-details.js',
        ] as $relativePath) {
            $files[] = base_path($relativePath);
        }

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString(
                'lucide',
                strtolower($source),
                basename($file).' must remain free of Lucide code and markup.',
            );
            $this->assertStringNotContainsString('data-lucide', $source);
        }
    }

    public function test_pet_profile_keeps_phosphor_icons_in_the_tab_bar_but_not_in_content(): void
    {
        $page = file_get_contents(base_path('pages/client/pet-details.html'));
        $component = file_get_contents(base_path('scripts/components/pet-details.js'));
        $tabBar = $this->sourceBetween(
            $page,
            'role="tablist"',
            '<section data-pet-panel="overview"',
        );

        foreach ([
            'phosphor.svg#paw-print',
            'phosphor.svg#scissors',
            'phosphor.svg#stethoscope',
        ] as $icon) {
            $this->assertStringContainsString($icon, $tabBar);
        }

        $panelBoundaries = [
            ['<section data-pet-panel="overview"', '<section data-pet-panel="grooming"'],
            ['<section data-pet-panel="grooming"', '<section data-pet-panel="health"'],
            ['<section data-pet-panel="health"', '<script src="/scripts/api.js'],
        ];

        foreach ($panelBoundaries as [$start, $end]) {
            $panel = $this->sourceBetween($page, $start, $end);
            $this->assertStringNotContainsString('<svg', $panel);
            $this->assertStringNotContainsString('<i ', $panel);
        }

        $this->assertStringNotContainsString('<i ', $component);
        $this->assertStringNotContainsString('<svg', $component);
    }

    public function test_every_customer_phosphor_reference_exists_in_the_local_sprite(): void
    {
        $sprite = file_get_contents(base_path('assets/icons/phosphor.svg'));
        preg_match_all('/<symbol id="([a-z0-9-]+)"/', $sprite, $definedMatches);
        $defined = array_flip($definedMatches[1]);

        $sources = $sprite;
        foreach (glob(base_path('pages/client/*.html')) as $page) {
            $sources .= file_get_contents($page);
        }

        preg_match_all('/phosphor\.svg#([a-z0-9-]+)/', $sources, $usedMatches);
        foreach (array_unique($usedMatches[1]) as $icon) {
            $this->assertArrayHasKey($icon, $defined, "Missing Phosphor symbol: {$icon}");
        }
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition, "Missing source boundary: {$start}");
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        $this->assertNotFalse($endPosition, "Missing source boundary: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
