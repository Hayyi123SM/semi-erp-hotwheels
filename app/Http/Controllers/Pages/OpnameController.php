<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\HitungBarisRequest;
use App\Http\Requests\Inventory\MulaiOpnameRequest;
use App\Http\Requests\Inventory\ReviewBarisRequest;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Rack;
use App\Services\Inventory\OpnameService;
use App\Support\Format;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Halaman Stok Opname beserta empat tombolnya.
 *
 * Seluruh perubahan keadaan diserahkan ke `OpnameService`; controller hanya
 * memilih apa yang dikirim ke layar. Pemilihan itu sendiri adalah salah satu
 * aturan fitur: selama sesi menghitung, kolom sistem tidak pernah ikut
 * dipilih, sehingga angkanya tidak ada di memori halaman ini sama sekali.
 * Menyembunyikan kolomnya di template akan tetap mengirimnya ke peramban, dan
 * blind count yang angka sistemnya ada di HTML bukan blind count.
 */
class OpnameController extends Controller
{
    /**
     * Kolom baris yang boleh dikirim saat sesi masih menghitung.
     *
     * `system_qty`, `diff_qty`, dan `reason` sengaja tidak ada di daftar ini.
     * Angka pertama adalah yang disembunyikan; angka kedua bocor lewat
     * perbandingannya; ketiga hanya ada setelah persetujuan, jadi ketika sesi
     * menghitung ia selalu null dan hanya memancing pertanyaan.
     */
    private const array BLIND_COLUMNS = [
        'id',
        'opname_id',
        'lot_id',
        'counted_qty',
        'counted_at',
        'counted_by',
        'status',
        'created_at',
        'updated_at',
    ];

    public function index(Request $request)
    {
        $open = Opname::query()
            ->whereIn('status', OpnameStatus::openValues())
            ->latest('id')
            ->first();

        $blind = $open !== null && $open->status === OpnameStatus::Counting;

        if ($open !== null) {
            $lines = $open->lines()->with(['lot.product.series', 'lot.rack', 'counter', 'approver']);

            if ($blind) {
                $lines->select(self::BLIND_COLUMNS);
            }

            $open->setRelation('rows', $lines->orderBy('id')->get());
        }

        return $this->page('pages.inventory.stok-opname', [
            'open' => $open,
            'blind' => $blind,
            'history' => $this->history($open),
            'racks' => Rack::query()->orderBy('code')->get(['id', 'code', 'zone']),
            'scopes' => OpnameScope::cases(),
            // Dua daftar alasan datang dari service, bukan dari enum:
            // arah selisih menentukan alasan yang masuk akal, dan aturan itu
            // hanya boleh ada di satu tempat -- di service yang juga
            // menegakkannya.
            'plusReasons' => OpnameService::PLUS_REASONS,
            'minusReasons' => OpnameService::MINUS_REASONS,
        ], 'Stok Opname');
    }

    public function store(MulaiOpnameRequest $request, OpnameService $service)
    {
        $opname = $service->start(
            $request->scope(),
            $request->rack(),
            $request->sku(),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s dimulai untuk %d lot. Hitung fisik satu per satu; angka sistem tetap tersembunyi sampai sesi diajukan.',
                $opname->opname_no,
                $opname->lines()->count(),
            ),
        ]);
    }

    public function hitung(Opname $opname, OpnameLine $line, HitungBarisRequest $request, OpnameService $service)
    {
        $line = $service->count($opname, $line, $request->countedQty(), $request->user());

        // Balasan sengaja tidak memuat ekspektasi atau selisih: sesi masih
        // menghitung, dan layar konfirmasi adalah bagian dari layar juga.
        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Hitungan tersimpan: %d unit untuk %s.',
                $request->countedQty(),
                $line->lot?->sku ?? ('#'.$line->lot_id),
            ),
        ]);
    }

    /**
     * Tambah satu ke hitungan fisik, dipanggil oleh pindai SKU di layar
     * (FR-IC-20).
     *
     * Panggilan lewat fetch (JSON) memakai balasan ringkas: qty terbaru dan
     * status baris, supaya peramban hanya mengganti dua sel yang berubah tanpa
     * memuat ulang halaman. Balasan itu tetap tidak memuat ekspektasi ataupun
     * selisih -- sesi masih menghitung, dan aturan blind count berlaku untuk
     * setiap respons yang dikirim selama sesi menghitung, bukan hanya respons
     * halaman penuh.
     */
    public function tambah(Opname $opname, OpnameLine $line, Request $request, OpnameService $service)
    {
        $line = $service->increment($opname, $line, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'line_id' => $line->getKey(),
                'counted_qty' => $line->counted_qty,
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
                'status_type' => Format::statusType($line->status->value),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Hitungan bertambah: %d unit untuk %s.',
                $line->counted_qty,
                $line->lot?->sku ?? ('#'.$line->lot_id),
            ),
        ]);
    }

    public function ajukan(Opname $opname, Request $request, OpnameService $service)
    {
        $opname = $service->submit($opname, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s diajukan. Selisih kini menunggu persetujuan Owner.',
                $opname->opname_no,
            ),
        ]);
    }

    public function review(Opname $opname, OpnameLine $line, ReviewBarisRequest $request, OpnameService $service)
    {
        $service->review(
            $opname,
            $line,
            $request->decision(),
            $request->reason(),
            $request->approver(),
        );

        $sku = $line->lot?->sku ?? ('#'.$line->lot_id);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $request->decision() === 'APPROVE'
                ? sprintf('Selisih %s disetujui dan diterapkan ke stok.', $sku)
                : sprintf('Selisih %s ditolak; stok tidak diubah.', $sku),
        ]);
    }

    public function batal(Opname $opname, Request $request, OpnameService $service)
    {
        $service->cancel($opname, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Sesi %s dibatalkan.', $opname->opname_no),
        ]);
    }

    /**
     * Riwayat sesi: sepuluh terakhir yang tidak sedang terbuka, lengkap dengan
     * jumlah baris dan jumlah baris berselisih -- dua angka yang menentukan
     * apakah sesi itu layak dibuka kembali isinya atau memang sudah selesai.
     *
     * @return Collection<int, Opname>
     */
    private function history(?Opname $open): Collection
    {
        return Opname::query()
            ->when(
                $open !== null,
                fn ($query) => $query->where('id', '!=', $open->getKey()),
            )
            ->withCount(['lines', 'lines as selisih_count' => fn ($lines) => $lines->where('diff_qty', '!=', 0)])
            ->with('creator')
            ->latest('id')
            ->limit(10)
            ->get();
    }
}
