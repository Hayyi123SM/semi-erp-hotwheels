<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\StockLot;

/**
 * Satu lot stok dilihat dari sisi kasir: cukup untuk mengisi keranjang, dan
 * tidak lebih.
 *
 * Bentuknya sengaja bukan `StockLot`. Yang tampil di picker adalah gabungan
 * beberapa tabel -- SKU, nama produk, harga jual, kepemilikan, sisa unit, dan
 * status label -- dan meneruskan model ke Alpine berarti mengirim seluruh baris ke
 * browser, termasuk kolom yang tidak pernah tampil di layar ini. Batasnya
 * ditarik di sini supaya yang melintasi ke klien adalah tepat yang dibutuhkan,
 * dan menambahkan kolom ke `stock_lots` tidak diam-diam ikut mengirimnya.
 *
 * `ownership` dan `statusType` sudah diterjemahkan ke nilai yang bisa langsung
 * dipakai `x-ui.badge-ownership` dan badge status, bukan nilai enum mentah.
 * Kalau enum mentah yang dikirim, setiap pemanggil harus tahu aturan
 * pemetaannya, dan aturan itu akan ditulis ulang -- dengan variations -- di
 * setiap tempat.
 */
final readonly class ProductLookupResult
{
    /**
     * @param  int  $id  primary key lot, dibaca kembali server saat menyimpan
     * @param  string  $sku  SKU internal, yang tercetak pada label WMS
     * @param  string  $name  nama produk
     * @param  string  $series  seri produk; string kosong kalau produknya tidak punya
     * @param  int  $price  harga jual dari `stock_lots.list_price`
     * @param  string  $ownership  nilai untuk `x-ui.badge-ownership`: TITIP, PRIBADI, atau KARANTINA
     * @param  string  $consignor  nama penitip; string kosong kalau barang milik toko
     * @param  int  $stock  unit yang masih ada di tangan
     * @param  int  $pendingLabels  job label yang belum keluar, jadi barcode barang ini belum bisa dipindai
     * @param  string  $status  nilai `stock_lots.status` apa adanya, untuk ditapis dan ditampilkan
     * @param  string  $statusType  nada badge status: success, warning, atau error
     * @param  bool  $sellable  boleh masuk keranjang, menurut {@see StockLot::isSellable()}
     * @param  string|null  $rack  lokasi rak, kalau sudah ditata
     */
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public string $series,
        public int $price,
        public string $ownership,
        public string $consignor,
        public int $stock,
        public int $pendingLabels,
        public string $status,
        public string $statusType,
        public bool $sellable,
        public ?string $rack,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'series' => $this->series,
            'price' => $this->price,
            'ownership' => $this->ownership,
            'consignor' => $this->consignor,
            'stock' => $this->stock,
            'pendingLabels' => $this->pendingLabels,
            'status' => $this->status,
            'statusType' => $this->statusType,
            'sellable' => $this->sellable,
            'rack' => $this->rack,
        ];
    }
}
