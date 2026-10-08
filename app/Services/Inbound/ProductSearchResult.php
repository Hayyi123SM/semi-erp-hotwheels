<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Models\Product;

/**
 * Satu produk dilihat dari picker Stock In Pribadi.
 *
 * Bentuknya sengaja bukan `Product`. Picker hanya butuh identitas untuk menambah
 * baris -- primary key untuk disimpan, nama dan seri untuk ditampilkan, kode
 * casting sebagai pengganti SKU (tabel `products` tidak punya kolom `sku`; SKU
 * hanya hidup di `stock_lots` dan baru ada setelah barang diterima) -- dan
 * barcode pabrik untuk hasil pindai.
 *
 * Kolom lain (`photos`, `tags`, `needs_review`, dll) tidak ikut: yang melintasi
 * ke klien adalah tepat yang dibutuhkan layar, dan menambah kolom ke `products`
 * tidak diam-diam ikut mengirimnya.
 */
final readonly class ProductSearchResult
{
    /**
     * @param  int  $productId  primary key `products`, dibaca kembali server saat menyimpan
     * @param  string  $name  nama produk
     * @param  string  $series  seri produk; string kosong kalau produknya tidak punya
     * @param  string  $castingCode  kode casting; string kosong kalau belum diisi
     * @param  string  $barcode  barcode pabrik (`factory_barcode_ref`); string kosong kalau belum diisi
     */
    public function __construct(
        public int $productId,
        public string $name,
        public string $series,
        public string $castingCode,
        public string $barcode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'name' => $this->name,
            'series' => $this->series,
            'casting_code' => $this->castingCode,
            'barcode' => $this->barcode,
        ];
    }

    /** Bangun dari produk yang relasi serinya sudah dimuat. */
    public static function fromProduct(Product $product): self
    {
        return new self(
            productId: (int) $product->getKey(),
            name: $product->name,
            series: $product->series?->name ?? '',
            castingCode: (string) ($product->casting_code ?? ''),
            barcode: (string) ($product->factory_barcode_ref ?? ''),
        );
    }
}
