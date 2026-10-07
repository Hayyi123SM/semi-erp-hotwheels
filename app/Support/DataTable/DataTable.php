<?php

namespace App\Support\DataTable;

use App\Support\Sql\LikePattern;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class DataTable
{
    public const DEFAULT_PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['asc', 'desc'];

    private Builder $query;

    private ColumnSet $columns;

    private array $searchable = [];

    /**
     * Kondisi OR tambahan di dalam kelompok pencarian, untuk kasus yang tidak
     * bisa dijawab dengan LIKE mentah.
     */
    private $extraSearch = null;

    private array $sortable = [];

    /**
     * Query keys a page declares as its own filters, mapped to how to name them.
     *
     * @var array<string, array{label: string, format?: callable, flag?: bool}>
     */
    private array $filters = [];

    private array $perPageOptions = self::DEFAULT_PER_PAGE;

    private ?string $searchPlaceholder = null;

    private ?string $createUrl = null;

    private ?string $createLabel = null;

    private ?string $exportUrl = null;

    private ?string $title = null;

    private ?string $emptyTitle = null;

    private ?string $emptyDescription = null;

    /**
     * URL tujuan untuk satu baris, dihitung per baris saat dirender.
     *
     * Bukan `string`, karena URL baris hampir selalu bergantung pada barisnya
     * sendiri -- nota menuju `pos.nota` dengan ID-nya, konsignment menuju
     * halaman detailnya. Menerima URL tunggal akan membuat seluruh tabel
     * menautkan ke baris yang sama.
     */
    private $rowUrl = null;

    private ?LengthAwarePaginator $rows = null;

    private string $search = '';

    private ?string $sort = null;

    private string $direction = 'asc';

    private int $perPage = 10;

    private bool $booted = false;

    private function __construct(
        private readonly Request $request,
        private readonly ?Authenticatable $user = null,
    ) {
        $this->columns = ColumnSet::make([]);
    }

    public static function for(Request $request, Builder $query, ?Authenticatable $user = null): self
    {
        $table = new self($request, $user);
        $table->query = $query;

        return $table;
    }

    /**
     * Columns searched by the toolbar. Relation paths are supported, e.g. 'product.name'.
     */
    public function searchable(array $fields): static
    {
        $this->searchable = array_values(array_filter($fields));

        return $this;
    }

    /**
     * Tambahkan syarat OR lain ke dalam kelompok pencarian.
     *
     * Closure-nya dipanggil dengan query builder dan teks yang diketik, di dalam
     * kelompok yang sama dengan pencarian kolom biasa -- bukan disisipkan sebagai
     * syarat terpisah. Bedanya penting: disisipkan terpisah, syarat itu akan
     * di-AND-kan, dan mengetik nama penitip tidak akan pernah menemukan nomor
     * yang diketik. Jalur pencarian ini untuk pola yang tidak bisa dijawab
     * `LIKE` apa adanya, misalnya mencocokkan nomor telepon yang sudah
     * dinormalkan.
     *
     * @param  (\Illuminate\Database\Eloquent\Builder $query, string $search): void  $callback
     */
    public function orSearchUsing(callable $callback): static
    {
        $this->extraSearch = $callback;

        return $this;
    }

    /**
     * Whitelist of sortable database columns. Anything outside this list is ignored,
     * which keeps user input away from the ORDER BY clause.
     */
    public function sortable(array $columns): static
    {
        $this->sortable = array_values(array_filter($columns));

        return $this;
    }

    public function columns(iterable $columns): static
    {
        $this->columns = ColumnSet::make($columns);

        return $this;
    }

    public function perPage(array $options): static
    {
        $this->perPageOptions = array_values(array_unique(array_filter(array_map('intval', $options))));

        if ($this->perPageOptions === []) {
            $this->perPageOptions = self::DEFAULT_PER_PAGE;
        }

        $this->booted = false;
        $this->boot();

        return $this;
    }

    public function searchPlaceholder(string $placeholder): static
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    public function create(string $url, string $label = 'Tambah'): static
    {
        $this->createUrl = $url;
        $this->createLabel = $label;

        return $this;
    }

    public function export(string $url): static
    {
        $this->exportUrl = $url;

        return $this;
    }

    /**
     * Declare the query keys this page filters by, so the table can name them.
     *
     * A page owns the controls themselves; this only supplies the labels, which
     * is what lets the toolbar say "Status: Aktif" and drop the key on click
     * without the page re-describing itself in a second place.
     *
     * `flag` marks a checkbox, where only a truthy value narrows anything and
     * the chip should read "Perlu review" rather than "Perlu review: 1". A key
     * whose value is meaningful in both directions (Rack's `active`, where `0`
     * means "show the inactive ones") is not a flag, and needs a `format`
     * callback to stay honest.
     *
     * @param  array<string, array{label: string, format?: callable, flag?: bool}>  $filters
     */
    public function filters(array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function emptyState(string $title, ?string $description = null): static
    {
        $this->emptyTitle = $title;
        $this->emptyDescription = $description;

        return $this;
    }

    /**
     * Tautkan setiap baris ke halamannya sendiri.
     *
     * Klik di mana pun pada baris -- kecuali di atas tombol atau tautan yang
     * memang sudah ada di dalamnya -- membuka URL ini. Ini pelengkap kolom aksi,
     * bukan penggantinya: kolom aksi menunjukkan apa yang bisa dilakukan pada
     * baris dan tetap terbaca oleh pembaca layar, sedangkan baris yang bisa
     * diklik hanya memperpendek jarak untuk orang yang sudah tahu mau ke mana.
     *
     * @param  callable|string  $url  penerima barisnya, atau URL yang sama untuk semua
     */
    public function rowUrl(callable|string $url): static
    {
        $this->rowUrl = $url;

        return $this;
    }

    /**
     * Apply search and sorting, then paginate. Safe to call more than once.
     */
    public function resolve(): self
    {
        $this->boot();

        if ($this->rows !== null) {
            return $this;
        }

        $this->applySearch();
        $this->applySort();

        $this->rows = $this->query->paginate($this->perPage)->withQueryString();

        return $this;
    }

    public function columnSet(): ColumnSet
    {
        return $this->columns;
    }

    public function visibleColumns(): Collection
    {
        return $this->columns->visible($this->user);
    }

    /**
     * Visible columns grouped by their role in the mobile card.
     *
     * Priority is irrelevant here: below md the table is replaced entirely, so
     * every column still gets a place on the card rather than being hidden.
     *
     * @return Collection<string, Collection<int, Column>>
     */
    public function cardColumns(): Collection
    {
        return $this->visibleColumns()
            ->reject(fn (Column $column) => $column->cardRole() === 'hidden')
            ->sortBy(fn (Column $column) => Column::cardOrder($column->cardRole()))
            ->values()
            ->groupBy(fn (Column $column) => $column->cardRole());
    }

    public function hasSortableColumns(): bool
    {
        return $this->visibleColumns()->contains(fn (Column $column) => $column->isSortable());
    }

    public function rows(): LengthAwarePaginator
    {
        $this->resolve();

        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return $this->rows()->isEmpty();
    }

    public function search(): string
    {
        $this->boot();

        return $this->search;
    }

    public function isSearchable(): bool
    {
        return $this->searchable !== [];
    }

    public function placeholder(): string
    {
        return $this->searchPlaceholder ?? 'Cari...';
    }

    public function pageSize(): int
    {
        $this->boot();

        return $this->perPage;
    }

    public function perPageOptions(): array
    {
        return $this->perPageOptions;
    }

    public function sort(): ?string
    {
        $this->boot();

        return $this->sort;
    }

    public function direction(): string
    {
        $this->boot();

        return $this->direction;
    }

    public function createUrl(): ?string
    {
        return $this->createUrl;
    }

    public function createLabel(): string
    {
        return $this->createLabel ?? 'Tambah';
    }

    /**
     * URL baris ini, atau `null` bila tabel tidak menautkan barisnya.
     *
     * @param  mixed  $row  satu baris hasil query
     */
    public function rowUrlFor(mixed $row): ?string
    {
        if ($this->rowUrl === null) {
            return null;
        }

        // String diperiksa lebih dulu, bukan lewat is_callable(): URL apa pun
        // yang kebetulan sama dengan nama fungsi PHP -- mustahil hari ini, tapi
        // mustahil dengan cara yang tidak bisa dilihat siapa pun -- akan
        // dipanggil sebagai fungsi dan kembali sebagai apa pun selain URL.
        $url = is_string($this->rowUrl) ? $this->rowUrl : ($this->rowUrl)($row);

        return $url === null || $url === '' ? null : (string) $url;
    }

    public function exportUrl(): ?string
    {
        return $this->exportUrl;
    }

    public function exportUrlFor(string $format): ?string
    {
        return $this->exportUrl ? $this->urlWith(['format' => $format], ['page']) : null;
    }

    public function caption(): string
    {
        return $this->title ?? 'Daftar data';
    }

    public function emptyTitle(): string
    {
        return $this->emptyTitle ?? 'Data tidak ditemukan';
    }

    public function emptyDescription(): string
    {
        return $this->emptyDescription ?? 'Belum ada data yang cocok dengan filter saat ini.';
    }

    public function hasActiveQuery(): bool
    {
        $this->boot();

        return $this->search !== '' || $this->sort !== null;
    }

    /**
     * Whether anything at all is narrowing the result set.
     *
     * The search counts even though it never becomes a chip: a search box shows
     * its own contents, and a dropdown never shows the value currently applied
     * when its first option reads "everything". This is what the empty state
     * uses to explain an empty list.
     */
    public function hasActiveFilters(): bool
    {
        $this->boot();

        return $this->search !== '' || $this->activeFilterTokens() !== [];
    }

    /**
     * One token per declared filter the reader has actually set.
     *
     * A flag is only a filter when it is truthy, matching how the query reads
     * it. A valued key counts in both directions, so `?active=0` is a real
     * filter on Rack and must not be reported as absent.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public function activeFilterTokens(): array
    {
        $this->boot();

        $tokens = [];

        foreach ($this->filters as $key => $definition) {
            if (! $this->request->has($key)) {
                continue;
            }

            $value = $this->request->query($key);

            $isSet = ($definition['flag'] ?? false)
                ? $this->request->boolean($key)
                : $value !== null && $value !== '';

            if (! $isSet) {
                continue;
            }

            $tokens[] = [
                'key' => $key,
                'label' => $this->filterTokenLabel((string) $key, (string) $value, $definition),
                'url' => $this->withoutFilter((string) $key),
            ];
        }

        return $tokens;
    }

    /**
     * @param  array{label: string, format?: callable, flag?: bool}  $definition
     */
    private function filterTokenLabel(string $key, string $value, array $definition): string
    {
        if (isset($definition['format'])) {
            return $definition['label'].': '.$definition['format']($value);
        }

        return ($definition['flag'] ?? false)
            ? $definition['label']
            : $definition['label'].': '.$value;
    }

    /**
     * The current view with one filter lifted off.
     *
     * Page is dropped along with it, since the shorter result set rarely has
     * the page the reader was on. Search, sort and page size stay: lifting the
     * status filter should not throw away the search that was narrowing things
     * in the first place.
     */
    public function withoutFilter(string $key): string
    {
        return $this->urlWith([], ['page', $key]);
    }

    /**
     * Whether the toolbar's second row has any reason to exist.
     *
     * The page owns the summary and the filter controls, so it passes what it
     * rendered; the table answers for its own half, which is the sort control
     * and the chips. Both the full render and the in-place fragment need this
     * answer, and the two rows of the toolbar are laid out from it, so it is
     * asked once here rather than decided twice in two templates.
     */
    public function hasStrip(bool $hasSummary = false, bool $hasFilters = false): bool
    {
        return $hasSummary
            || $hasFilters
            || $this->sortableColumns()->isNotEmpty()
            || $this->activeFilterTokens() !== [];
    }

    public function isSortable(Column $column): bool
    {
        $this->boot();

        return $column->isSortable() && in_array($column->sort, $this->sortable, true);
    }

    /**
     * The visible columns a reader may reorder, in display order.
     *
     * Both the toolbar and the mobile sort control need this list, and the
     * toolbar needs the *count* to decide whether the filter strip has any
     * reason to render at all. Deriving it here keeps the two from drifting.
     */
    public function sortableColumns(): Collection
    {
        return $this->visibleColumns()
            ->filter(fn (Column $column) => $this->isSortable($column))
            ->values();
    }

    public function isSorted(Column $column): bool
    {
        $this->boot();

        return $this->sort !== null && $column->sort === $this->sort;
    }

    public function ariaSort(Column $column): string
    {
        if (! $this->isSortable($column)) {
            return 'none';
        }

        if (! $this->isSorted($column)) {
            return 'none';
        }

        return $this->direction === 'asc' ? 'ascending' : 'descending';
    }

    public function sortUrl(Column $column): string
    {
        if (! $this->isSortable($column)) {
            return $this->currentUrl();
        }

        $ascending = ! ($this->isSorted($column) && $this->direction === 'asc');

        return $this->urlWith([
            'sort' => $column->sort,
            'direction' => $ascending ? 'asc' : 'desc',
        ], ['page']);
    }

    /**
     * Reload action: drops every query parameter except the page size, so a
     * reset also clears filters owned by the page rather than the table.
     */
    public function resetUrl(): string
    {
        $this->boot();

        $base = $this->request->url();

        return $base.'?'.http_build_query(['per_page' => $this->perPage]);
    }

    public function perPageUrl(int $perPage): string
    {
        return $this->urlWith(['per_page' => $perPage], ['page']);
    }

    private function currentUrl(): string
    {
        return $this->urlWith([]);
    }

    private function urlWith(array $overrides, array $drop = []): string
    {
        $this->boot();

        $params = array_merge($this->request->query(), $overrides);

        foreach ($drop as $key) {
            Arr::forget($params, $key);
        }

        // Re-state sort and page size from the resolved state, so a hand-typed
        // ?sort=dropped_column or ?per_page=999999 never leaks into a link.
        $sort = array_key_exists('sort', $overrides) ? $overrides['sort'] : $this->sort;
        $direction = array_key_exists('direction', $overrides) ? $overrides['direction'] : $this->direction;

        if (in_array('sort', $drop, true) || $sort === null) {
            Arr::forget($params, ['sort', 'direction']);
        } else {
            $params['sort'] = $sort;
            $params['direction'] = $direction;
        }

        if (! in_array('per_page', $drop, true)) {
            $params['per_page'] = $overrides['per_page'] ?? $this->perPage;
        }

        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($params[$key]);
            }
        }

        $base = $this->request->url();

        return $params === [] ? $base : $base.'?'.http_build_query($params);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $search = $this->request->query('q');
        $this->search = is_string($search) ? trim($search) : '';

        $sort = $this->request->query('sort');
        $this->sort = is_string($sort) && in_array($sort, $this->sortable, true) ? $sort : null;

        $direction = strtolower((string) $this->request->query('direction'));
        $this->direction = $this->sort !== null && in_array($direction, self::SORTS, true)
            ? $direction
            : 'asc';

        $this->perPage = $this->clampPerPage($this->request->query('per_page'));
    }

    private function clampPerPage(mixed $value): int
    {
        $requested = is_numeric($value) ? (int) $value : 0;

        return in_array($requested, $this->perPageOptions, true) ? $requested : $this->perPageOptions[0];
    }

    private function applySearch(): void
    {
        if ($this->search === '' || $this->searchable === []) {
            return;
        }

        $needle = LikePattern::contains($this->search);
        $table = $this->query->getModel()->getTable();

        $this->query->where(function ($query) use ($needle, $table) {
            foreach ($this->searchable as $field) {
                $query->orWhere(function ($query) use ($field, $needle, $table) {
                    if (! str_contains($field, '.')) {
                        $query->whereRaw($this->like($table, $field), [$needle]);

                        return;
                    }

                    [$relation, $column] = explode('.', $field, 2);

                    $query->orWhereHas(
                        $relation,
                        fn ($related) => $related->whereRaw($this->like($related->getModel()->getTable(), $column), [$needle]),
                    );
                });
            }

            if ($this->extraSearch !== null) {
                ($this->extraSearch)($query, $this->search);
            }
        });
    }

    /**
     * Columns come from the developer's whitelist, never from user input. What
     * the reader typed is a bound parameter, and the escape character is stated
     * rather than assumed -- see {@see LikePattern}.
     */
    private function like(string $table, string $column): string
    {
        return LikePattern::clause("`{$table}`.`{$column}`");
    }

    private function applySort(): void
    {
        if ($this->sort === null) {
            return;
        }

        $this->query->orderBy($this->sort, $this->direction);

        $key = $this->query->getModel()->getKeyName();

        if ($key !== $this->sort) {
            $this->query->orderBy($key);
        }
    }
}
