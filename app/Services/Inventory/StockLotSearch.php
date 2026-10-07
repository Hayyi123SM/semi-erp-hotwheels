<?php

namespace App\Services\Inventory;

use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Models\StockLot;
use App\Support\Sql\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pencarian lot untuk lookup cetak ulang (FR-IB-21).
 *
 * Dipisah dari controller karena aturan pencariannya punya sifat sendiri: apa
 * yang boleh dicari, dan bagaimana hasilnya diurutkan. Urutannya penting --
 * lot yang paling mungkin dicari operator (punya label yang belum keluar)
 * harus muncul paling atas, bukan hanya yang paling cocok secara teks.
 */
class StockLotSearch
{
    /**
     * Panjang minimal input pencarian.
     *
     * Tanpa batas ini, satu huruf sudah menarik sebagian besar tabel `stock_lots`
     * beserta `products` dan `consignors`-nya. Halaman ini dijalankan operator
     * di tablet sambil memegang barang, jadi query yang berat akan terasa
     * sebagai lag saat mengetik.
     */
    private const MIN_QUERY_LENGTH = 2;

    private const LIMIT = 50;

    /**
     * Cari lot berdasarkan SKU, nama produk, atau nama penitip.
     *
     * @return Collection<int, StockLot>
     */
    public function search(?string $query): Collection
    {
        $query = trim((string) $query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return new Collection;
        }

        // `_` yang diketik operator dicocokkan sebagai karakter biasa, bukan
        // "satu huruf apa saja": tanpa itu, mengetik `CN01_` akan ikut cocok
        // dengan `CN01A`. See {@see LikePattern} for the escape character.
        $like = LikePattern::contains($query);

        return StockLot::query()
            ->with(['product.series', 'consignment', 'consignor', 'rack'])
            ->withCount([
                // Job yang belum keluar. Ini yang membuat lot dengan label
                // hilang terlihat lebih dulu: lot itu punya confirmed job tapi
                // tidak punya yang masih antre.
                'labelPrintJobs as pending_labels_count' => fn (Builder $query) => $query
                    ->whereIn('status', ['QUEUED', 'SENT', 'FAILED']),
            ])
            // Jumlah cetakan yang sudah dialokasikan tapi belum keluar. Tanpa
            // ini, kolom "sisa" di layar terlihat seperti masih lega padahal
            // cetakannya masih di printer -- dan operator akan menambah
            // cetakan yang sebenarnya tidak muat.
            ->withSum([
                'labelPrintJobs as in_flight_labels' => fn (Builder $query) => $query
                    ->whereIn('status', [LabelStatus::Queued->value, LabelStatus::Sent->value]),
            ], 'copies')
            // Percobaan cetak ulang hari ini, per lot. Dipakai untuk memberi
            // tahu operator sebelum dia menekan tombol, bukan untuk menolak --
            // keputusan ada di server.
            ->withCount([
                'labelPrintJobs as reprints_today' => fn (Builder $query) => $query
                    ->whereIn('reason', LabelReason::reprints())
                    ->where('created_at', '>=', now()->startOfDay()),
            ])
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw(LikePattern::clause('`stock_lots`.`sku`'), [$like])
                    ->orWhereHas('product', fn (Builder $product) => $product->whereRaw(LikePattern::clause('`name`'), [$like]))
                    ->orWhereHas('consignor', fn (Builder $consignor) => $consignor->whereRaw(LikePattern::clause('`name`'), [$like]))
                    ->orWhereHas(
                        'consignment',
                        fn (Builder $consignment) => $consignment->whereRaw(LikePattern::clause('`doc_no`'), [$like]),
                    );
            })
            // Lot yang labelnya masih tertunda dulu, lalu yang berlabel paling
            // sering dicetak ulang (kemungkinan besar yang dicari), lalu SKU.
            // `reprint_count` yang tinggi adalah sinyal barang yang labelnya
            // sering hilang, bukan barang yang paling penting.
            ->orderByRaw('CASE WHEN pending_labels_count > 0 THEN 0 ELSE 1 END')
            ->orderByDesc('stock_lots.reprint_count')
            ->orderBy('stock_lots.sku')
            ->limit(self::LIMIT)
            ->get();
    }
}
