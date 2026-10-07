<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Models\Consignor;
use App\Models\StockLot;
use App\Models\User;
use App\Support\DataTable\Column;
use App\Support\DataTable\ColumnSet;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataTableTest extends TestCase
{
    use RefreshDatabase;

    private function consignor(string $code, string $name, string $status = 'ACTIVE'): Consignor
    {
        return Consignor::create([
            'consignor_code' => $code,
            'name' => $name,
            'status' => $status,
            'settlement_cycle' => 'MONTHLY',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 10,
        ]);
    }

    private function table(array $query = [], ?object $user = null): DataTable
    {
        return DataTable::for(request()->merge($query), Consignor::query(), $user)
            ->searchable(['name', 'consignor_code'])
            ->sortable(['name', 'consignor_code', 'created_at'])
            ->columns([
                Column::make('consignor_code', 'Kode', sort: 'consignor_code')->mono(),
                Column::make('name', 'Nama', sort: 'name'),
                Column::make('status', 'Status', format: 'status'),
            ])
            ->perPage([10, 25, 50])
            ->title('Data Penitip');
    }

    #[Test]
    public function it_clamps_per_page_to_the_whitelist(): void
    {
        $this->assertSame(10, $this->table(['per_page' => '999999'])->pageSize());
        $this->assertSame(10, $this->table(['per_page' => 'abc'])->pageSize());
        $this->assertSame(10, $this->table(['per_page' => '-5'])->pageSize());
        $this->assertSame(25, $this->table(['per_page' => '25'])->pageSize());
    }

    #[Test]
    public function it_ignores_sort_outside_the_whitelist(): void
    {
        $table = $this->table(['sort' => 'wa_number', 'direction' => 'desc']);
        $this->assertNull($table->sort());
        $this->assertSame('asc', $table->direction());

        $allowed = $this->table(['sort' => 'consignor_code', 'direction' => 'evil']);
        $this->assertSame('consignor_code', $allowed->sort());
        $this->assertSame('asc', $allowed->direction());
    }

    #[Test]
    public function it_normalises_an_invalid_per_page_in_generated_links(): void
    {
        $url = $this->table(['per_page' => '999999'])->resetUrl();
        $this->assertStringContainsString('per_page=10', $url);
        $this->assertStringNotContainsString('999999', $url);
    }

    #[Test]
    public function it_filters_rows_by_search_term(): void
    {
        $this->consignor('CN01', 'Budi Santoso');
        $this->consignor('CN02', 'Siti Aminah');

        $rows = $this->table(['q' => 'budi'])->rows();

        $this->assertSame(1, $rows->total());
        $this->assertSame('Budi Santoso', $rows->first()->name);
    }

    #[Test]
    public function it_escapes_wildcards_in_the_search_term(): void
    {
        $this->consignor('CN01', 'Budi Santoso');
        $this->consignor('CN02', 'Budi% Santoso');
        $this->consignor('CN03', 'Andi Saputra');
        $this->consignor('CN04', 'Andi-Saputra');
        $this->consignor('CN05', 'Andi_Saputra');

        $this->assertSame(1, $this->table(['q' => 'Budi%'])->rows()->total());
        $this->assertSame(1, $this->table(['q' => 'i_Sap'])->rows()->total(), 'Underscore must match literally, not any character.');
        $this->assertSame(1, $this->table(['q' => '%'])->rows()->total(), 'A bare wildcard must not match all five rows.');
    }

    #[Test]
    public function it_escapes_the_escape_character_itself(): void
    {
        $this->consignor('CN01', 'Gigi Santoso');
        $this->consignor('CN02', 'Gigi! Santoso');
        $this->consignor('CN03', 'Gigi!X Santoso');

        // An escape character that reaches the pattern unescaped swallows the
        // character after it, so this term would match nothing at all.
        $this->assertSame(1, $this->table(['q' => 'Gigi!X'])->rows()->total());

        // Both names really do contain the term, so both belong in the answer.
        $this->assertSame(2, $this->table(['q' => 'Gigi!'])->rows()->total());
    }

    #[Test]
    public function it_declares_a_like_escape_character_that_every_engine_agrees_on(): void
    {
        $this->consignor('CN01', 'Budi Santoso');

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });

        $this->table(['q' => 'budi'])->rows();

        $like = collect($queries)->first(fn ($sql) => str_contains(mb_strtolower($sql), 'like'));

        $this->assertNotNull($like, 'Expected a LIKE comparison.');

        // Asserted on the SQL rather than on the rows, because the rows are
        // right on one engine and wrong on the other depending on which
        // character was picked. Only the statement itself tells the truth.
        $this->assertStringContainsString("escape '!'", mb_strtolower($like));

        // Backslash is a trap: MySQL reads `ESCAPE '\'` as an unterminated
        // literal and rejects the whole query.
        $this->assertStringNotContainsString("escape '\\'", mb_strtolower($like));
    }

    #[Test]
    public function it_sorts_with_a_stable_tiebreaker(): void
    {
        $this->consignor('CN01', 'Sama');
        $this->consignor('CN02', 'Sama');
        $this->consignor('CN03', 'Sama');

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });

        $this->table(['sort' => 'name', 'direction' => 'asc'])->rows();

        $orderBy = collect($queries)->first(fn ($sql) => str_contains($sql, 'order by'));

        $this->assertNotNull($orderBy, 'Expected an ORDER BY clause.');
        $this->assertMatchesRegularExpression('/order by .*"name" asc, .*"id" asc/i', $orderBy);
    }

    #[Test]
    public function it_paginates_with_the_query_string_preserved(): void
    {
        foreach (range(1, 25) as $i) {
            $this->consignor('CN'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Penitip '.$i);
        }

        $table = $this->table(['per_page' => '10', 'q' => 'Penitip', 'page' => '2']);

        $this->assertSame(25, $table->rows()->total());
        $this->assertSame(3, $table->rows()->lastPage());
        $this->assertSame(2, $table->rows()->currentPage());
        $this->assertSame(10, $table->rows()->count());
        $this->assertStringContainsString('q=Penitip', $table->rows()->nextPageUrl());
        $this->assertStringContainsString('q=Penitip', $table->rows()->url(1));
    }

    #[Test]
    public function it_resolves_only_once(): void
    {
        $this->consignor('CN01', 'Budi');

        $table = $this->table();
        $table->resolve();
        $table->resolve();

        $this->assertSame(1, $table->rows()->total());
    }

    #[Test]
    public function it_hides_invisible_columns_from_the_header_and_from_exports(): void
    {
        $columns = ColumnSet::make([
            Column::make('name', 'Nama'),
            Column::make('scheme_rate', 'Skema')->visible(fn ($user) => $user?->isOwner() === true),
        ]);

        $this->assertSame(['name'], $columns->visible(null)->pluck('key')->all());
        $this->assertSame(['name'], $columns->exportable(null)->pluck('key')->all());

        $owner = User::factory()->owner()->create();
        $this->assertSame(['name', 'scheme_rate'], $columns->visible($owner)->pluck('key')->all());
    }

    #[Test]
    public function it_excludes_non_exportable_columns(): void
    {
        $columns = ColumnSet::make([
            Column::make('name', 'Nama'),
            Column::make('actions', 'Aksi')->notExportable(),
        ]);

        $this->assertSame(['name'], $columns->exportable()->pluck('key')->all());
    }

    #[Test]
    public function it_builds_sort_links_that_toggle_direction(): void
    {
        $set = $this->table()->columnSet();

        $first = $this->table(['sort' => 'name', 'direction' => 'asc']);
        $this->assertStringContainsString('direction=desc', $first->sortUrl($set->find('name')));
        $this->assertSame('ascending', $first->ariaSort($set->find('name')));

        $other = $first->sortUrl($set->find('consignor_code'));
        $this->assertStringContainsString('sort=consignor_code', $other);
        $this->assertStringContainsString('direction=asc', $other);
        $this->assertSame('none', $first->ariaSort($set->find('status')));
    }

    #[Test]
    public function it_builds_a_reset_link_that_keeps_only_the_page_size(): void
    {
        $url = $this->table(['q' => 'budi', 'sort' => 'name', 'direction' => 'desc', 'page' => '4', 'per_page' => '25'])->resetUrl();

        $this->assertStringNotContainsString('q=', $url);
        $this->assertStringNotContainsString('sort=', $url);
        $this->assertStringNotContainsString('direction=', $url);
        $this->assertStringNotContainsString('&page=', $url);
        $this->assertStringContainsString('per_page=25', $url);
    }

    #[Test]
    public function it_drops_page_scoped_filters_from_the_reset_link(): void
    {
        $url = $this->table([
            'q' => 'budi',
            'status' => 'ARCHIVED',
            'owner' => 'CONSIGN',
            'sort' => 'name',
            'per_page' => '25',
        ])->resetUrl();

        $this->assertStringNotContainsString('status=', $url);
        $this->assertStringNotContainsString('owner=', $url);
        $this->assertStringContainsString('per_page=25', $url);
    }

    #[Test]
    public function it_builds_a_page_size_link_without_the_current_page(): void
    {
        $url = $this->table(['q' => 'budi', 'page' => '3'])->perPageUrl(50);

        $this->assertStringContainsString('per_page=50', $url);
        $this->assertStringContainsString('q=budi', $url);
        $this->assertStringNotContainsString('&page=', $url);
    }

    #[Test]
    public function it_never_leaks_an_unwhitelisted_sort_into_a_link(): void
    {
        $url = $this->table(['sort' => 'scheme_rate', 'direction' => 'sideways', 'per_page' => '999'])->perPageUrl(25);

        $this->assertStringNotContainsString('scheme_rate', $url);
        $this->assertStringNotContainsString('sideways', $url);
        $this->assertStringNotContainsString('999', $url);
        $this->assertStringContainsString('per_page=25', $url);
    }

    #[Test]
    public function it_marks_a_column_sortable_only_when_whitelisted(): void
    {
        $set = $this->table()->columnSet();

        $this->assertTrue($this->table()->isSortable($set->find('name')));
        $this->assertFalse($this->table()->isSortable(Column::make('wa_number', 'WA')->sortable('wa_number')));
        $this->assertFalse($this->table()->isSortable($set->find('status')));
    }

    #[Test]
    public function it_rejects_invalid_column_options(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Column::make('qty', 'Qty')->format('bule');
    }

    #[Test]
    public function it_rejects_values_that_are_not_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColumnSet::make(['name' => 'budi']);
    }

    #[Test]
    public function fluent_setters_do_not_mutate_the_original_column(): void
    {
        $base = Column::make('qty', 'Qty');
        $derived = $base->format('number')->align('right')->mono();

        $this->assertNull($base->format);
        $this->assertSame('left', $base->align);
        $this->assertFalse($base->mono);
        $this->assertSame('number', $derived->format);
        $this->assertSame('right', $derived->align);
        $this->assertTrue($derived->mono);
    }

    #[Test]
    public function it_derives_a_label_and_sort_column_from_the_key(): void
    {
        $relation = Column::make('consignor.name');
        $this->assertSame('Consignor Name', $relation->label);
        $this->assertNull($relation->sort, 'Relation keys must not be sortable implicitly.');

        $direct = Column::make('name');
        $this->assertSame('Name', $direct->label);
        $this->assertSame('name', $direct->sortable()->sort);
    }

    #[Test]
    public function it_searches_through_relations(): void
    {
        $this->consignor('CN01', 'Budi Santoso');

        $table = DataTable::for(request(), StockLot::query()->with('consignor'))
            ->searchable(['consignor.name'])
            ->sortable(['sku'])
            ->columns([Column::make('sku', 'SKU')->sortable('sku')])
            ->perPage([10])
            ->title('Stok');

        $this->assertSame(0, $table->rows()->total());
    }

    #[Test]
    public function it_resolves_status_column_values_for_badges(): void
    {
        $archived = $this->consignor('CN01', 'Budi', ConsignorStatus::Archived->value);

        $table = $this->table();
        $row = $table->rows()->first();

        $this->assertSame(ConsignorStatus::Archived, $row->status);
        $this->assertSame($archived->id, $row->id);
    }

    /**
     * Mirrors the two shapes the real pages use, because they are not
     * interchangeable: a flag only narrows when truthy, while a valued key
     * narrows in both directions.
     */
    private function filterableTable(array $query = []): DataTable
    {
        return $this->table($query)->filters([
            'status' => ['label' => 'Status', 'format' => fn (string $v) => Format::statusLabel($v)],
            'needs_review' => ['label' => 'Perlu review', 'flag' => true],
            'active' => ['label' => 'Rak', 'format' => fn (string $v) => $v === '1' ? 'aktif' : 'tidak aktif'],
        ]);
    }

    #[Test]
    public function a_search_alone_counts_as_an_active_filter_but_makes_no_chip(): void
    {
        $table = $this->filterableTable(['q' => 'budi']);

        // The search box already shows what is in it, and type=search gives the
        // reader a native clear. A chip next to it would only repeat that.
        $this->assertTrue($table->hasActiveFilters());
        $this->assertSame([], $table->activeFilterTokens());
    }

    #[Test]
    public function an_unset_filter_is_not_an_active_filter(): void
    {
        $table = $this->filterableTable();

        $this->assertFalse($table->hasActiveFilters());
        $this->assertSame([], $table->activeFilterTokens());
    }

    #[Test]
    public function a_flag_filter_ignores_a_false_value(): void
    {
        // The query reads this one with boolean(), so ?needs_review=0 filters
        // nothing and must not be reported as an applied filter.
        $this->assertFalse($this->filterableTable(['needs_review' => '0'])->hasActiveFilters());
        $this->assertFalse($this->filterableTable(['needs_review' => ''])->hasActiveFilters());
        $this->assertTrue($this->filterableTable(['needs_review' => '1'])->hasActiveFilters());
    }

    #[Test]
    public function a_valued_filter_counts_in_both_directions(): void
    {
        $tokens = $this->filterableTable(['active' => '0'])->activeFilterTokens();

        // On Rack ?active=0 means "show the inactive ones", so calling this an
        // absent filter would hide a filter that is genuinely narrowing.
        $this->assertCount(1, $tokens);
        $this->assertSame('Rak: tidak aktif', $tokens[0]['label']);
        $this->assertSame('active', $tokens[0]['key']);
    }

    #[Test]
    public function a_flag_chip_names_the_filter_without_a_raw_value(): void
    {
        $tokens = $this->filterableTable(['needs_review' => '1'])->activeFilterTokens();

        $this->assertSame('Perlu review', $tokens[0]['label']);
    }

    #[Test]
    public function a_chip_uses_the_pages_own_formatter(): void
    {
        $tokens = $this->filterableTable(['status' => ConsignorStatus::Archived->value])->activeFilterTokens();

        // Not the raw "ARCHIVED": the page already has a word for it, and the
        // chip is read, not parsed.
        $this->assertStringContainsString('Arsip', $tokens[0]['label']);
        $this->assertStringNotContainsString('ARCHIVED', $tokens[0]['label']);
    }

    #[Test]
    public function lifting_one_filter_keeps_the_rest_of_the_view(): void
    {
        $table = $this->filterableTable([
            'q' => 'budi',
            'status' => 'ACTIVE',
            'sort' => 'name',
            'direction' => 'desc',
            'per_page' => '25',
            'page' => '4',
        ]);

        $url = $table->withoutFilter('status');

        // Parsed rather than pattern matched: "per_page=" contains "page=".
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('status', $query);
        // Back to page one: the shorter list rarely has the page being viewed.
        $this->assertArrayNotHasKey('page', $query);
        $this->assertSame('budi', $query['q']);
        $this->assertSame('name', $query['sort']);
        $this->assertSame('desc', $query['direction']);
        $this->assertSame('25', $query['per_page']);
    }

    #[Test]
    public function several_filters_each_get_their_own_chip(): void
    {
        $tokens = $this->filterableTable([
            'status' => 'ACTIVE',
            'needs_review' => '1',
            'active' => '1',
        ])->activeFilterTokens();

        $this->assertCount(3, $tokens);
        $this->assertSame(['status', 'needs_review', 'active'], array_column($tokens, 'key'));
    }
}
