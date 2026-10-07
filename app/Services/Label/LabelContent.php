<?php

declare(strict_types=1);

namespace App\Services\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\OwnerType;
use App\Models\StockLot;
use App\Support\Format;

/**
 * Semua yang perlu diketahui satu label, dibekukan jadi nilai biasa.
 *
 * Kenapa tidak merender langsung dari `StockLot`: harga jual, kondisi, dan nama
 * produk bisa berubah setelah label pertama dicetak. Kalau renderer membaca
 * model langsung, re-print karena `PRICE_CHANGE` diam-diam akan menghasilkan
 * label berbeda dari yang dicetak sebelumnya -- dan tidak ada cara untuk tahu
 * label yang sebenarnya keluar dari printer. Kolom `payload` pada
 * `label_print_jobs` menyimpan bentuk ini apa adanya, jadi job lama bisa
 * dicetak ulang persis seperti yang pertama kali.
 *
 * Konstruktor sengaja `private` dan ada `fromLot()`-nya: isinya hanya boleh
 * berasal dari model, supaya tidak ada jalan untuk membuat label dengan
 * bentuk data yang tidak bisa terjadi.
 */
final readonly class LabelContent
{
    /**
     * @param  int  $quantity  Berapa label untuk unit ini; dicetak sebagai penanda `1/12`.
     */
    private function __construct(
        public string $sku,
        public string $productName,
        public string $ownerCode,
        public OwnerType $ownerType,
        public CardCondition $cardCondition,
        public BlisterCondition $blisterCondition,
        public int $listPrice,
        public int $quantity,
    ) {}

    public static function fromLot(StockLot $lot): self
    {
        return new self(
            sku: $lot->sku,
            productName: self::shortProductName($lot),
            ownerCode: $lot->owner_code,
            ownerType: $lot->owner_type,
            cardCondition: $lot->card_condition,
            blisterCondition: $lot->blister_condition,
            listPrice: $lot->list_price,
            quantity: $lot->qty_received,
        );
    }

    /**
     * Bentuk yang disimpan di `payload`, untuk job yang sudah pernah dicetak.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            sku: (string) $payload['sku'],
            productName: (string) $payload['product_name'],
            ownerCode: (string) $payload['owner_code'],
            ownerType: OwnerType::from((string) $payload['owner_type']),
            cardCondition: CardCondition::from((string) $payload['card_condition']),
            blisterCondition: BlisterCondition::from((string) $payload['blister_condition']),
            listPrice: (int) $payload['list_price'],
            quantity: (int) $payload['quantity'],
        );
    }

    /**
     * Isi terburuk yang mungkin muncul di label asli, untuk uji cetak (FR-IB-25).
     *
     * Uji cetak ada justru untuk menemukan layout yang meluap, jadi isinya
     * sengaja dibuat memaksa: SKU tepat di batas 15 karakter, nama produk
     * tepat 24 karakter (bentuk tercetak setelah `shortProductName` memangkas,
     * termasuk ellipsis), kondisi terpanjang, owner code terpanjang, dan
     * harga tertinggi. Kalau label ini muat, semua label asli pasti muat.
     *
     * Ini satu-satunya jalan membangun label tanpa lot. Nilainya tidak pernah
     * disimpan ke `label_print_jobs` dan tidak boleh dipakai untuk label yang
     * akan ditempel ke barang sungguhan.
     */
    public static function sample(): self
    {
        return new self(
            // Batas SKU 15 karakter. Tanpa spasi supaya tidak lolos dari
            // `word-break: break-all` yang cuma jarang teruji.
            sku: 'HW-2024-000123X',
            // 24 karakter: panjang maksimum yang benar-benar sampai ke printer.
            productName: 'Porsche 911 GT3 RS 992 …',
            // Bentuk terpanjang yang masih sah: `CN` + 3 digit, sesuai regex
            // SKU `^(OW00|CN\d{2,3})-...`. Tanpa tanda hubung.
            ownerCode: 'CN999',
            ownerType: OwnerType::Consign,
            // Pasangan singkatan terpanjang: "NM/CL".
            cardCondition: CardCondition::NearMint,
            blisterCondition: BlisterCondition::Clear,
            // Batas `items.*.list_price` di inbound adalah 100.000.000, jadi
            // itu yang diuji. Angka yang lebih murah hanya membuat uji cetak
            // lolos padahal baris harganya meluber di printer.
            listPrice: 100_000_000,
            // Batas `qty_received` 1-999.
            quantity: 999,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'owner_code' => $this->ownerCode,
            'owner_type' => $this->ownerType->value,
            'card_condition' => $this->cardCondition->value,
            'blister_condition' => $this->blisterCondition->value,
            'list_price' => $this->listPrice,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * Label thermal punya ruang sangat terbatas, jadi produk dipangkas ke
     * batas yang masih terbaca. Dipangkas di sini, bukan di template, supaya
     * snapshot `payload` ikut menyimpan bentuk final -- kalau tidak, pemangkasan
     * bisa berubah antara satu cetak dan cetak berikutnya.
     */
    private static function shortProductName(StockLot $lot): string
    {
        $name = trim((string) ($lot->product?->name ?? ''));

        if ($name === '') {
            return 'Tanpa Nama';
        }

        return mb_strlen($name) > 24 ? mb_substr($name, 0, 23).'…' : $name;
    }

    /**
     * Kondisi digabung card + blister karena keduanya harus terbaca di label
     * yang sama, dan memakai singkatan supaya muat.
     */
    public function conditionLabel(): string
    {
        return strtoupper($this->cardCondition->shortLabel().'/'.$this->blisterCondition->shortLabel());
    }

    public function priceLabel(): string
    {
        return Format::rupiah($this->listPrice);
    }

    /**
     * Kode pemilik yang dicetak. Untuk titipan ini `CNxx`, bukan nama -- nama
     * penitip tidak pernah masuk ke label (lihat §1.4.2).
     */
    public function ownerLabel(): string
    {
        return strtoupper($this->ownerCode);
    }
}
