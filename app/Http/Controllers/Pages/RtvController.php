<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Enums\LotStatus;
use App\Enums\OpnameStatus;
use App\Enums\OwnerType;
use App\Enums\QuarantineStatus;
use App\Enums\RackType;
use App\Enums\RtvStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BuatRtvRequest;
use App\Http\Requests\Inventory\ScanRtvRequest;
use App\Http\Requests\Inventory\SetujuiRtvRequest;
use App\Models\Consignor;
use App\Models\OpnameLine;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\RtvNote;
use App\Models\StockLot;
use App\Services\Inventory\RtvService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Halaman Retur Penitip beserta empat tombolnya (FR-IC-30..35).
 *
 * Seluruh perubahan keadaan diserahkan ke `RtvService`; controller hanya
 * memilih apa yang dikirim ke layar. Pilihan itu sendiri adalah bagian dari
 * aturan fitur:
 *
 * 1. **Langkah ditentukan server.** Tidak ada tombol "lanjut" yang berpindah
 *    langkah di peramban -- langkah berikutnya terbuka hanya setelah server
 *    menerima hasil langkah sebelumnya, sehingga tombol yang masih terkunci di
 *    layar tidak bisa ditinggalkan begitu saja.
 * 2. **Kandidat hanya muncul saat belum ada sesi.** Sementara satu dokumen
 *    terbuka, formulir pemilihan disembunyikan sama sekali, bukan hanya
 *    dinonaktifkan: mengirim `lot_id` dari formulir yang "tidak terlihat"
 *    adalah pintu belakang paling sederhana untuk membuat sesi kedua.
 * 3. **Baris yang terikat kasus ditandai di layar.** FR-IC-35 tetap ditegakkan
 *    oleh service; menampilkannya di sini agar orang tidak mengetik qty lalu
 *    baru mengetahui alasannya setelah formulir ditolak.
 */
class RtvController extends Controller
{
    /**
     * Ambang aging yang ditawarkan pada filter kandidat (FR-IC-31).
     *
     * Ditempatkan di sini, bukan di form, supaya daftarnya yang membatasi
     * nilai yang diterima: angka yang tidak dipilih dari daftar tidak
     * mengubah hasil query sama sekali, alih-alih menjadi ambang yang tidak
     * pernah cocok dan terlihat sebagai tabel kosong.
     *
     * @var list<int>
     */
    private const array AGING_CHOICES = [30, 60, 90];

    /**
     * Aging yang dipakai saat kandidat diminta (FR-IC-31).
     */
    private const int DEFAULT_AGING = 60;

    public function index(Request $request)
    {
        $open = RtvNote::query()
            ->whereIn('status', RtvStatus::openValues())
            ->with(['lines.lot.product.series', 'lines.lot.rack', 'consignor', 'creator'])
            ->latest('id')
            ->first();

        $penitip = $request->integer('penitip');
        $aging = $this->aging($request);
        $candidates = null;
        $blocked = collect();

        if ($open === null && $penitip > 0) {
            $candidates = StockLot::query()
                ->where('owner_type', OwnerType::Consign->value)
                ->where('consignor_id', $penitip)
                ->where('status', LotStatus::Available->value)
                ->where('qty_on_hand', '>', 0)
                ->with(['product.series', 'rack'])
                // Yang tertua lebih dulu: aging adalah alasan memilih SKU ini,
                // jadi urutan tabel mengikuti umurnya, bukan id masuknya.
                ->orderBy('created_at')
                ->get();

            $blocked = $this->blockedLots($candidates);
        }

        return $this->page('pages.inventory.retur-rtv', [
            'open' => $open,
            'step' => $this->step($open),
            'consignors' => Consignor::query()->orderBy('consignor_code')->get(['id', 'consignor_code', 'name']),
            'candidates' => $candidates,
            'blocked' => $blocked,
            'penitip' => $penitip > 0 ? $penitip : null,
            'aging' => $aging,
            'agingChoices' => self::AGING_CHOICES,
            'stagingRack' => $this->stagingRack(),
            'history' => $this->history($open),
        ], 'Retur / RTV');
    }

