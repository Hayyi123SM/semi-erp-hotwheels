<?php

namespace App\Http\Controllers\Master;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest\StoreProductRequest;
use App\Http\Requests\ProductRequest\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Services\AuditLogger;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use Illuminate\Support\Facades\Redirect;

class ProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        $user = auth()->user();
        $isOwner = $user->isOwner();

        $base = Product::query()->with('series');
        $products = (clone $base)
            ->when(request()->boolean('needs_review'), fn ($query) => $query->where('needs_review', true));

        $lotSummary = $this->lotSummary();

        $table = DataTable::for(request(), $products)
            ->searchable(['name', 'casting_code', 'tags', 'series.name'])
            ->sortable(['name', 'casting_code', 'default_list_price', 'status', 'needs_review'])
            ->columns([
                Column::make('name', 'Produk', sort: 'name')
                    ->priority(1)
                    ->card('title')
                    ->render(function (Product $product) {
                        $sub = $product->casting_code ? 'Casting '.e($product->casting_code) : '';

                        foreach ($product->tags ?? [] as $tag) {
                            $sub .= '<span class="ml-1 rounded bg-canvas px-1.5 py-0.5 text-label-sm">#'.e($tag).'</span>';
                        }

                        return '<div class="flex flex-col gap-0.5">'
                            .'<span class="font-medium text-text-strong">'.e($product->name).'</span>'
                            .'<span class="text-label-sm text-text-subtle">'.$sub.'</span>'
                            .'</div>';
                    }),
                Column::make('series.name', 'Seri')->priority(3)->card('subtitle')->render(fn (Product $p) => $p->series?->name),
                Column::make('detail', 'Detail')->priority(3)->card('meta')->render(function (Product $p) {
                    return trim(($p->year ? $p->year.' · ' : '').($p->color ?? 'Tanpa warna'));
                }),
                Column::make('condition', 'Kondisi')->priority(2)->card('meta')->render(function (Product $p) {
                    return trim(Format::enum($p->card_condition->value).' / '.Format::enum($p->blister_condition->value));
                }),
                Column::make('default_list_price', 'Harga Jual', align: 'right', format: 'rupiah', sort: 'default_list_price')
                    ->priority(1)
                    ->card('price'),
                Column::make('lots', 'Lot Aktif', align: 'center')->priority(1)->card('badge')->render(function (Product $product) use ($lotSummary) {
                    $lot = $lotSummary[$product->id] ?? null;

                    return '<span class="font-mono tabular-nums text-text-strong">'.($lot->lots ?? 0).'</span>'
                        .'<span class="text-label-sm text-text-subtle"> / '.($lot->qty ?? 0).' unit</span>';
                }),
                Column::make('status', 'Status', format: 'status')
                    ->priority(1)
                    ->card('badge')
                    ->value(fn (Product $product) => $product->needs_review ? 'NEEDS_REVIEW' : $product->status->value),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => array_values(array_filter([
                            [
                                'key' => 'approve',
                                'label' => 'Setujui',
                                'variant' => 'primary',
                                'route' => 'master.katalog-produk.approve',
                                'method' => 'PATCH',
                                'when' => fn (Product $p) => $isOwner && $p->needs_review,
                            ],
                            [
                                'key' => 'edit',
                                'label' => 'Edit',
                                'route' => 'master.katalog-produk.edit',
                                'when' => $isOwner,
                            ],
                            [
                                'key' => 'destroy',
                                'label' => 'Hapus',
                                'variant' => 'danger',
                                'route' => 'master.katalog-produk.destroy',
                                'method' => 'DELETE',
                                'when' => $isOwner,
                                'confirm' => [
                                    'title' => 'Hapus produk ini?',
                                    'description' => 'Produk dihapus permanen. Tidak bisa bila masih memiliki stock lot.',
                                    'text' => 'Hapus',
                                ],
                            ],
                        ])),
                    ])
                    ->visible(fn () => true),
            ])
            ->perPage([10, 25, 50, 100])
            ->searchPlaceholder('Cari nama produk / seri / kode casting...')
            ->title('Katalog Produk')
            ->filters([
                // A flag, because the query reads it with boolean(): only a
                // truthy value narrows anything, so the chip says just the name.
                'needs_review' => ['label' => 'Perlu review', 'flag' => true],
            ])
            ->create(route('master.katalog-produk.create'), 'Tambah Produk');

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Produk tidak ditemukan' : 'Katalog masih kosong',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Tambahkan produk pertama, atau gunakan Impor Excel untuk memuat banyak data sekaligus.'
        );

        return $this->page('pages.master.katalog-produk', [
            'table' => $table,
            'totalProducts' => $base->toBase()->count(),
            'totalLots' => (int) $lotSummary->sum('lots'),
            'totalUnits' => (int) $lotSummary->sum('qty'),
            'needsReviewCount' => $base->toBase()->where('needs_review', true)->count(),
            'series' => ProductSeries::orderBy('name')->get(),
            'canManage' => $isOwner,
        ], 'Katalog Produk');
    }

    private function lotSummary()
    {
        return StockLot::query()
            ->selectRaw('product_id, COUNT(*) as lots, COALESCE(SUM(qty_on_hand), 0) as qty')
            ->where('status', LotStatus::Available->value)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');
    }

    public function create()
    {
        return $this->page('pages.master.katalog-produk-form', [
            'product' => new Product,
            'series' => ProductSeries::orderBy('name')->get(),
            'canManage' => auth()->user()->isOwner(),
        ], 'Tambah Produk');
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $isOwner = auth()->user()->isOwner();

        $this->ensureNameUnique($data['name']);

        $data['needs_review'] = $isOwner ? false : true;
        $data['tags'] = $this->parseTags($data['tags'] ?? null);

        $product = Product::create($data);
        $this->audit->created($product);

        $message = $isOwner
            ? 'Produk '.$product->name.' tersimpan.'
            : 'Produk '.$product->name.' tersimpan dan menunggu review Owner.';

        return Redirect::route('master.katalog-produk')->with('toast', ['type' => 'success', 'message' => $message]);
    }

    public function edit(Product $product)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.master.katalog-produk-form', [
            'product' => $product,
            'series' => ProductSeries::orderBy('name')->get(),
            'canManage' => true,
        ], 'Edit Produk');
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $before = $product->getAttributes();
        $data = $request->validated();
        $data['tags'] = $this->parseTags($data['tags'] ?? null);
        $data['needs_review'] = false;

        $product->fill($data)->save();
        $this->audit->updated($product, $before);

        return Redirect::route('master.katalog-produk')->with('toast', [
            'type' => 'success',
            'message' => 'Produk '.$product->name.' diperbarui.',
        ]);
    }

    public function approve(Product $product)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $product->update(['needs_review' => false]);
        $this->audit->log('REVIEW', class_basename($product), $product->getKey(), ['needs_review' => true], ['needs_review' => false]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Produk '.$product->name.' disetujui.']);
    }

    public function destroy(Product $product)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        if ($product->stockLots()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Produk tidak dapat dihapus karena masih memiliki stock lot.',
            ]);
        }

        $this->audit->delete($product);
        $product->delete();

        return back()->with('toast', ['type' => 'info', 'message' => 'Produk '.$product->name.' dihapus.']);
    }

    private function ensureNameUnique(string $name): void
    {
        $duplicate = Product::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            Redirect::back()
                ->withInput()
                ->withErrors(['name' => 'Nama produk "'.$name.'" sudah ada di katalog.'])
                ->throwResponse();
        }
    }

    private function parseTags(?string $tags): array
    {
        if ($tags === null) {
            return [];
        }

        return collect(explode(',', $tags))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
