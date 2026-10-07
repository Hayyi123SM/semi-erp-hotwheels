<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The breadcrumb's shape, and which of its crumbs are places you can go.
 *
 * Every crumb used to render as a bare span, because the prop only accepted a
 * string and a string cannot carry a URL. Widening it to accept a label and an
 * optional href was the fix, and widening a shared prop is exactly the kind of
 * change that needs both halves pinned: the new form has to work, and the old
 * one has to keep working. The plain-string form is not a fallback, it is what
 * 27 of the pages still send — including the ones whose middle crumb is a
 * navigation group rather than a page, which must not become a link to nowhere.
 */
class PageHeaderCrumbsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The breadcrumb as a list of ['text' => …, 'href' => ?].
     *
     * Parsed rather than pattern-matched, because the anchors carry Alpine and
     * Blade attributes whose values contain angle brackets.
     *
     * @return list<array{text: string, href: ?string}>
     */
    private function crumbs(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $crumbs = [];

        foreach ($xpath->query("//nav[@aria-label='Breadcrumb']/*") as $node) {
            if ($node->nodeName === 'svg') {
                continue; // the separator between crumbs
            }

            $crumbs[] = [
                'text' => trim($node->textContent),
                'href' => $node instanceof \DOMElement ? ($node->getAttribute('href') ?: null) : null,
            ];
        }

        return $crumbs;
    }

    private function header(string $html): string
    {
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $matches);

        return trim($matches[1] ?? '');
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function penitip(string $name = 'Budi Santoso'): Consignor
    {
        return Consignor::create([
            'consignor_code' => 'CN01',
            'name' => $name,
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 10,
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function formPages(): array
    {
        return [
            'penitip' => ['master.penitip.create', 'Data Penitip', 'master.penitip'],
            'produk' => ['master.katalog-produk.create', 'Katalog Produk', 'master.katalog-produk'],
            'rak' => ['master.lokasi-rak.create', 'Lokasi Rak', 'master.lokasi-rak'],
            'pengguna' => ['setting.pengguna.create', 'Pengguna & Role', 'setting.pengguna'],
        ];
    }

    #[Test]
    #[DataProvider('formPages')]
    public function a_form_page_links_its_parent_list(string $route, string $label, string $parent): void
    {
        $owner = $this->owner();

        $crumbs = $this->crumbs($this->actingAs($owner)->get(route($route))->getContent());

        $this->assertSame(
            route($parent),
            $crumbs[1]['href'],
            "the {$label} crumb should lead back to the list it belongs to"
        );
        $this->assertSame($label, $crumbs[1]['text']);
    }

    #[Test]
    #[DataProvider('formPages')]
    public function the_current_page_is_not_itself_a_destination(string $route, string $label, string $parent): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route($route))->getContent();

        $crumbs = $this->crumbs($html);

        $this->assertNull($crumbs[count($crumbs) - 1]['href']);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    #[Test]
    public function the_group_a_page_belongs_to_is_not_a_destination(): void
    {
        $owner = $this->owner();

        // "Master Data" is a sidebar group, not a page. There is no route to
        // put here, and a link with an empty href would reload the page in place.
        $crumbs = $this->crumbs($this->actingAs($owner)->get(route('master.penitip.create'))->getContent());

        $this->assertSame('Master Data', $crumbs[0]['text']);
        $this->assertNull($crumbs[0]['href']);
    }

    #[Test]
    public function an_edit_page_names_the_record_it_is_editing(): void
    {
        $owner = $this->owner();
        $consignor = $this->penitip('Budi Santoso');
        $html = $this->actingAs($owner)->get(route('master.penitip.edit', $consignor))->getContent();

        $crumbs = $this->crumbs($html);

        // Five hundred rows and a heading that only says "Edit Penitip" is a
        // question the page cannot answer for the reader.
        $this->assertSame('Edit · Budi Santoso', $crumbs[count($crumbs) - 1]['text']);
        $this->assertStringContainsString('Budi Santoso', $this->header($html));
    }

    #[Test]
    public function a_create_page_names_no_record_because_there_is_none_yet(): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route('master.penitip.create'))->getContent();

        $crumbs = $this->crumbs($html);

        $this->assertSame('Tambah', $crumbs[count($crumbs) - 1]['text']);
        $this->assertSame('Tambah Penitip', $this->header($html));
    }

    #[Test]
    public function a_list_page_links_nothing_at_all(): void
    {
        $owner = $this->owner();

        $crumbs = $this->crumbs($this->actingAs($owner)->get(route('master.penitip'))->getContent());

        $this->assertCount(2, $crumbs);
        $this->assertSame([null, null], array_column($crumbs, 'href'));
    }

    #[Test]
    public function the_plain_string_form_still_renders(): void
    {
        // The compatibility half of widening the prop: 27 pages send plain
        // strings and must be untouched by the change.
        $html = Blade::render('<x-ui.page-header title="Judul" :crumbs="[\'Satu\', \'Dua\']" />');

        $crumbs = $this->crumbs($html);

        $this->assertSame('Satu', $crumbs[0]['text']);
        $this->assertSame('Dua', $crumbs[1]['text']);
        $this->assertSame([null, null], array_column($crumbs, 'href'));
    }

    #[Test]
    public function a_single_crumb_is_the_current_page(): void
    {
        $html = Blade::render('<x-ui.page-header title="Judul" :crumbs="[\'Master Data\']" />');

        $crumbs = $this->crumbs($html);

        $this->assertCount(1, $crumbs);
        $this->assertNull($crumbs[0]['href']);
    }

    #[Test]
    public function a_carry_on_import_step_links_nothing(): void
    {
        // Its middle crumb names a step that has no page of its own, which is
        // the case that a blanket "link everything but the last" would break.
        $html = Blade::render(
            '<x-ui.page-header title="Judul" :crumbs="[\'Master Data\', \'Impor Excel\', \'Petunjuk\']" />'
        );

        $crumbs = $this->crumbs($html);

        $this->assertSame([null, null, null], array_column($crumbs, 'href'));
    }
}
