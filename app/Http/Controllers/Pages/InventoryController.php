<?php

namespace App\Http\Controllers\Pages;

use App\Enums\CardCondition;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\PindahRakRequest;
use App\Models\Consignor;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\Inventory\StockTransferService;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use App\Support\MockData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    /**
     * Ambang "stok menipis" pada filter Live Stock (FR-IC-02).
     *
     * Tidak ada kolom ambang per SKU di skema, jadi angka ini ditetapkan di
     * satu tempat alih-alih tersebar di query dan label filter. Satu artinya
     * "tinggal satu unit di rak" -- kasus yang paling sering dicari orang
     * ketika mencari tahu apa yang harus segera ditambah.
     */
    private const int LOW_STOCK_QTY = 1;

    public function liveStock(Request $request)
    {
        $user = $request->user();

        $table = DataTable::for($request, $this->liveStockQuery($request)->with([
            'product.series',
            'consignor',
            'rack',
        ]), $user)
            ->searchable(['sku', 'product.name', 'product.series.name', 'consignor.name'])
            ->sortable(['sku', 'owner_type', 'qty_on_hand', 'card_condition', 'list_price', 'status', 'created_at'])
            ->searchPlaceholder('Scan barcode / cari SKU...')
            ->columns([
                Column::make('sku', 'SKU', sort: 'sku')->mono()->priority(1)->card('title'),
                Column::make('product.name', 'Produk & Seri')
                    ->priority(1)
                    ->card('subtitle')
                    ->render(fn (StockLot $lot) => $this->productCell($lot)),
                Column::make('card_condition', 'Kondisi', sort: 'card_condition')
                    ->priority(3)
                    ->card('meta')
                    ->value(fn (StockLot $lot) => $this->conditionText($lot)),
                Column::make('owner_type', 'Pemilik', sort: 'owner_type')
                    ->format('ownership')
                    ->priority(1)
                    ->card('badge'),
                Column::make('qty_on_hand', 'Qty', align: 'right', sort: 'qty_on_hand', format: 'number')
                    ->priority(1)
                    ->card('meta'),
                Column::make('rack.code', 'Rak')
                    ->priority(2)
                    ->card('meta')
                    ->render(fn (StockLot $lot) => '<span class="font-mono text-body-sm">'.e($lot->rack?->code ?? Format::EMPTY).'</span>'),
                Column::make('list_price', 'Harga Jual', align: 'right', sort: 'list_price', format: 'rupiah')
                    ->priority(1)
                    ->card('price'),
                // HPP dan skema adalah angka milik Owner. Kolomnya disembunyikan
                // lewat `visible()`, bukan lewat pengecekan di template: keputusan
                // yang sama dipakai tabel, kartu di layar kecil, dan ekspor.
                Column::make('cost_price', 'HPP', align: 'right', format: 'rupiah')
                    ->priority(3)
                    ->card('meta')
                    ->visible(fn (?object $viewer) => $viewer?->isOwner() ?? false),
                Column::make('scheme', 'Skema')
                    ->priority(3)
                    ->card('meta')
                    ->visible(fn (?object $viewer) => $viewer?->isOwner() ?? false)
                    ->render(fn (StockLot $lot) => e($this->schemeText($lot))),
                Column::make('status', 'Status', sort: 'status')->format('status')->priority(2)->card('badge'),
                Column::make('created_at', 'Masuk', sort: 'created_at', format: 'date')->priority(2)->card('meta'),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('inventory.lot-actions', ['canTransfer' => true]),
            ])
            ->filters([
                'pemilik' => ['label' => 'Pemilik'],
                'penitip' => ['label' => 'Penitip', 'format' => fn (string $value) => $this->consignorLabel((int) $value)],
                'seri' => ['label' => 'Seri', 'format' => fn (string $value) => $this->seriesLabel((int) $value)],
                'kondisi' => ['label' => 'Kondisi kartu', 'format' => fn (string $value) => CardCondition::tryFrom($value)?->label() ?? $value],
                'rak' => ['label' => 'Rak', 'format' => fn (string $value) => $this->rackLabel((int) $value)],
                'status' => ['label' => 'Status', 'format' => fn (string $value) => Format::statusLabel($value)],
                'aging' => ['label' => 'Masuk ≥', 'format' => fn (string $value) => $value.' hari'],
                'menipis' => ['label' => 'Stok menipis', 'flag' => true],
            ]);

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Stok tidak ditemukan' : 'Belum ada stok',
            $filtered
                ? 'Coba ubah kata kunci atau lepas filter yang aktif.'
                : 'Barang masuk lewat menu Inbound akan muncul di sini.',
        );

        return $this->page('pages.inventory.live-stock', [
            'table' => $table,
            'summary' => $this->liveStockSummary($request),
            'isOwner' => $user?->isOwner() ?? false,
            'series' => ProductSeries::query()->orderBy('name')->get(['id', 'name']),
            'consignors' => Consignor::query()->orderBy('consignor_code')->get(['id', 'consignor_code', 'name']),
            'racks' => Rack::query()->where('is_active', true)->orderBy('code')->get(['id', 'code']),
            'lowStockQty' => self::LOW_STOCK_QTY,
        ], 'Live Stock');
    }

    /**
     * Ekspor CSV sesuai filter yang sedang aktif (FR-IC-06).
     *
     * Kolom Owner ikut hanya bila pembacanya Owner: filter `visible()` yang
     * sama dipakai tabel, jadi tidak ada angka yang bocor lewat unduhan. XLSX
     * sengaja tidak ditawarkan -- belum ada pembacanya di aplikasi ini, dan
     * menautkannya berarti menawarkan berkas yang tidak bisa dibuka.
     */
    public function ekspor(Request $request): StreamedResponse
    {
        $isOwner = $request->user()?->isOwner() ?? false;

        $lots = $this->liveStockQuery($request)
            ->with(['product.series', 'consignor', 'rack'])
            ->get();

        $headers = ['SKU', 'Produk', 'Seri', 'Kondisi', 'Pemilik', 'Penitip', 'Qty', 'Rak', 'Harga Jual', 'Status', 'Masuk'];

        if ($isOwner) {
            $headers = [...$headers, 'HPP', 'Skema'];
        }

        return response()->streamDownload(function () use ($lots, $headers, $isOwner) {
            $out = fopen('php://output', 'w');

            // BOM di depan: tanpanya Excel membaca berkas ini sebagai cp1252 dan
            // huruf non-ASCII di nama produk keluar pecah.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '\\');

            foreach ($lots as $lot) {
                $row = [
                    $lot->sku,
                    $lot->product?->name ?? '',
                    $lot->product?->series?->name ?? '',
                    $this->conditionText($lot),
                    Format::ownershipType($lot->owner_type),
                    $lot->consignor?->name ?? '',
                    $lot->qty_on_hand,
                    $lot->rack?->code ?? '',
                    $lot->list_price,
                    Format::statusLabel($lot->status),
                    $lot->created_at?->format('Y-m-d') ?? '',
                ];

                if ($isOwner) {
                    $row[] = $lot->cost_price ?? '';
                    $row[] = $this->schemeText($lot);
                }

                fputcsv($out, $row, ',', '"', '\\');
            }

            fclose($out);
        }, 'live-stock-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Kartu stok satu SKU (FR-IC-03).
     *
     * Route model binding: SKU yang tidak ada menghasilkan 404, sama seperti
     * halaman detail nota. Tidak ada filter kepemilikan di sini -- kartu stok
     * adalah catatan internal toko, dan batas membaca-nya sudah dijaga oleh
     * siapa yang boleh menulis gerakan.
     */
    public function kartuStok(Request $request, StockLot $lot)
    {
        // Query langsung, bukan `$lot->movements()`: `DataTable::for()` meminta
        // builder Eloquent, sementara relasi memberi `HasMany`. Keduanya membaca
        // baris yang sama, hanya bentuknya yang berbeda.
        $movements = StockMovement::query()->where('lot_id', $lot->getKey())->with('actor');

        // Pengurutan bawaan: yang terbaru lebih dulu. Hanya dipasang bila
        // pembaca belum memilih urutan -- kalau dipasang selalu, urutannya
        // mendahului pilihan pembaca dan kolom yang diklik tidak pernah menang.
        if (! $request->filled('sort')) {
            $movements->orderByDesc('created_at')->orderByDesc('id');
        }

        $table = DataTable::for($request, $movements, $request->user())
            ->sortable(['created_at', 'type', 'qty_delta'])
            ->columns([
                Column::make('created_at', 'Waktu', sort: 'created_at', format: 'datetime')
                    ->priority(1)
                    ->card('title'),
                Column::make('type', 'Gerakan', sort: 'type')
                    ->priority(1)
                    ->card('subtitle')
                    ->render(fn (StockMovement $row) => '<span class="text-body-sm">'.e($row->type?->label() ?? (string) $row->type).'</span>'),
                Column::make('qty_delta', 'Qty', align: 'right', sort: 'qty_delta')
                    ->priority(1)
                    ->card('meta')
                    ->render(fn (StockMovement $row) => $this->signedQtyCell($row->qty_delta)),
                Column::make('balance_after', 'Saldo', align: 'right', format: 'number')
                    ->priority(2)
                    ->card('meta'),
                Column::make('actor.name', 'Aktor')
                    ->priority(2)
                    ->card('meta')
                    ->render(fn (StockMovement $row) => e($row->actor?->name ?? 'Sistem')),
                Column::make('ref', 'Bukti')
                    ->priority(3)
                    ->card('footer')
                    ->render(fn (StockMovement $row) => e($this->referenceText($row))),
                Column::make('reason', 'Keterangan')
                    ->priority(3)
                    ->card('hidden')
                    ->render(fn (StockMovement $row) => e($row->reason ?? Format::EMPTY)),
                Column::make('device_id', 'Perangkat')
                    ->priority(3)
                    ->card('hidden')
                    ->mono(),
            ])
            ->emptyState('Belum ada gerakan', 'Pergerakan stok untuk SKU ini akan tercatat di sini.');

        return $this->page('pages.inventory.kartu-stok', [
            'lot' => $lot->load(['product.series', 'consignor', 'rack']),
            'table' => $table,
        ], 'Kartu Stok '.$lot->sku);
    }

    public function pindahRak(StockLot $lot, PindahRakRequest $request, StockTransferService $transfer)
    {
        $rack = $request->rack();

        $transfer->transfer($lot, $rack, $request->user(), $request->reason());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $lot->sku.' dipindah ke rak '.$rack->code.'.',
        ]);
    }

    public function karantina()
    {
        return $this->page('pages.inventory.karantina', ['cases' => MockData::quarantineCases()], 'Karantina');
    }

    /**
     * Penyaringan Live Stock tanpa pengurutan, dipakai ringkasan.
     */
    private function liveStockFilter(Request $request): Builder
    {
        $query = StockLot::query();

        // Nilai enum diverifikasi lebih dulu: input yang tidak dikenal diabaikan
        // alih-alih menjadi syarat yang tidak pernah cocok -- yang di layar
        // terlihat seperti tabel kosong, bukan filter yang salah ketik.
        $ownership = (string) $request->query('pemilik');

        if ($ownership === 'PRIBADI') {
            $query->where('owner_type', OwnerType::Own->value);
        } elseif ($ownership === 'TITIPAN') {
            $query->where('owner_type', OwnerType::Consign->value);
        }

        if ($penitip = $request->integer('penitip')) {
            $query->where('consignor_id', $penitip);
        }

        if ($series = $request->integer('seri')) {
            $query->whereHas('product', fn (Builder $products) => $products->where('series_id', $series));
        }

        if (($condition = (string) $request->query('kondisi')) !== '' && CardCondition::tryFrom($condition) !== null) {
            $query->where('card_condition', $condition);
        }

        if ($rack = $request->integer('rak')) {
            $query->where('rack_id', $rack);
        }

        if (($status = (string) $request->query('status')) !== '' && LotStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($aging = $request->integer('aging')) {
            $query->where('created_at', '<=', now()->subDays($aging));
        }

        if ($request->boolean('menipis')) {
            $query->where('qty_on_hand', '<=', self::LOW_STOCK_QTY);
        }

        return $query;
    }

    /**
     * Penyaringan yang sama, ditambah urutan bawaan.
     *
     * Pemisahannya bukan gaya: ringkasan memanggil `selectRaw` di atas query
     * yang sudah terurut, dan `ORDER BY sku` pada query agregat tanpa GROUP BY
     * ditolak MySQL dengan "not in GROUP BY clause". Satu sumber penyaringan,
     * dua perilaku pengurutan.
     */
    private function liveStockQuery(Request $request): Builder
    {
        $query = $this->liveStockFilter($request);

        // Urutan bawaan: SKU urut, karena itulah yang dibaca orang ketika
        // menyusuri daftar. Dipasang hanya bila pembaca belum memilih sendiri.
        if (! $request->filled('sort')) {
            $query->orderBy('sku');
        }

        return $query;
    }

    /**
     * Empat angka ringkasan (FR-IC-05), dihitung dari penyaringan yang sama
     * persis dengan yang mengisi tabel.
     *
     * Nilai pribadi memakai HPP dan nilai titipan memakai harga jual, sesuai
     * cara aplikasi ini menghitung: barang titipan belum menjadi milik toko,
     * jadi menghitungnya dengan harga beli akan mengarang kerugian yang tidak
     * pernah terjadi.
     *
     * @return array{units: int, ownUnits: int, consignUnits: int, ownValue: int, consignValue: int, lots: int, consignors: int, lowStock: int}
     */
    private function liveStockSummary(Request $request): array
    {
        $totals = $this->liveStockFilter($request)
            ->selectRaw('
                count(*) as lots,
                count(distinct consignor_id) as consignors,
                coalesce(sum(qty_on_hand), 0) as units,
                coalesce(sum(case when owner_type = ? then qty_on_hand else 0 end), 0) as own_units,
                coalesce(sum(case when owner_type = ? then qty_on_hand else 0 end), 0) as consign_units,
                coalesce(sum(case when owner_type = ? then qty_on_hand * coalesce(cost_price, 0) else 0 end), 0) as own_value,
                coalesce(sum(case when owner_type = ? then qty_on_hand * list_price else 0 end), 0) as consign_value,
                count(case when qty_on_hand <= ? then 1 end) as low_stock
            ', [
                OwnerType::Own->value,
                OwnerType::Consign->value,
                OwnerType::Own->value,
                OwnerType::Consign->value,
                self::LOW_STOCK_QTY,
            ])
            ->toBase()
            ->first();

        return [
            'units' => (int) ($totals->units ?? 0),
            'ownUnits' => (int) ($totals->own_units ?? 0),
            'consignUnits' => (int) ($totals->consign_units ?? 0),
            'ownValue' => (int) ($totals->own_value ?? 0),
            'consignValue' => (int) ($totals->consign_value ?? 0),
            'lots' => (int) ($totals->lots ?? 0),
            'consignors' => (int) ($totals->consignors ?? 0),
            'lowStock' => (int) ($totals->low_stock ?? 0),
        ];
    }

    private function productCell(StockLot $lot): string
    {
        $series = $lot->product?->series?->name;
        $html = '<span class="font-medium text-text-strong">'.e($lot->product?->name ?? $lot->sku).'</span>';

        if ($series !== null && $series !== '') {
            $html .= '<span class="block text-label-sm text-text-muted">'.e($series).'</span>';
        }

        return $html;
    }

    private function conditionText(StockLot $lot): string
    {
        $card = $lot->card_condition?->label() ?? Format::EMPTY;
        $blister = $lot->blister_condition?->label();

        return $blister === null || $blister === '' ? $card : $card.' / '.$blister;
    }

    private function schemeText(StockLot $lot): string
    {
        if ($lot->owner_type === OwnerType::Own) {
            return 'Milik toko';
        }

        if ($lot->scheme_type === null) {
            return Format::EMPTY;
        }

        $detail = match (true) {
            $lot->scheme_rate !== null && $lot->scheme_rate !== '' => Format::rate((string) $lot->scheme_rate).'%',
            $lot->scheme_amount !== null => Format::rupiah((int) $lot->scheme_amount),
            default => Format::EMPTY,
        };

        return Format::enum($lot->scheme_type->value).' · '.$detail;
    }

    private function signedQtyCell(int $qty): string
    {
        $tone = match (true) {
            $qty > 0 => 'text-success-text',
            $qty < 0 => 'text-error-text',
            default => 'text-text-muted',
        };

        $text = $qty > 0 ? '+'.$qty : (string) $qty;

        return '<span class="font-semibold tabular-nums '.$tone.'">'.e($text).'</span>';
    }

    /**
     * Dokumen yang melahirkan satu gerakan, dalam kalimat yang bisa dicari
     * orang -- nomor nota dan nomor dokumen masuk lebih mudah diingat daripada
     * id baris.
     */
    private function referenceText(StockMovement $movement): string
    {
        if ($movement->ref_type === null || $movement->ref_id === null) {
            return Format::EMPTY;
        }

        return match (true) {
            $movement->ref_type === 'CONSIGNMENT' || str_contains($movement->ref_type, 'Consignment') => 'Terima barang #'.$movement->ref_id,
            str_contains($movement->ref_type, 'Sale') => 'Nota #'.$movement->ref_id,
            str_contains($movement->ref_type, 'Opname') => 'Opname #'.$movement->ref_id,
            default => class_basename($movement->ref_type).' #'.$movement->ref_id,
        };
    }

    private function consignorLabel(int $id): string
    {
        $consignor = Consignor::find($id);

        return $consignor === null
            ? Format::EMPTY
            : $consignor->consignor_code.' · '.$consignor->name;
    }

    private function seriesLabel(int $id): string
    {
        return ProductSeries::find($id)?->name ?? Format::EMPTY;
    }

    private function rackLabel(int $id): string
    {
        return Rack::find($id)?->code ?? Format::EMPTY;
    }
}
