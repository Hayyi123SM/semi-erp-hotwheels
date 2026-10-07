<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\LabelStatus;
use App\Enums\LotStatus;
use App\Models\StockLot;
use App\Services\Inventory\StockLotSearch;
use App\Support\Format;
use App\Support\Sql\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pencarian produk untuk kasir: dipindai dari barcode, atau dicari lewat nama.
 *
 * Dipisah dari {@see StockLotSearch} karena keduanya
 * mencari hal yang berbeda. `StockLotSearch` untuk operator di area Inbound, dan
 * urutannya bothering label yang hilang: lot yang paling mungkin dicari adalah lot
 * yang labelnya belum keluar, bukan lot yang namanya paling cocok. Kasir tidak
 * berinteraksi dengan label: yang dia butuhkan adalah barang yang bisa dijual
 * sekarang, dan hal itu tidak boleh bergantung pada antrean cetakan.
 *
 * Karena itu kuerinya tidak mewarisi urutan `StockLotSearch`. `pendingLabels`
 * tetap ikut dihitung, tapi hanya sebagai keterangan: barcode barang yang labelnya
 * belum keluar memang tidak akan terpindai, dan itu perlu terlihat oleh kasir
 * sebelum ia memasukkan barang itu ke keranjang.
 */
class ProductLookup
{
    /**
     * Batas hasil pencarian lewat nama.
     *
     * Lebih kecil dari 50 milik `StockLotSearch` karena yang di sini dimuat ke
     * daftar yang kasir scroll dengan jari di tablet. 30 baris sudah lebih dari
     * yang pernah dipilih, dan sisanya hanya memperpanjang daftar yang harus
     * digulir untuk sampai ke barang yang sedang dicari.
     */
    private const SEARCH_LIMIT = 30;

    /**
     * Lot yang tepat untuk satu kode yang dipindai.
     *
     * Tanpa batas panjang minimum, berbeda dari pencarian lewat nama: barcode yang
     * keluar dari scanner selalu lengkap, jadi pendeknya kode berarti memang tidak
     * ada lot itu -- bukan berarti kasir belum selesai mengetik. Menahan pencarian
     * sampai dua huruf akan membuat lot ber-SKU pendek mustahil ditemukan, dan
     * barang itu akan terlihat oleh kasir sebagai barang yang hilang.
     *
     * Pencocokan tetap `contains`, bukan persis. Label yang tercetak bisa punya
     * awalan atau akhiran dari mesin, dan barcode yang terpindai membawa seluruh
     * label itu; persis akan mengembalikan nol hasil untuk barang yang jelas ada di
     * tangan kasir.
     */
    public function findByBarcode(string $code): ?ProductLookupResult
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return $this->results(
            StockLot::query()
                ->whereRaw(LikePattern::clause('`sku`'), [LikePattern::contains($code)])
                // Lot yang persis sama kode-nya didahulukan kalau ada lebih dari
                // satu yang memuatnya, supaya scan tidak pernah memilih lot yang
                // tak sengaja cocok pada barcode milik lot lain.
                ->orderByRaw('CASE WHEN `sku` = ? THEN 0 ELSE 1 END', [$code])
                ->orderBy('sku'),
        )->first();
    }

    /**
     * Lot yang cocok dengan teks yang diketik kasir.
     *
     * @return Collection<int, ProductLookupResult>
     */
    public function search(?string $query): Collection
    {
        $query = trim((string) $query);

        if (mb_strlen($query) < 2) {
            return new Collection;
        }

        return $this->results(
            StockLot::query()
                ->where(function (Builder $group) use ($query): void {
                    $like = LikePattern::contains($query);

                    $group->whereRaw(LikePattern::clause('`stock_lots`.`sku`'), [$like])
                        ->orWhereHas('product', fn (Builder $product) => $product->whereRaw(
                            LikePattern::clause('`name`'),
                            [$like],
                        ))
                        ->orWhereHas('consignor', fn (Builder $consignor) => $consignor->whereRaw(
                            LikePattern::clause('`name`'),
                            [$like],
                        ));
                })
                // Yang masih bisa dijual didahulukan, baru sisanya. Daftar yang
                // menaruh barang habis lebih tinggi dari barang yang bisa langsung
                // ditabrak membuat kasir menggulir untuk menemukan barang yang
                // sedang laku.
                ->orderByRaw('CASE WHEN `stock_lots`.`status` = ? AND `stock_lots`.`qty_on_hand` > 0 THEN 0 ELSE 1 END', [
                    LotStatus::Available->value,
                ])
                ->orderBy('stock_lots.sku')
                ->limit(self::SEARCH_LIMIT),
        );
    }

    /**
     * Bentuk lot jadi hasil yang dikirim ke klien, lengkap dengan relasi yang
     * dibaca di layar picker.
     *
     * Relasi dimuat di sini, bukan diserahkan ke pemanggil: setiap pemanggil butuh
     * kolom yang sama, dan membiarkan masing-masing memanggil `with()` sendiri
     * berarti daftar itu akan berbeda antar layar.
     *
     * @param  Builder<StockLot>  $query
     * @return Collection<int, ProductLookupResult>
     */
    private function results(Builder $query): Collection
    {
        return $query
            ->with(['product.series', 'consignor', 'rack'])
            ->withCount([
                'labelPrintJobs as pending_labels_count' => fn (Builder $jobs) => $jobs->whereIn('status', [
                    LabelStatus::Queued->value,
                    LabelStatus::Sent->value,
                    LabelStatus::Failed->value,
                ]),
            ])
            ->get()
            ->map(fn (StockLot $lot): ProductLookupResult => new ProductLookupResult(
                id: (int) $lot->getKey(),
                sku: $lot->sku,
                name: $lot->product?->name ?? $lot->sku,
                series: $lot->product?->series?->name ?? '',
                price: $lot->list_price,
                ownership: Format::ownershipType($lot->owner_type),
                consignor: $lot->consignor?->name ?? '',
                stock: $lot->qty_on_hand,
                pendingLabels: (int) $lot->pending_labels_count,
                status: $lot->status->value,
                statusType: Format::statusType($lot->status),
                sellable: $lot->isSellable(),
                rack: $lot->rack?->code,
            ));
    }
}
