<?php

declare(strict_types=1);

namespace App\Services\Consignment;

use App\Enums\PaperSize;
use App\Models\Consignment;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;

/**
 * View model untuk halaman cetak bukti terima titipan.
 *
 * Blade tidak melakukan query, tidak menghitung, dan tidak memutuskan format.
 * Semuanya dikumpulkan di sini supaya halaman cetak bisa dibandingkan dengan
 * e-receipt hanya dengan membaca dua kelas ini.
 *
 * Angka dokumennya bukan milik kelas ini: diambil apa adanya dari
 * `ReceiptContent`, yang sama dengan yang dipakai pesan WhatsApp.
 *
 * Pengguna yang mencetak diteruskan dari controller, bukan diambil dari `auth()`
 * di dalam kelas. Ini bukan sekadar biar test-nya gampang: pemanggil yang sudah
 * tahu siapa yang sedang mencetak tidak perlu service mengulangi tebakan itu,
 * dan kalau nanti halaman ini dipakai dari konteks lain (misalnya cetak atas
 * nama Staff lain), sumber kebenarannya tetap satu -- argumennya.
 */
final readonly class ReceiptSheet
{
    private ReceiptContent $content;

    private CarbonInterface $printedAt;

    /**
     * @param  bool  $autoPrint  halaman harus langsung membuka dialog print setelah
     *                           dimuat. Dipakai alur commit, bukan cetakan ulang
     *                           dari riwayat: membuka dialog print tanpa diminta
     *                           saat orang sedang membaca daftar membuat daftar itu
     *                           tercetak sendiri.
     */
    public function __construct(
        public Consignment $consignment,
        public PaperSize $paper,
        public User $printedBy,
        bool $autoPrint = false,
        ?CarbonInterface $printedAt = null,
    ) {
        $this->content = new ReceiptContent($consignment);
        $this->printedAt = $printedAt ?? now();
    }

    public function storeName(): string
    {
        return $this->content->storeName();
    }

    public function docNo(): string
    {
        return $this->consignment->doc_no;
    }

    public function consignorName(): string
    {
        return (string) ($this->consignment->consignor?->name ?? 'Penitip');
    }

    public function consignorCode(): string
    {
        return (string) ($this->consignment->consignor?->consignor_code ?? '-');
    }

    /**
     * Tanggal barang diterima, bukan tanggal struk ini dicetak.
     *
     * Tanggal cetak ada di footer. Kalau tanggal terima ikut bergeser mengikuti
     * tanggal hari ini, setiap cetakan ulang mengubah bukti yang sama menjadi
     * dokumen dengan tanggal berbeda.
     */
    public function receivedOn(): string
    {
        return Format::date($this->consignment->consignment_date);
    }

    /**
     * @return array{items: int, pcs: int}
     */
    public function totals(): array
    {
        $lots = $this->consignment->stockLots;

        return [
            'items' => $lots->count(),
            'pcs' => (int) $lots->sum('qty_received'),
        ];
    }

    /**
     * @return list<array{sku: string, pcs: int, price: string, scheme: ?string}>
     */
    public function lines(): array
    {
        return $this->content->rows();
    }

    public function varianceNote(): ?string
    {
        return $this->content->varianceNote();
    }

    /**
     * Waktu pencetakan untuk footer.
     *
     * "WIB" berasal dari `Format::datetime()`, jadi zona waktunya ikut yang
     * dipakai aplikasi. Zona waktu test sengaja disamakan dengan produksi di
     * `phpunit.xml`; kalau tidak, test berjalan di UTC dan footer menstempel waktu
     * UTC dengan label WIB.
     */
    public function printedAt(): string
    {
        return Format::datetime($this->printedAt);
    }

    /**
     * Nama petugas yang mencetak.
     *
     * Yang masuk ke footer adalah orang yang sedang mencetak, bukan pembuat
     * dokumen. Bedanya nyata pada cetakan ulang: dokumen yang sudah sebulan lalu
     * dicetak ulang karena satu barang kurang menghasilkan footer yang menyebut
     * orang yang benar-benar memegang struk itu, bukan yang menerima barangnya
     * bulan lalu.
     */
    public function printedByName(): string
    {
        return $this->printedBy->name !== '' ? $this->printedBy->name : '-';
    }

    /**
     * Nama yang ikut dicetak di bawah garis tanda tangan petugas.
     *
     * Garisnya tetap kosong untuk ditandatangani tangan. Namanya hanya pengenal
     * siapa yang harus menandatangani, jadi penitip tidak perlu menebak apakah
     * struk ini miliknya atau bukan.
     */
    public function storeSignerName(): string
    {
        return $this->printedBy->name !== '' ? $this->printedBy->name : '-';
    }

    /**
     * Nama penitip yang harus menandatangani.
     *
     * Diambil dari dokumen, bukan dari `auth()`: struk bisa dicetak ulang oleh
     * petugas mana pun untuk dokumen lama, dan penitipnya adalah orang yang
     * namanya tercatat di dokumen itu.
     */
    public function consignorSignerName(): string
    {
        return $this->consignment->consignor?->name ?? 'Penitip';
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
