<?php

namespace Tests\Feature\Ui;

use App\Models\Consignor;
use App\Models\User;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\DataTable\ViewPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResponsiveColumnTest extends TestCase
{
    use RefreshDatabase;

    private function render(DataTable $table): string
    {
        return Blade::render('<x-ui.data-table :table="$table" />', ['table' => $table]);
    }

    private function table(): DataTable
    {
        return DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('consignor_code', 'Kode')->priority(1),
                Column::make('name', 'Nama')->priority(1),
                Column::make('phone', 'Telepon')->priority(2),
                Column::make('address', 'Alamat')->priority(3),
            ])
            ->title('Data Penitip');
    }

    private function tableWithName(string $name): DataTable
    {
        Consignor::create([
            'consignor_code' => 'CN-LONG',
            'name' => $name,
            'status' => 'ACTIVE',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 10,
        ]);

        return DataTable::for(request(), Consignor::query()->where('consignor_code', 'CN-LONG'))
            ->columns([Column::make('name', 'Nama')->priority(1)->card('title')])
            ->title('Data Penitip');
    }

    /**
     * A column is only sortable when it names its own sort key, so declaring
     * sortable() on the table is not enough to get the mobile sort fallback.
     */
    private function sortableTable(): DataTable
    {
        return DataTable::for(request(), Consignor::query())
            ->columns([Column::make('name', 'Nama', sort: 'name')->priority(1)])
            ->sortable(['name'])
            ->title('Data Penitip');
    }

    /**
     * The same table, but resolved against a request that carries the given
     * query, so the filter chips have something to be active about.
     */
    private function filteredTable(array $query, array $filters): DataTable
    {
        return DataTable::for(request()->merge($query), Consignor::query())
            ->columns([
                Column::make('consignor_code', 'Kode')->priority(1),
                Column::make('name', 'Nama')->priority(1),
            ])
            ->searchable(['name'])
            ->filters($filters)
            ->title('Data Penitip');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Penitip Satu',
            'status' => 'ACTIVE',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 10,
        ]);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function priorityProvider(): array
    {
        return [
            'priority 1 is always on' => [1, ''],
            'priority 2 waits for xl' => [2, 'hidden xl:table-cell'],
            'priority 3 waits for 2xl' => [3, 'hidden 2xl:table-cell'],
        ];
    }

    #[Test]
    #[DataProvider('priorityProvider')]
    public function it_hides_low_priority_columns_only_through_css(int $level, string $expected): void
    {
        $column = Column::make('name', 'Nama')->priority($level);

        $this->assertSame($expected, $column->responsiveClass());
    }

    #[Test]
    public function it_applies_the_priority_class_to_both_head_and_body_cells(): void
    {
        $html = $this->render($this->table());

        $this->assertSame(2, substr_count($html, 'hidden xl:table-cell'), 'Telepon head + cell');
        $this->assertSame(2, substr_count($html, 'hidden 2xl:table-cell'), 'Alamat head + cell');
    }

    #[Test]
    public function it_never_removes_a_low_priority_column_from_the_markup(): void
    {
        $html = $this->render($this->table());

        // Priority is presentation only. Hiding must happen in CSS so the column
        // still exists for screen readers, export and the tablet layout.
        foreach (['Kode', 'Nama', 'Telepon', 'Alamat'] as $label) {
            $this->assertMatchesRegularExpression(
                '#<th\b[^>]*>\s*'.preg_quote($label, '#').'\s*</th>#',
                $html,
                "{$label} header is still rendered"
            );
        }
    }

    #[Test]
    public function it_renders_a_table_and_a_card_list_and_hides_one_of_them(): void
    {
        $html = $this->render($this->table());

        // The two branches are switched by CSS on data-table-view, so the classes
        // are hooks rather than hardcoded display utilities.
        $this->assertStringContainsString('class="data-table-table table-scroll"', $html);
        $this->assertStringContainsString('class="data-table-cards"', $html);
        $this->assertStringContainsString('data-table-card', $html);
    }

    #[Test]
    public function it_renders_the_card_list_exactly_once(): void
    {
        $html = $this->render($this->table());

        // Grid mode reuses the mobile card list instead of adding a third copy
        // of every row, so the branch must not be duplicated in the markup.
        $this->assertSame(1, substr_count($html, 'class="data-table-cards"'));
        $this->assertSame(1, substr_count($html, 'class="data-table-table table-scroll"'));
    }

    #[Test]
    public function it_shows_every_visible_column_on_a_mobile_card(): void
    {
        $html = $this->render($this->table());

        // Below md the card replaces the table, so the CSS-only priority hiding
        // must not apply there: a phone user still needs the address.
        $card = substr($html, (int) strpos($html, 'data-table-cards'));

        foreach (['Kode', 'Nama', 'Telepon', 'Alamat'] as $label) {
            $this->assertStringContainsString($label, $card, "{$label} appears on the card");
        }
    }

    #[Test]
    public function it_scopes_the_view_toggle_to_the_data_table_root(): void
    {
        $html = $this->render($this->table());

        // The toggle dispatches; the root is the only listener, so a stray
        // window-level handler cannot flip every table on the page at once.
        $this->assertStringContainsString('@table-view="setView($event.detail)"', $html);
        $this->assertStringNotContainsString('@table-view.window', $html);

        // `setView` comes from tableView, which `dataTable` composes together
        // with the in-place refresh: an element can only carry one x-data, so
        // the root has to be the component that holds both.
        $this->assertStringContainsString('x-data="dataTable(', $html);
    }

    #[Test]
    public function it_hides_the_view_toggle_below_md(): void
    {
        $html = $this->render($this->table());

        // A phone only has the card list, so the switch has nothing to toggle.
        $this->assertStringContainsString('class="hidden h-11 items-center gap-0.5', $html);
        $this->assertStringContainsString('role="group" aria-label="Tampilan data"', $html);
    }

    #[Test]
    public function the_head_and_the_body_share_one_cell_padding(): void
    {
        $html = $this->render($this->table());

        // The head declared px-4 py-3 while the body declared nothing, so the
        // header text sat inside its cell and the row text touched the border.
        // Both now read Column::CELL_PADDING.
        $this->assertSame(4, substr_count($html, 'class="'.Column::CELL_PADDING.' text-body-sm align-middle'));
        $this->assertSame(4, substr_count($html, 'class="'.Column::CELL_PADDING.' font-semibold whitespace-nowrap'));
    }

    #[Test]
    public function a_row_is_drawn_with_a_single_divider(): void
    {
        $html = $this->render($this->table());

        // divide-y on the body plus border-b on the row used to stack two 1px
        // lines of different opacity between every pair of rows.
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringNotContainsString('divide-y divide-border-subtle">', $html);
    }

    #[Test]
    public function it_clamps_long_text_to_two_lines_with_a_tooltip(): void
    {
        $html = $this->render($this->tableWithName('Koperasi Hot Wheels Sentosa Abadi'));

        $this->assertStringContainsString('class="cell-text text-text-strong" title="Koperasi Hot Wheels Sentosa Abadi"', $html);
    }

    #[Test]
    public function it_leaves_a_short_value_without_a_tooltip(): void
    {
        $html = $this->render($this->tableWithName('Penitip Satu'));

        // A hover on a value that fits only surfaces noise.
        $this->assertStringContainsString('class="cell-text text-text-strong" >Penitip Satu</span>', $html);
    }

    #[Test]
    public function every_toolbar_control_shares_one_height(): void
    {
        $table = DataTable::for(request(), Consignor::query())
            ->columns([Column::make('name', 'Nama')])
            ->searchable(['name'])
            ->create('/master/penitip/tambah', 'Tambah Penitip')
            ->export('/master/penitip/export')
            ->title('Data Penitip');

        $html = $this->render($table);

        // Search, reload, export, toggle and create are all h-11, either through
        // their own class or through h-11 on the toggle container. The toggle used
        // to be the only short control in the row.
        $this->assertStringContainsString('class="input-base pl-10"', $html);
        $this->assertStringContainsString('class="btn-icon"', $html);
        $this->assertStringContainsString('class="btn-secondary"', $html);
        $this->assertStringContainsString('class="hidden h-11 items-center gap-0.5', $html);
        $this->assertStringContainsString('class="btn-primary"', $html);
    }

    #[Test]
    public function the_toolbar_keeps_its_controls_in_reading_order(): void
    {
        $table = DataTable::for(request(), Consignor::query())
            ->columns([Column::make('name', 'Nama')])
            ->create('/master/penitip/tambah')
            ->export('/master/penitip/export')
            ->title('Data Penitip');

        $html = $this->render($table);

        $controls = array_map(
            fn (string $needle): int|false => strpos($html, $needle),
            ['class="btn-icon"', 'class="btn-secondary"', 'class="hidden h-11 items-center', 'class="btn-primary"']
        );

        // Reload, export, view switch, create: the two that change the result set
        // sit on the outside, the two that change the presentation sit together.
        $sorted = $controls;
        sort($sorted);

        $this->assertSame($sorted, $controls);
    }

    #[Test]
    public function it_drops_the_view_toggle_when_there_is_nothing_to_switch(): void
    {
        $html = Blade::render('<x-ui.data-table :table="$table" />', [
            'table' => DataTable::for(request(), Consignor::query()->where('name', 'tidak ada'))
                ->columns([Column::make('consignor_code', 'Kode')])
                ->title('Data Penitip'),
        ]);

        $this->assertStringNotContainsString('aria-label="Tampilan data"', $html);
    }

    #[Test]
    public function it_rejects_an_out_of_range_priority(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Column::make('name', 'Nama')->priority(4);
    }

    #[Test]
    public function it_seeds_the_saved_view_in_the_head(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/master/penitip')->assertOk()->getContent();

        // Js::from() picks single quotes for a plain string, so the literal in
        // the document is what proves the key was interpolated per user.
        $this->assertStringContainsString("window.localStorage.getItem('datatable-view:{$user->id}')", $html);
    }

    #[Test]
    public function it_scopes_the_view_preference_to_the_signed_in_user(): void
    {
        // Signed out, the key still has to be stable rather than empty.
        $this->assertSame('datatable-view:guest', ViewPreference::storageKey());

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertSame("datatable-view:{$user->id}", ViewPreference::storageKey());
    }

    #[Test]
    public function it_offers_the_view_toggle_on_a_real_page(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get('/master/penitip')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Tampilan data"', $html);

        // Js::from() renders a bare string as a single quoted literal, so this is
        // the value the click hands to setView().
        $this->assertStringContainsString("\$dispatch('table-view', 'table')", $html);
        $this->assertStringContainsString("\$dispatch('table-view', 'grid')", $html);
    }

    #[Test]
    public function a_table_without_page_tools_has_no_second_row(): void
    {
        $html = $this->render($this->table());

        // An empty band would be a stripe of nothing between the toolbar and the
        // table, so the row only exists when it has something to say.
        $this->assertStringNotContainsString('filter-strip', $html);
    }

    #[Test]
    public function the_second_row_carries_the_page_own_tools(): void
    {
        $html = Blade::render(
            '<x-ui.data-table :table="$table"><x-slot:filters><select name="status" class="select-base filter-select"></select></x-slot:filters></x-ui.data-table>',
            ['table' => $this->table()]
        );

        $this->assertStringContainsString('filter-strip', $html);
        $this->assertStringContainsString('filter-select', $html);

        // Filters refine the result set, so they live below the default row
        // rather than competing with search for the same line.
        $this->assertGreaterThan(
            strpos($html, 'name="q"'),
            strpos($html, 'name="status"')
        );
    }

    #[Test]
    public function a_filter_change_submits_the_form(): void
    {
        $html = Blade::render(
            '<x-ui.data-table :table="$table"><x-slot:filters><select name="status"></select></x-slot:filters></x-ui.data-table>',
            ['table' => $this->table()]
        );

        // Without this a dropdown looks like a control but does nothing until
        // the reader happens to touch the search box as well. The listener
        // belongs to the form rather than to this row, because the row is
        // re-rendered on every refresh and reaches the browser without one.
        $this->assertStringContainsString('x-on:change="apply()"', $html);
        $this->assertStringContainsString('x-data="dataTableForm"', $html);
    }

    #[Test]
    public function a_page_summary_sits_in_the_second_row(): void
    {
        $html = Blade::render(
            '<x-ui.data-table :table="$table"><x-slot:summary>3 seri terdaftar</x-slot:summary></x-ui.data-table>',
            ['table' => $this->table()]
        );

        $this->assertStringContainsString('filter-strip', $html);
        $this->assertStringContainsString('3 seri terdaftar', $html);

        // Read only context, not a control: it must not reach the default row.
        $this->assertGreaterThan(
            strpos($html, 'filter-strip'),
            strpos($html, '3 seri terdaftar')
        );
    }

    #[Test]
    public function the_sort_fallback_joins_the_second_row(): void
    {
        $html = Blade::render(
            '<x-ui.data-table :table="$table"><x-slot:filters><select name="status"></select></x-slot:filters></x-ui.data-table>',
            ['table' => $this->sortableTable()]
        );

        // Below md the head is gone, so the chips have to live somewhere. They
        // refine the list just like a filter does, so they ride along here
        // instead of becoming a third band of their own.
        $this->assertGreaterThan(
            strpos($html, 'filter-strip'),
            strpos($html, 'md:hidden')
        );
        $this->assertStringContainsString('Urutkan', $html);
    }

    #[Test]
    public function a_sortable_table_gets_a_second_row_even_without_page_tools(): void
    {
        $html = $this->render($this->sortableTable());

        $this->assertStringContainsString('filter-strip', $html);
    }

    #[Test]
    public function a_filter_chip_matches_the_height_of_a_dropdown(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get('/master/lokasi-rak')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="filter-chip"', $html);

        // The chip used to be a hand written `px-3 py-2` pill at ~34px, which is
        // the one control in the strip that did not line up with the rest.
        $this->assertStringNotContainsString('px-3 py-2 text-label-md text-text-muted', $html);
    }

    #[Test]
    public function an_applied_filter_shows_a_chip_that_drops_it(): void
    {
        $html = $this->render($this->filteredTable(['status' => 'active'], [
            'status' => ['label' => 'Status', 'format' => fn (string $v) => ucfirst($v)],
        ]));

        $this->assertStringContainsString('class="filter-token"', $html);
        $this->assertStringContainsString('Status: Active', $html);

        // The whole chip is the link, so the target is generous enough to hit
        // and it names what it will remove for anyone not reading the glyph.
        $this->assertMatchesRegularExpression('/<a href="[^"]*" class="filter-token"/', $html);
        $this->assertStringNotContainsString('status=active', $html);
    }

    #[Test]
    public function no_chip_appears_while_no_filter_is_applied(): void
    {
        $html = $this->render($this->filteredTable([], [
            'status' => ['label' => 'Status', 'format' => fn (string $v) => $v],
        ]));

        $this->assertStringNotContainsString('filter-token', $html);
    }

    /**
     * The declaration names the filter keys; the page renders the controls. If
     * one is renamed and not the other the chip simply never lights up, which is
     * the kind of thing nothing else would catch.
     */
    #[Test]
    public function every_declared_filter_is_backed_by_a_control_on_the_page(): void
    {
        $pages = [
            '/master/penitip' => ['status'],
            '/master/katalog-produk' => ['needs_review'],
            '/master/lokasi-rak' => ['type', 'active'],
            '/settings/pengguna-role' => ['role'],
        ];

        foreach ($pages as $url => $keys) {
            // Owner, bukan default factory (Staff): `/settings/pengguna-role`
            // kini 403 untuk Staff, dan test ini tidak soal siapa yang boleh
            // masuk -- tuannya di AuthorizationConsistencyTest. Yang di sini
            // cuma apakah kontrol filternya benar-benar dirender.
            $html = $this->actingAs(User::factory()->owner()->create())
                ->get($url)
                ->assertOk()
                ->getContent();

            foreach ($keys as $key) {
                $this->assertStringContainsString(
                    'name="'.$key.'"',
                    $html,
                    $url.' declares a filter named '.$key.' but renders no control with that name'
                );
            }
        }
    }
}
