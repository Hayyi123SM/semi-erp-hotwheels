<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Models\Consignor;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\DynamicComponent;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class DataTableComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Blade::anonymousComponentPath(__DIR__.'/fixtures/components', 'dt-test');

        // DynamicComponent memoises its tag compiler in a static property, so a
        // component path registered by a later test would never be discovered.
        (new ReflectionProperty(DynamicComponent::class, 'compiler'))->setValue(null, null);
    }

    private function seedRows(int $count = 3): void
    {
        foreach (range(1, $count) as $i) {
            Consignor::create([
                'consignor_code' => 'CN'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => 'Penitip '.$i,
                'status' => 'ACTIVE',
                'scheme_type' => 'PERCENTAGE',
                'scheme_rate' => 10,
            ]);
        }
    }

    private function render(DataTable $table): string
    {
        return Blade::render('<x-ui.data-table :table="$table" />', ['table' => $table]);
    }

    private function table(array $query = []): DataTable
    {
        return DataTable::for(request()->merge($query), Consignor::query())
            ->searchable(['name', 'consignor_code'])
            ->sortable(['name', 'consignor_code'])
            ->columns([
                Column::make('consignor_code', 'Kode', sort: 'consignor_code')->mono(),
                Column::make('name', 'Nama', sort: 'name'),
                Column::make('status', 'Status', format: 'status'),
            ])
            ->perPage([10, 25, 50])
            ->title('Data Penitip');
    }

    #[Test]
    public function it_renders_the_default_layout(): void
    {
        $this->seedRows();
        $html = $this->render($this->table()->create('/master/penitip/create', 'Tambah Penitip'));

        $this->assertStringContainsString('name="q"', $html, 'Search input');
        $this->assertStringContainsString('Tambah Penitip', $html, 'Create button');
        $this->assertStringContainsString('Reset filter', $html, 'Reload button');
        $this->assertStringContainsString('name="per_page"', $html, 'Page size select');
        $this->assertStringContainsString('Menampilkan', $html, 'Pagination summary');
        $this->assertStringContainsString('Data Penitip', $html, 'Table caption');
    }

    #[Test]
    public function it_hides_controls_that_have_no_url(): void
    {
        $this->seedRows();
        $html = $this->render($this->table());

        $this->assertStringNotContainsString('Tambah', $html);
        $this->assertStringNotContainsString('Ekspor', $html);
    }

    #[Test]
    public function it_renders_the_export_menu_when_a_url_is_configured(): void
    {
        $this->seedRows();
        $html = $this->render($this->table()->export('/master/penitip/export'));

        $this->assertStringContainsString('Ekspor', $html);
        $this->assertStringContainsString('format=csv', $html);
        $this->assertStringContainsString('format=xlsx', $html);
    }

    #[Test]
    public function it_renders_sortable_headers_with_aria_state(): void
    {
        $this->seedRows();

        $unsorted = $this->render($this->table());
        $this->assertStringContainsString('aria-sort="none"', $unsorted);
        $this->assertStringContainsString('sort=consignor_code&amp;direction=asc', $unsorted);

        $sorted = $this->render($this->table(['sort' => 'consignor_code', 'direction' => 'desc']));
        $this->assertStringContainsString('aria-sort="descending"', $sorted);
    }

    #[Test]
    public function it_keeps_the_active_query_in_the_preserved_hidden_inputs(): void
    {
        $this->seedRows();
        $html = $this->render($this->table(['q' => 'Penitip', 'sort' => 'name', 'direction' => 'desc']));

        $this->assertStringContainsString('name="q" value="Penitip"', $html);
        $this->assertStringContainsString('name="sort" value="name"', $html);
        $this->assertStringContainsString('name="direction" value="desc"', $html);
    }

    #[Test]
    public function it_omits_the_direction_input_for_ascending(): void
    {
        $this->seedRows();
        $html = $this->render($this->table(['sort' => 'name', 'direction' => 'asc']));

        $this->assertStringNotContainsString('name="direction"', $html);
    }

    #[Test]
    public function it_renders_formatted_cells(): void
    {
        Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Budi',
            'status' => 'ARCHIVED',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 10,
        ]);

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('scheme_rate', 'Skema', format: 'number'),
                Column::make('status', 'Status', format: 'status'),
                Column::make('missing', 'Kosong'),
                Column::make('consignment_date', 'Tanggal', format: 'date'),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('10', $html);
        $this->assertStringContainsString('Arsip', $html);
        $this->assertStringContainsString('—', $html, 'Empty placeholder');
    }

    #[Test]
    public function it_renders_a_derived_value_through_the_chosen_format(): void
    {
        $this->seedRows(1);

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('status', 'Status', format: 'status')
                    ->value(fn ($row) => 'NEEDS_REVIEW'),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('bg-warning-bg', $html);
        $this->assertStringContainsString('Perlu Review', $html);
    }

    #[Test]
    public function it_unwraps_backed_enums_from_a_derived_value(): void
    {
        $this->seedRows(1);

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('status', 'Status', format: 'status')
                    ->value(fn ($row) => ConsignorStatus::Archived),
            ])
            ->perPage([10]);

        $this->assertStringContainsString('Arsip', $this->render($table));
    }

    #[Test]
    public function it_prefers_render_closures_over_formats(): void
    {
        $this->seedRows();

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('name', 'Nama', format: 'rupiah')
                    ->render(fn ($row) => '<em>'.$row->name.'</em>'),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('<em>Penitip 1</em>', $html);
        $this->assertStringNotContainsString('Rp', $html);
    }

    #[Test]
    public function it_delegates_cells_to_a_component(): void
    {
        $this->seedRows(1);

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('name', 'Nama')->component('dt-test-badge', [
                    'type' => 'info',
                    'label' => 'Delegasi',
                ]),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('fixture-badge', $html, 'Delegated component rendered');
        $this->assertStringContainsString('bg-info-bg', $html, 'componentData forwarded');
        $this->assertStringContainsString('Delegasi: Penitip 1', $html, 'Column value forwarded');
        $this->assertStringContainsString('data-key="name"', $html, 'Column object forwarded');
    }

    #[Test]
    public function it_falls_back_to_a_badge_when_a_delegated_component_is_missing(): void
    {
        $this->seedRows(1);

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('name', 'Nama')->component('dt-test-missing', ['type' => 'info']),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('Penitip 1', $html, 'Value still readable when the component cannot be resolved');
    }

    #[Test]
    public function it_hides_invisible_columns_from_the_rendered_table(): void
    {
        $this->seedRows();

        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('name', 'Nama'),
                Column::make('scheme_rate', 'Skema')->visible(fn ($user) => false),
            ])
            ->perPage([10]);

        $html = $this->render($table);

        $this->assertStringContainsString('Nama', $html);
        $this->assertStringNotContainsString('Skema', $html);
    }

    #[Test]
    public function it_renders_the_empty_state_without_a_table(): void
    {
        $html = $this->render(
            $this->table()->emptyState('Belum ada penitip', 'Tambahkan penitip pertama.')
        );

        $this->assertStringContainsString('Belum ada penitip', $html);
        $this->assertStringContainsString('Tambahkan penitip pertama.', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('Menampilkan', $html);
    }

    #[Test]
    public function it_renders_pagination_window_and_page_size_options(): void
    {
        $this->seedRows(30);

        $html = $this->render($this->table(['per_page' => '10', 'page' => '2']));

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('aria-label="Halaman sebelumnya"', $html);
        $this->assertStringContainsString('aria-label="Halaman berikutnya"', $html);
        $this->assertStringContainsString('dari 30 data', $html);
        $this->assertStringContainsString('<option value="10" selected', $html);
        $this->assertStringContainsString('<option value="50"', $html);
    }

    #[Test]
    public function it_disables_pagination_arrows_on_a_single_page(): void
    {
        $this->seedRows(2);

        $html = $this->render($this->table(['per_page' => '10']));

        $this->assertStringNotContainsString('aria-label="Halaman berikutnya"', $html);
        $this->assertStringNotContainsString('aria-label="Halaman sebelumnya"', $html);
    }

    #[Test]
    public function it_submits_immediately_for_a_scanned_sku(): void
    {
        $this->seedRows();
        $html = $this->render($this->table());

        // The markup states the intent -- a typed search waits, a scanned code
        // does not -- and the timing itself is checked against the component in
        // data-table-form.test.js, where it can be measured rather than read.
        $this->assertStringContainsString('x-on:input="search($event.target.value)"', $html, 'Search delegates its timing to the form');
        $this->assertStringContainsString('x-data="dataTableForm"', $html);
    }
}
