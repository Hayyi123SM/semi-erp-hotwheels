<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the responsive shell contract.
 *
 * These are markup assertions, not rendering: PHPUnit cannot tell us the drawer
 * is 256px on a phone. What it can do is pin the parts that regressed before —
 * the mobile width inheriting the 72px rail, and the drawer having no way out
 * for a keyboard user.
 */
class SidebarLayoutTest extends TestCase
{
    private function readView(string $name): string
    {
        return (string) file_get_contents(resource_path("views/{$name}"));
    }

    private function js(string $name): string
    {
        return (string) file_get_contents(resource_path("js/{$name}"));
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /** @return list<string> */
    private function jsFiles(): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];

        foreach ($files as $file) {
            // Test files are left out. Reading `document.body.style.overflow` to
            // assert on the lock is the whole point of the tests that do it, and
            // a scan cannot tell that read from the write this rule is about.
            if (! $file->isFile() || $file->getExtension() !== 'js' || str_contains($file->getFilename(), '.test.js')) {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    #[Test]
    public function the_mobile_drawer_is_not_narrower_than_the_tablet_rail(): void
    {
        $css = $this->css();

        // The base value used to be the 4.5rem rail, which also squeezed the
        // mobile drawer to icon width while its labels were still rendered.
        $this->assertMatchesRegularExpression(
            '/:root\s*\{\s*--sidebar-current:\s*16rem;/',
            $css,
            'the unsized fallback is the 256px drawer'
        );

        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 768px\)\s*\{\s*:root\s*\{\s*--sidebar-current:\s*4\.5rem;/',
            $css,
            'the rail is applied from md up, not before'
        );
    }

    #[Test]
    public function the_desktop_band_is_declared_after_the_rail(): void
    {
        $css = $this->css();

        $rail = strpos($css, '@media (min-width: 768px) {');
        $desktop = strpos($css, '@media (min-width: 1280px) {');

        $this->assertIsInt($rail);
        $this->assertIsInt($desktop);

        // Equal specificity, so the later block is the one that wins at xl.
        $this->assertGreaterThan($rail, $desktop);
    }

    #[Test]
    public function the_rail_keeps_its_two_usable_widths(): void
    {
        $css = $this->css();

        // 4.5rem = 72px, the width that only fits icons. 16rem = 256px is the
        // mobile drawer, the pinned tablet rail and the desktop sidebar.
        $this->assertSame(1, preg_match_all('/--sidebar-current:\s*4\.5rem;/', $css));
        $this->assertSame(3, preg_match_all('/--sidebar-current:\s*16rem;/', $css));
    }

    #[Test]
    public function the_sidebar_translate_comes_from_alpine_alone(): void
    {
        $sidebar = $this->readView('layouts/sidebar.blade.php');

        // A static md:translate-x-0 fought the Alpine-bound class for the same
        // property, and the winner depended on Tailwind's variant ordering.
        $this->assertStringNotContainsString('md:translate-x-0', $sidebar);
        $this->assertStringContainsString(":class=\"isVisible ? 'translate-x-0' : '-translate-x-full'\"", $sidebar);
    }

    #[Test]
    public function the_drawer_traps_and_restores_focus(): void
    {
        $sidebar = $this->readView('layouts/sidebar.blade.php');
        $topbar = $this->readView('layouts/topbar.blade.php');

        $this->assertStringContainsString('x-ref="drawerClose"', $sidebar);
        $this->assertStringContainsString('x-ref="menuToggle"', $topbar);
        $this->assertStringContainsString('@keydown.escape.window="open && closeDrawer()"', $sidebar);
    }

    #[Test]
    public function the_drawer_locks_the_page_behind_it(): void
    {
        $this->assertStringContainsString(
            'this.$watch(\'open\', (isOpen) => this.$lock.set(isOpen));',
            $this->js('alpine/sidebar.js')
        );

        $this->assertStringContainsString(
            'x-cloak',
            $this->readView('layouts/sidebar.blade.php'),
            'the scrim is not painted before Alpine runs'
        );
    }

    #[Test]
    public function no_overlay_writes_the_body_overflow_itself(): void
    {
        // Every overlay used to assign document.body.style.overflow, so closing
        // one of them scrolled the page back while the others were still up.
        $owner = 'scroll-lock.js';
        $offenders = [];

        foreach ($this->jsFiles() as $file) {
            $touches = str_contains((string) file_get_contents($file), 'document.body.style.overflow');

            if ($touches && ! str_ends_with($file, $owner)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, "only {$owner} may touch the body overflow");
    }

    #[Test]
    public function the_shell_chrome_label_can_actually_truncate(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.sidebar-label\s*\{\s*@apply block truncate;/',
            $this->css(),
            'text-overflow is ignored on an inline box, which is what overflowed the rail'
        );
    }

    #[Test]
    public function the_view_preference_is_seeded_before_the_stylesheet(): void
    {
        $layout = $this->readView('layouts/app.blade.php');

        // An inline script in the head is the only way to have the attribute set
        // before first paint; after the stylesheet the table would flash past.
        $seed = strpos($layout, 'localStorage.getItem');
        $stylesheet = strpos($layout, '@vite');

        $this->assertIsInt($seed);
        $this->assertIsInt($stylesheet);
        $this->assertLessThan($stylesheet, $seed);
    }
}
