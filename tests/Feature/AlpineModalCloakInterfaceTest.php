<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class AlpineModalCloakInterfaceTest extends TestCase
{
    public function test_admin_shells_load_readiness_and_session_gates_before_content(): void
    {
        $checkedShells = 0;
        $pagesPath = base_path('pages/admin');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesPath),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'html') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all(
                '/<[a-z][^>]*\bx-data\s*=\s*["\']adminSidebar\(\)["\'][^>]*>/is',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as [$openingTag]) {
                $checkedShells++;
                $this->assertStringContainsString('data-admin-shell', $openingTag, $file->getPathname());
                $this->assertStringNotContainsString('data-admin-ready', $openingTag);
                $this->assertMatchesRegularExpression('/<html\b[^>]*data-session-gate="checking"/', $source);
                $this->assertSame(1, preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $source, $head));
                foreach (['custom', 'session-lock', 'admin-loading'] as $stylesheet) {
                    $this->assertMatchesRegularExpression(
                        '~<link\b(?=[^>]*rel="stylesheet")[^>]*href="[^"#]*css/'.$stylesheet.'\.css(?:\?[^"#]*)?"~',
                        $head[1],
                    );
                }
                $this->assertDoesNotMatchRegularExpression(
                    '/<[a-z]+\b(?=[^>]*\bdata-admin-navigation(?:\s|>))(?=[^>]*(?:\bx-cloak\b|x-show="sidebarReady"))[^>]*>/i',
                    $source,
                );
                $this->assertStringNotContainsString('data-admin-sidebar-loading', $source);
            }
        }

        $this->assertGreaterThan(0, $checkedShells, 'The Admin shell scan must cover real pages.');
        $loadingCss = file_get_contents(base_path('css/admin-loading.css'));
        $this->assertMatchesRegularExpression(
            '/\[data-admin-shell\]:not\(\[data-admin-ready\]\)\s+main\s*\{\s*visibility\s*:\s*hidden\s*;/s',
            $loadingCss,
        );
        $sessionCss = file_get_contents(base_path('css/session-lock.css'));
        $this->assertSame(1, preg_match(
            '/html\[data-session-gate\]\s+body\s*>\s*:not\(#bethlehem-session-lock\):not\(script\):not\(style\)\s+\*\s*\{([^}]+)\}/s',
            $sessionCss,
            $sessionGate,
        ));
        $this->assertMatchesRegularExpression('/visibility\s*:\s*hidden\s*!important\s*;/', $sessionGate[1]);
        $this->assertMatchesRegularExpression('/pointer-events\s*:\s*none\s*!important\s*;/', $sessionGate[1]);
        $this->assertSame(1, preg_match(
            '/html\[data-session-gate="checking"\][^{]+\[data-admin-navigation\]\s+\*\s*\{([^}]+)\}/s',
            $loadingCss,
            $checkingNavigation,
        ));
        $this->assertMatchesRegularExpression('/visibility\s*:\s*visible\s*!important\s*;/', $checkingNavigation[1]);
        $this->assertMatchesRegularExpression('/pointer-events\s*:\s*none\s*!important\s*;/', $checkingNavigation[1]);
    }

    public function test_every_alpine_full_screen_overlay_is_cloaked_before_initialization(): void
    {
        $violations = [];
        $pagesPath = base_path('pages');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesPath),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'html') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all(
                '/<[a-z][^>]*\bx-show\s*=\s*(?<quote>["\']).*?\k<quote>[^>]*>/is',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as [$openingTag, $offset]) {
                if (! preg_match('/\bclass\s*=\s*(?<quote>["\'])(?<classes>.*?)\k<quote>/is', $openingTag, $classMatch)) {
                    continue;
                }

                $classes = preg_split('/\s+/', trim($classMatch['classes']));
                $isFullScreenOverlay = in_array('fixed', $classes, true)
                    && in_array('inset-0', $classes, true);

                if (! $isFullScreenOverlay || preg_match('/\bx-cloak\b/i', $openingTag)) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = str_replace(str_replace('\\', '/', base_path()).'/', '', $relativePath);
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $violations[] = "{$relativePath}:{$line}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Every Alpine full-screen overlay must include x-cloak so it cannot flash before Alpine initializes.\n"
                .implode("\n", $violations),
        );
    }

    public function test_every_javascript_full_screen_overlay_has_a_static_hidden_fallback(): void
    {
        $violations = [];
        $pagesPath = base_path('pages');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesPath),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'html') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all(
                '/<[a-z][^>]*\bclass\s*=\s*(?<quote>["\'])(?<classes>.*?)\k<quote>[^>]*>/is',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as $index => [$openingTag, $offset]) {
                $classes = preg_split('/\s+/', trim($matches['classes'][$index][0]));
                $isFullScreenOverlay = in_array('fixed', $classes, true)
                    && in_array('inset-0', $classes, true);
                $usesAlpineVisibility = preg_match('/\bx-show\s*=/i', $openingTag);

                if (! $isFullScreenOverlay || $usesAlpineVisibility) {
                    continue;
                }

                $hasStaticHiddenFallback = in_array('hidden', $classes, true)
                    || preg_match('/\bx-cloak\b/i', $openingTag)
                    || preg_match('/\bstyle\s*=\s*["\'][^"\']*display\s*:\s*none/i', $openingTag);

                if ($hasStaticHiddenFallback) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = str_replace(str_replace('\\', '/', base_path()).'/', '', $relativePath);
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $violations[] = "{$relativePath}:{$line}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Every non-Alpine full-screen overlay must be statically hidden until its script explicitly opens it.\n"
                .implode("\n", $violations),
        );
    }

    public function test_shared_styles_hide_cloaked_content_before_alpine_initializes(): void
    {
        $customCss = file_get_contents(base_path('css/custom.css'));

        $this->assertMatchesRegularExpression(
            '/\[x-cloak\]\s*\{[^}]*display\s*:\s*none\s*!important\s*;/is',
            $customCss,
        );
    }
}