    public function store(BuatRtvRequest $request, RtvService $service)
    {
        $note = $service->create(
            $request->consignor(),
            $request->quantities(),
            $request->reason(),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s dibuat: %d SKU · %d unit. Pindahkan barang ke rak staging sebelum memindai.',
                $note->rtv_no,
                $note->lines()->count(),
                $note->units(),
            ),
        ]);
    }

    public function staging(RtvNote $rtv, RtvService $service)
    {
        $note = $service->moveToStaging($rtv);

        // Rak dibaca ulang dari note yang baru saja diperbarui, bukan dari
        // parameter: kode rak yang ditulis orang ke pesan toast adalah kode
        // yang benar-benar dipakai, bukan kode yang diingat sebelum pindah.
        $staging = $this->stagingRack();

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Barang %s dipindah ke rak %s. Pindai tiap unit untuk verifikasi.',
                $note->rtv_no,
                $staging?->code ?? 'staging',
            ),
        ]);
    }

    public function scan(RtvNote $rtv, ScanRtvRequest $request, RtvService $service)
    {
        $line = $service->scan($rtv, $request->sku(), $request->user());

        $message = $line->isFullyVerified()
            ? sprintf('%s selesai dipindai (%d unit).', $line->lot?->sku ?? $line->lot_id, $line->qty)
            : sprintf('Unit %s dipindai (%d dari %d).', $line->lot?->sku ?? $line->lot_id, $line->verified_qty, $line->qty);

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    public function approve(RtvNote $rtv, SetujuiRtvRequest $request, RtvService $service)
    {
        $note = $service->approve($rtv, $request->approver());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'RTV %s dieksekusi: %d unit keluar stok dan tercatat sebagai gerakan RTV.',
                $note->rtv_no,
                $note->units(),
            ),
        ]);
    }

    public function batal(RtvNote $rtv, Request $request, RtvService $service)
    {
        $note = $service->cancel($rtv, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Sesi %s dibatalkan.', $note->rtv_no),
        ]);
    }

    /**
     * Langkah yang sedang terbuka, dihitung dari keadaan dokumen -- bukan dari
     * riwayat klik orang di peramban.
     */
    private function step(?RtvNote $open): int
    {
        if ($open === null) {
            return 1;
        }

        $verified = $open->status === RtvStatus::Verifying
            && $open->lines->every(fn ($line): bool => $line->isFullyVerified());

        return $verified ? 3 : 2;
    }

    /**
     * Aging yang diminta pembaca, dibatasi ke pilihan yang memang ditawarkan.
     */
    private function aging(Request $request): int
    {
        $aging = $request->integer('aging');

        return in_array($aging, self::AGING_CHOICES, true) ? $aging : self::DEFAULT_AGING;
    }

    /**
     * Pasangan `lot_id => alasan` untuk kandidat yang tidak boleh dipilih
     * (FR-IC-35). Pengecekan sebenarnya tetap di service; ini hanya agar
     * layar menyebut alasannya sebelum formulir dikirim.
     *
     * @param  Collection<int, StockLot>  $candidates
     * @return SupportCollection<int, string>
     */
    private function blockedLots(Collection $candidates): SupportCollection
    {
        $ids = $candidates->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $byOpname = OpnameLine::query()
            ->whereIn('lot_id', $ids)
            ->whereHas('opname', fn ($opnames) => $opnames->whereIn('status', OpnameStatus::openValues()))
            ->pluck('lot_id');

        $byQuarantine = QuarantineCase::query()
            ->whereIn('assigned_lot_id', $ids)
            ->whereIn('status', QuarantineStatus::openValues())
            ->pluck('assigned_lot_id');

        $blocked = collect();

        foreach ($candidates as $lot) {
            $reason = match (true) {
                $byOpname->contains($lot->id) => 'Sesi opname terbuka',
                $byQuarantine->contains($lot->id) => 'Kasus karantina terbuka',
                default => null,
            };

            if ($reason !== null) {
                $blocked->put($lot->id, $reason);
            }
        }

        return $blocked;
    }

    /**
     * Sepuluh dokumen yang sudah selesai, lengkap dengan jumlah baris dan
     * total unitnya -- dua angka yang menentukan apakah baris itu layak
     * dibuka kembali isinya.
     *
     * @return Collection<int, RtvNote>
     */
    private function history(?RtvNote $open): Collection
    {
        return RtvNote::query()
            ->whereNotIn('status', RtvStatus::openValues())
            ->when(
                $open !== null,
                fn ($query) => $query->where('id', '!=', $open->getKey()),
            )
            ->withCount('lines')
            ->withSum('lines', 'qty')
            ->with(['consignor', 'creator'])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * Rak staging yang sedang aktif, untuk ditampilkan di layar verifikasi.
     */
    private function stagingRack(): ?Rack
    {
        return Rack::query()
            ->where('type', RackType::RtvStaging->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->first();
    }
}
