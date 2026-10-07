<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\PrintRackLabelsRequest;
use App\Http\Requests\RackRequest\StoreRackRequest;
use App\Http\Requests\RackRequest\UpdateRackRequest;
use App\Models\Rack;
use App\Models\StockLot;
use App\Services\AuditLogger;
use App\Services\Label\LabelPage;
use App\Services\Label\LabelPrinterSettings;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use Illuminate\Support\Facades\Redirect;

class RackController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        $isOwner = auth()->user()->isOwner();

        $base = Rack::query();
        $racks = (clone $base)->when(
            request()->query('type'),
            fn ($query, $type) => $query->where('type', $type)
        )->when(
            request()->query('active'),
            fn ($query, $active) => $query->where('is_active', $active === '1')
        );

        $usage = $this->usage();

        $table = DataTable::for(request(), $racks)
            ->searchable(['code', 'zone'])
            ->sortable(['code', 'zone', 'type', 'capacity', 'is_active'])
            ->columns([
                Column::make('code', 'Kode Rak', sort: 'code')->mono()->priority(1)->card('title'),
                Column::make('zone', 'Zona')->priority(3)->card('meta'),
                Column::make('type', 'Tipe', format: 'enum')->priority(3)->card('meta'),
                Column::make('capacity', 'Kapasitas', align: 'right', format: 'number')->priority(1)->card('meta'),
                Column::make('usage', 'Isi', align: 'center')->priority(2)->card('meta')->render(function (Rack $rack) use ($usage) {
                    $row = $usage[$rack->id] ?? null;
                    $qty = (int) ($row->qty ?? 0);

                    return '<span class="font-mono tabular-nums text-text-strong">'.(int) ($row->lots ?? 0).'</span>'
                        .'<span class="text-label-sm text-text-subtle"> / '.$qty.' unit</span>';
                }),
                Column::make('fill', 'Kapasitas Terpakai', align: 'left')->priority(1)->card('meta')->render(function (Rack $rack) use ($usage) {
                    if (! $rack->capacity) {
                        return '<span class="text-label-sm text-text-subtle">tanpa kapasitas</span>';
                    }

                    $percent = min(100, (int) ($usage[$rack->id]->qty ?? 0) / $rack->capacity * 100);

                    return '<div class="flex items-center gap-2">'
                        .'<div class="h-2 w-full overflow-hidden rounded-full bg-canvas">'
                        .'<div class="h-full rounded-full bg-primary" style="width: '.$percent.'%"></div>'
                        .'</div>'
                        .'<span class="w-12 shrink-0 text-right text-label-sm tabular-nums text-text-muted">'
                        .number_format($percent, 1).'%</span></div>';
                }),
                Column::make('is_active', 'Status')->priority(2)->card('badge')->value(function (Rack $rack) {
                    return $rack->is_active ? 'ACTIVE' : 'INACTIVE';
                }),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => array_values(array_filter([
                            [
                                'key' => 'edit',
                                'label' => 'Edit',
                                'route' => 'master.lokasi-rak.edit',
                                'when' => $isOwner,
                            ],
                            [
                                'key' => 'toggle',
                                'label' => 'Nonaktif',
                                'route' => 'master.lokasi-rak.toggle',
                                'method' => 'PATCH',
                                // `toggle` hanya untuk Owner, dan hanya saat rak
                                // sedang aktif; pasangannya, `activate`, menutup
                                // kasus sebaliknya. Sebelumnya `when`-nya
                                // `! $isOwner || $rack->is_active`, yang membuat
                                // tombol "Aktif" selalu tampil untuk Staff --
                                // satu-satunya aksi yang terlihat di tabel rak
                                // untuk mereka, dan selalu 403 karena
                                // `toggle()` di controller menolak non-Owner.
                                // `! $isOwner ||` membuat predikatnya selalu
                                // benar, bukan hanya saat rak aktif.
                                'when' => fn (Rack $rack) => $isOwner && $rack->is_active,
                                'confirm' => [
                                    'title' => 'Nonaktifkan rak?',
                                    'description' => 'Rak harus kosong agar bisa dinonaktifkan.',
                                    'confirm_text' => 'Nonaktifkan',
                                ],
                            ],
                            [
                                'key' => 'activate',
                                'label' => 'Aktifkan',
                                'route' => 'master.lokasi-rak.toggle',
                                'method' => 'PATCH',
                                'when' => fn (Rack $rack) => $isOwner && ! $rack->is_active,
                            ],
                            [
                                'key' => 'destroy',
                                'label' => 'Hapus',
                                'route' => 'master.lokasi-rak.destroy',
                                'method' => 'DELETE',
                                'variant' => 'danger',
                                'confirm' => [
                                    'title' => 'Hapus rak?',
                                    'description' => 'Rak dihapus permanen. Hanya bisa bila tidak berisi stok.',
                                    'confirm_text' => 'Hapus',
                                ],
                                'when' => $isOwner,
                            ],
                        ])),
                    ]),
            ])
            ->filters([
                'type' => ['label' => 'Tipe', 'format' => fn (string $value) => Format::enum($value)],
                // Not a flag: `?active=0` is a real filter here, it means "show
                // the inactive ones", so the chip has to say which of the two.
                'active' => ['label' => 'Rak', 'format' => fn (string $value) => $value === '1' ? 'aktif' : 'tidak aktif'],
            ]);

        if ($isOwner) {
            $table->create(route('master.lokasi-rak.create'), 'Tambah Rak');
        }

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Rak tidak ditemukan' : 'Belum ada rak',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Tambahkan rak pertama seperti A-S1-L1, atau gunakan Impor Excel (Owner).'
        );

        return $this->page('pages.master.lokasi-rak', [
            'table' => $table,
            'totalRacks' => $base->toBase()->count(),
            'activeRacks' => $base->toBase()->where('is_active', true)->count(),
            'totalQty' => (int) $usage->sum('qty'),
            'usedRacks' => $usage->count(),
            'canManage' => $isOwner,
        ], 'Lokasi Rak');
    }

    /**
     * Form cetak label rak (FR-MD-21).
     *
     * Dipisah dari halaman daftar supaya daftar centang rak tidak ikut
     * terfilter oleh pencarian tabel, dan supaya operator bisa mencari rak
     * tanpa mengubah tampilan tabel. Hanya rak aktif: label untuk rak yang
     * sudah dinonaktifkan hanya menambah kertas tanpa ada yang memakainya.
     */
    public function labelForm()
    {
        return $this->page('pages.master.lokasi-rak-label', [
            'racks' => Rack::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'zone']),
        ], 'Cetak Label Rak');
    }

    public function printLabels(PrintRackLabelsRequest $request, LabelPage $page, LabelPrinterSettings $printer)
    {
        $ids = $request->rackIds();
        $template = $request->template();
        $copies = $request->copies();

        $racks = Rack::query()
            ->whereKey($ids)
            ->get()
            // Urutan sesuai pilihan operator, bukan urutan id, supaya label
            // keluar dari printer sesuai urutan yang diminta.
            ->sortBy(fn (Rack $rack) => array_search($rack->id, $ids, true))
            ->values();

        // Label rak memakai kertas yang sama dengan label barang: satu printer,
        // satu setelan cetak. Kalau mode stiker aktif tapi ukuran label rak
        // yang dipilih tidak muat di kertas Owner, `sheetGrid()` mengembalikan
        // `null` dengan peringatan di log -- jadi label rak tetap dicetak pada
        // ukuran yang benar, satu per halaman, bukan dipaksa ke grid yang tidak
        // pas.
        $paperLayout = $printer->layoutFor($template);

        return response()->view('pages.inbound.label-print', [
            'title' => 'Cetak Label Rak',
            'labels' => $page->forRacks($racks, $template, $copies, $paperLayout->grid),
            'total' => $racks->count() * $copies,
            'backUrl' => route('master.lokasi-rak'),
            'activeTemplate' => $template,
            'paperLayout' => $paperLayout,
        ]);
    }

    private function usage()
    {
        return StockLot::query()
            ->selectRaw('rack_id, COUNT(*) as lots, COALESCE(SUM(qty_on_hand), 0) as qty')
            ->whereNotNull('rack_id')
            ->groupBy('rack_id')
            ->get()
            ->keyBy('rack_id');
    }

    public function create()
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.master.lokasi-rak-form', [
            'rack' => new Rack,
            'canManage' => true,
        ], 'Tambah Rak');
    }

    public function store(StoreRackRequest $request)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $rack = Rack::create($this->normalize($request->validated()));
        $this->audit->created($rack);

        return Redirect::route('master.lokasi-rak')->with('toast', [
            'type' => 'success',
            'message' => 'Rak '.$rack->code.' tersimpan.',
        ]);
    }

    public function edit(Rack $rack)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.master.lokasi-rak-form', [
            'rack' => $rack,
            'canManage' => true,
        ], 'Edit Rak');
    }

    public function update(UpdateRackRequest $request, Rack $rack)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $before = $rack->getAttributes();
        $rack->fill($this->normalize($request->validated()))->save();
        $this->audit->updated($rack, $before);

        return Redirect::route('master.lokasi-rak')->with('toast', [
            'type' => 'success',
            'message' => 'Rak '.$rack->code.' diperbarui.',
        ]);
    }

    public function toggleActive(Rack $rack)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        if ($rack->is_active && $this->hasStock($rack)) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Rak tidak dapat dinonaktifkan karena masih berisi stok.',
            ]);
        }

        $rack->update(['is_active' => ! $rack->is_active]);
        $this->audit->log('ACTIVE', class_basename($rack), $rack->getKey(), ['is_active' => ! $rack->is_active], ['is_active' => $rack->is_active]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Rak '.$rack->code.' '.($rack->is_active ? 'diaktifkan' : 'dinonaktifkan').'.',
        ]);
    }

    public function destroy(Rack $rack)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        if ($this->hasStock($rack)) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Rak tidak dapat dihapus karena masih berisi stok.',
            ]);
        }

        $this->audit->delete($rack);
        $rack->delete();

        return back()->with('toast', ['type' => 'info', 'message' => 'Rak '.$rack->code.' dihapus.']);
    }

    private function hasStock(Rack $rack): bool
    {
        return StockLot::query()
            ->where('rack_id', $rack->id)
            ->where('qty_on_hand', '>', 0)
            ->exists();
    }

    private function normalize(array $data): array
    {
        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }
}
