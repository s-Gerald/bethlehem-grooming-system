<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DynamicLucideIconInterfaceTest extends TestCase
{
    public function test_customer_empty_state_icons_remain_mounted_while_switching_tabs(): void
    {
        $page = file_get_contents(base_path('pages/admin/clients.html'));

        foreach (["statusFilter === 'unregistered'" => 'user-plus', "statusFilter !== 'unregistered'" => 'users'] as $condition => $icon) {
            $this->assertMatchesRegularExpression(
                '/<svg\b[^>]*x-show="'.preg_quote($condition, '/').'"[^>]*>\s*<use\b[^>]*href="[^"#]*assets\/icons\/phosphor\.svg(?:\?[^"#]*)?#'.$icon.'"/',
                $page,
            );
        }
        $this->assertStringNotContainsString(
            '<template x-if="statusFilter === \'unregistered\'">',
            $page,
        );
    }

    public function test_admin_sprite_references_resolve_to_existing_symbols(): void
    {
        $sprite = file_get_contents(base_path('assets/icons/phosphor.svg'));
        preg_match_all('/<symbol\b[^>]*\bid="([^"]+)"/', $sprite, $symbols);
        $this->assertNotEmpty($symbols[1]);
        $references = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('pages/admin')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }
            preg_match_all('/<use\b[^>]*(?<!:)href="([^"]*phosphor\.svg[^"]*)"/', file_get_contents($file->getPathname()), $icons);
            foreach ($icons[1] as $href) {
                $references++;
                $this->assertMatchesRegularExpression('~^(?:\.\./)+assets/icons/phosphor\.svg(?:\?[^#]*)?#[a-z0-9-]+$~', $href);
                $this->assertContains(parse_url($href, PHP_URL_FRAGMENT), $symbols[1], "Missing sprite target: {$href}");
            }
        }
        $this->assertGreaterThan(0, $references, 'The sprite reference scan must not be empty.');
    }

    public function test_admin_pages_with_sprite_icons_do_not_load_lucide(): void
    {
        $checkedPages = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('pages/admin')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }

            $page = file_get_contents($file->getPathname());
            if (! str_contains($page, 'phosphor.svg')) {
                continue;
            }
            $checkedPages++;
            $this->assertStringNotContainsString('data-lucide=', $page, $file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/<script\b[^>]*src="[^"]*lucide/i', $page);
        }
        $this->assertGreaterThan(0, $checkedPages, 'The icon runtime scan must cover real pages.');
        $this->assertStringNotContainsString('window.lucide', file_get_contents(base_path('scripts/components/admin-sidebar.js')));
    }
}
