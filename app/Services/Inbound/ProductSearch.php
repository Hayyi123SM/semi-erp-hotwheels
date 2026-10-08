<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Pos\ProductLookup;
use App\Support\Sql\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pencarian produk untuk picker Stock In Pribadi.
 *
 * Berbeda dari {@see ProductLookup}, yang mencari lot: picker
 * ini dipakai sebelum barang diterima, jadi belum tentu ada satu lot pun untuk
 * produk yang dicari -- dan `products` bahkan tidak punya kolom `sku`. Yang
 * dicari adalah baris `products`, dengan `casting_code` sebagai identitas
 * pengganti SKU.
 *
 * Hanya produk `ACTIVE` yang dikembalikan, konsisten dengan dropdown lama
 * sebelum halaman ini dipindai ke popup: produk nonaktif memang tidak boleh
 * masuk daftar terima.
 */
class ProductSearch
{
    /**
     * Batas hasil pencarian lewat teks.
     *
     * Sama dengan batas picker kasir: daftar ini discroll dengan jari di tablet,
     * dan 30 baris sudah lebih dari yang pernah dipilih dalam satu penerimaan.
     */
    private const SEARCH_LIMIT = 30;

    /**
     * Panjang minimum untuk mengetik.
     *
     * Harus sama dengan batas klien di `product-picker.js` (`MIN_TERM_LENGTH`).
     * Kalau lebih pendek di sini, pengguna mengetik satu huruf, server sudah
     * menjawab, lalu pencarian berikutnya berbeda bentuk -- dan memotong kueri
     * yang valid secara diam-diam membuat produk yang jelas ada terlihat hilang.
     */
    private const MIN_TERM_LENGTH = 2;

    /**
     * Produk yang cocok dengan teks yang diketik.
     *
     * @return Collection<int, ProductSearchResult>
     */
    public function search(?string $term): Collection
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return new Collection;
        }

        $like = LikePattern::contains($term);

        $products = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->with('series')
            // Kode casting dan barcode pabrik ikut dicari: keduanya identitas
            // yang lebih pendek dari nama, dan operator lebih sering menempel
            // kode itu daripada mengetik nama lengkap produk.
            ->where(function (Builder $group) use ($like): void {
                $group->whereRaw(LikePattern::clause('`products`.`name`'), [$like])
                    ->orWhereRaw(LikePattern::clause('`products`.`casting_code`'), [$like])
                    ->orWhereRaw(LikePattern::clause('`products`.`factory_barcode_ref`'), [$like])
                    ->orWhereHas('series', fn (Builder $series) => $series
                        ->whereRaw(LikePattern::clause('`name`'), [$like])
                        ->orWhereRaw(LikePattern::clause('`code`'), [$like]));
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return $products
            ->map(fn (Product $product): ProductSearchResult => ProductSearchResult::fromProduct($product))
            ->values();
    }

    /**
     * Produk yang kode pabriknya atau SKU lot-nya persis sama dengan kode hasil pindai.
     *
     * Pencocokannya persis, bukan `contains`, berbeda dari pencarian teks: kode
     * yang keluar dari scanner selalu lengkap, dan `contains` atas barcode akan
     * memilih produk yang tidak dimaksud hanya karena satu kode adalah akhiran
     * dari kode lain. `LIKE` tanpa wildcard dipakai alih-alih `=` supaya
     * keberkasannya sama di MySQL dan SQLite: `=` membedakan huruf besar-kecil
     * di SQLite tapi tidak di MySQL, sementara `LIKE` tidak membedakan keduanya.
     * Wildcard tidak ikut -- `LikePattern::escape()` mengecualikan kode yang
     * kebetulan memuat `%` atau `_`.
     *
     * Tiga kolom dicoba karena tiga sumber pemindaian yang berbeda di area ini:
     * barcode pabrik yang tercetak di kemasan, kode casting yang tertempel di
     * kartu, dan label WMS lama (SKU lot) untuk produk yang sudah pernah diterima.
     *
     * @return Collection<int, ProductSearchResult>
     */
    public function findByBarcode(string $code): Collection
    {
        $code = trim($code);

        if ($code === '') {
            return new Collection;
        }

        $products = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->with('series')
            ->where(function (Builder $group) use ($code): void {
                // Pola tanpa wildcard = kecocokan persis, tapi lewat jalur yang
                // sama dengan pencarian teks supaya karakter LIKE di dalam kode
                // (mis. `%` pada barcode) ikut diecualikan.
                $group->whereRaw(LikePattern::clause('`products`.`factory_barcode_ref`'), [LikePattern::escape($code)])
                    ->orWhereRaw(LikePattern::clause('`products`.`casting_code`'), [LikePattern::escape($code)])
                    ->orWhereHas('stockLots', fn (Builder $lot) => $lot
                        ->whereRaw(LikePattern::clause('`stock_lots`.`sku`'), [LikePattern::escape($code)]));
            })
            // Produk yang cocok lewat barcode pabrik didahulukan atas yang
            // cocok lewat label lot lama, supaya scan yang sama selalu jatuh ke
            // produk yang sama ketika keduanya kebetulan ada. Jalur yang sama
            // dengan klausa WHERE di atas, supaya urutannya tidak berbeda
            // antara MySQL dan SQLite.
            ->orderByRaw(
                'CASE WHEN '.LikePattern::clause('`products`.`factory_barcode_ref`').' THEN 0 ELSE 1 END',
                [LikePattern::escape($code)],
            )
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return $products
            ->map(fn (Product $product): ProductSearchResult => ProductSearchResult::fromProduct($product))
            ->values();
    }
}
