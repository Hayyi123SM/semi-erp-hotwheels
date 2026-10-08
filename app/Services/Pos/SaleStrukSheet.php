<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaperSize;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;

/**
 * View model untuk struk POS.
 *
 * Blade tidak melakukan query, tidak menghitung, dan tidak memutuskan format.
 * Semuanya dikumpulkan di sini supaya pratinjau di layar kasir, halaman cetak,
 * dan byte ESC/POS membaca angka yang sama -- sama seperti `ReceiptSheet` untuk
 * bukti terima titipan.
 *
 * Nama toko dibaca langsung dari `Setting`, satu tempat yang sama dengan
 * `ReceiptContent`. Dua medium (WEB dan struk) tidak pernah boleh menampilkan
 * nama toko berbeda, jadi fallback-nya juga harus sama.
 *
 * Pengguna yang mencetak diteruskan dari controller, bukan diambil dari `auth()`
 * di dalam kelas -- alasan yang sama dengan `ReceiptSheet`.
 *
 * `changeDue` tidak bisa dibaca dari `Sale`: tabelnya tidak menyimpan uang yang
 * dipegang kasir, hanya `payments`. Kembalian hanya diketahui saat checkout
 * selesai di layar kasir; cetakan ulang dari riwayat tidak lagi punya angkanya,
 * jadi nilainya `null` dan baris "Kembalian" tidak ditampilkan.
 */
final readonly class SaleStrukSheet
{
    private CarbonInterface $printedAt;

    /**
     * @param  int|null  $changeDue  dikembalikan dari `tender` saat layar kasir
     *                               selesai checkout, atau `null` untuk cetakan
     *                               ulang yang sudah tidak tahu uang yang dipegang.
     */
    public function __construct(
        public Sale $sale,
        public PaperSize $paper,
        public User $printedBy,
        public ?int $changeDue = null,
        ?CarbonInterface $printedAt = null,
    ) {
        $this->printedAt = $printedAt ?? now();
    }

    public function storeName(): string
    {
        $name = Setting::get('store.name');

        return is_string($name) && trim($name) !== '' ? $name : '167 Diecast Shop';
    }

    public function docNo(): string
    {
        return $this->sale->receipt_no;
    }

    /**
     * Waktu transaksi, bukan waktu struk ini dicetak.
     *
     * Waktu cetak ada di footer. Kalau kolom ini membaca `now()`, cetakan ulang
     * bulan depan akan mengubah notas tahun ini menjadi dokumen dengan tanggal
     * baru -- persis alasan `ReceiptSheet::receivedOn()` memakai tanggal dokumen.
     */
    public function soldOn(): string
    {
        return $this->sale->sold_at !== null
            ? Format::datetime($this->sale->sold_at)
            : 'Belum tercatat di server';
    }

    public function cashierName(): string
    {
        return (string) ($this->sale->cashier?->name ?? 'Sistem');
    }

    public function shiftNo(): string
    {
        return $this->sale->shift_id !== null ? '#'.$this->sale->shift_id : '-';
    }

    public function subtotal(): int
    {
        return $this->sale->subtotal;
    }

    public function discountTotal(): int
    {
        return $this->sale->discount_total;
    }

    public function total(): int
    {
        return $this->sale->total;
    }

    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     qty: int,
     *     price: int,
     *     line_total: int,
     *     discount: int,
     *     list_price: int,
     * }>
     */
    public function lines(): array
    {
        return $this->sale->items
            ->map(fn (SaleItem $item): array => [
                'sku' => $item->sku,
                'name' => (string) ($item->lot?->product?->name ?? $item->sku),
                'qty' => $item->qty,
                // `sell_price` sudah bersih dari diskon (`P = L - diskon`), sama
                // seperti yang dihitung halaman nota: total baris cukup himpun
                // dari `sell_price * qty`, dan diskonnya dicatat terpisah supaya
                // tidak dikurangi dua kali.
                'price' => $item->sell_price,
                'line_total' => $item->sell_price * $item->qty,
                'discount' => $item->discount,
                'list_price' => $item->list_price,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{method: string, amount: int, reference: ?string}>
     */
    public function payments(): array
    {
        return $this->sale->payments
            ->map(fn (SalePayment $payment): array => [
                'method' => $payment->method->label(),
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ])
            ->values()
            ->all();
    }

    public function methodSummary(): string
    {
        return $this->sale->methodSummary();
    }

    /**
     * Waktu pencetakan untuk footer, dengan zona waktu yang sama seperti
     * baris-baris lain lewat `Format::datetime()`.
     */
    public function printedAt(): string
    {
        return Format::datetime($this->printedAt);
    }

    public function printedByName(): string
    {
        return $this->printedBy->name !== '' ? $this->printedBy->name : '-';
    }

    /**
     * Pengaturan `@page` dan lebar area cetak untuk kertas terpilih.
     *
     * @return array{size: string, width: string, thermal: bool}
     */
    public function page(): array
    {
        return [
            'size' => $this->paper->pageSizeCss(),
            'width' => $this->paper->contentWidthCss(),
            'thermal' => $this->paper->isThermal(),
        ];
    }

    public function paper(): PaperSize
    {
        return $this->paper;
    }

    public function label(): string
    {
        return $this->paper->label();
    }
}
