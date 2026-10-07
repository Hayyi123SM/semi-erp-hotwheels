<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which sidebar entries are lit, and on which pages.
 *
 * The sidebar used to compare route names with `===`, so `master.penitip.create`
 * matched nothing at all and a reader standing on a form had no menu item lit.
 * The rule is now a prefix match, and the interesting half of it is not that it
 * works on the four create and edit pages — it is that it does not spill onto a
 * sibling whose name merely extends the prefix.
 */
class SidebarActiveStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every lit entry, in document order.
     *
     * Parsed rather than pattern-matched: the anchors carry Alpine and Blade
     * attributes whose values contain angle brackets, so a regex over the
     * markup is a guess about the template. This reads the page the way a
     * browser would, and it also lets a test say "these two and nothing else",
     * which is the only form in which a false positive is actually visible.
     */
    private function litEntries(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $lit = [];

        $anchors = $xpath->query("//nav[@aria-label='Navigasi utama']//a");

        foreach ($anchors as $anchor) {
            $labels = $xpath->query(".//span[contains(@class, 'sidebar-label')]", $anchor);
            $label = trim($labels->item(0)?->textContent ?? '');

            if ($label === '') {
                continue;
            }

            $isLit = $anchor->getAttribute('aria-current') === 'page'
                || str_contains($anchor->getAttribute('class'), 'bg-primary-soft');

            if ($isLit) {
                $lit[] = $label;
            }
        }

        return $lit;
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function penitip(): Consignor
    {
        return Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Budi Santoso',
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 10,
        ]);
    }

    #[Test]
    public function the_list_page_lights_its_own_entry(): void
    {
        $owner = $this->owner();

        $this->assertSame(['Data Penitip'], $this->litEntries(
            $this->actingAs($owner)->get(route('master.penitip'))->getContent()
        ));
    }

    #[Test]
    public function the_create_page_keeps_its_section_lit(): void
    {
        $owner = $this->owner();

        $this->assertSame(['Data Penitip'], $this->litEntries(
            $this->actingAs($owner)->get(route('master.penitip.create'))->getContent()
        ));
    }

    #[Test]
    public function the_edit_page_keeps_its_section_lit(): void
    {
        $owner = $this->owner();
        $consignor = $this->penitip();

        $this->assertSame(['Data Penitip'], $this->litEntries(
            $this->actingAs($owner)->get(route('master.penitip.edit', $consignor))->getContent()
        ));
    }

    #[Test]
    public function a_create_page_does_not_light_a_sibling_in_the_same_group(): void
    {
        $owner = $this->owner();

        // The failure a plain substring match walks into: every name in this
        // group starts with "master.", so a loose comparison would light all of
        // them at once and leave the reader with no idea where they are standing.
        $lit = $this->litEntries(
            $this->actingAs($owner)->get(route('master.penitip.create'))->getContent()
        );

        $this->assertNotContains('Katalog Produk', $lit);
        $this->assertNotContains('Lokasi Rak', $lit);
    }

    #[Test]
    public function a_page_with_no_children_lights_only_itself(): void
    {
        $owner = $this->owner();

        // 18 of the 22 entries have no children at all, and the prefix rule has
        // to leave every one of them exactly as it found them.
        $this->assertSame(['Karantina'], $this->litEntries(
            $this->actingAs($owner)->get(route('inventory.karantina'))->getContent()
        ));
    }

    #[Test]
    public function a_signed_out_reader_is_not_given_a_navigation_to_light_up(): void
    {
        // request()->route() is null on these pages, and matching a prefix
        // against null would emit a deprecation on every page a guest can reach.
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('bg-primary-soft', false);
    }
}
