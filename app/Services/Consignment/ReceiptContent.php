<?php

declare(strict_types=1);

namespace App\Services\Consignment;

use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Setting;
use App\Models\StockLot;
use App\Support\Format;

/**
 * Isi bukti terima titipan, dipakai oleh dua medium.
 *
 * Bukti terima keluar dalam dua bentuk: pesan WhatsApp dan kertas yang dicetak
 * (struk 58/80 mm atau A4). Keduanya dibaca penitip sebagai bukti, jadi keduanya
 * harus mengulang angka yang sama persis.
 *
 * Class ini ada supaya angka itu hanya punya satu sumber. Kalau masing-masing
 * medium menghitung sendiri, perbedaannya tidak akan pernah terlihat sebagai
 * perbedaan kode, tapi muncul sebagai selisih antara kertas yang dipegang penitip
 * dan pesan di hp-nya -- dan yang harus mencari tahu mana yang salah adalah
 * pemilik toko, tanpa catatan siapa yang mencetak kapan.
 *
 * Yang TIDAK boleh masuk ke sini, sama seperti di e-receipt: nomor rekening,
 * nama bank, dan nomor WhatsApp orang lain. Memberitahu barang sudah diterima
 * tidak butuh data rekening, dan begitu data rekening keluar lewat kertas yang
 * hilang di tas penitip, data itu keluar dari kendali toko.
 *
 * Sumber angkanya `stock_lots`, bukan `consignment_items`: baris item dihapus
 * setelah commit karena isinya identik dengan lot (lihat
 * `InboundService::commitWithinTransaction`). Lot satu-satunya tempat yang masih
 * menyimpan isi dokumen, jadi kedua medium tidak mungkin berbeda selama keduanya
 * membaca dari sini.
 */
final readonly class ReceiptContent
{
    public function __construct(
        private Consignment $consignment,
    ) {}

    /**
     * Nilai setiap variabel yang dikenali template pesan.
     *
     * Dipakai `App\Services\Notification\ConsignmentReceipt` untuk menyusun body
     * WhatsApp, dan dipakai ulang apa adanya untuk angka di kertas.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $lots = $this->consignment->stockLots;

        return [
            'doc_no' => $this->consignment->doc_no,
            'consignor_name' => (string) ($this->consignment->consignor?->name ?? 'Penitip'),
            'consignor_code' => (string) ($this->consignment->consignor?->consignor_code ?? '-'),
            'date' => $this->consignment->consignment_date?->format('d M Y') ?? '-',
            'item_count' => (string) $lots->count(),
            'qty' => (string) $lots->sum('qty_received'),
            'detail' => $this->detailText(),
            'store' => $this->storeName(),
        ];
    }

    /**
     * Rincian satu baris per SKU, sudah diurutkan dan sudah berbentuk data.
     *
     * Ini bentuk yang dipakai kertas. `detailText()` menyusun pesan WhatsApp dari
     * hasil method ini, bukan sebaliknya, supaya urutan dan isinya tidak punya
     * dua definisi.
     *
     * Diurutkan pakai `sequence` lot, bukan urutan load database: rincian yang
     * sama harus keluar dengan urutan yang sama di kedua medium, dan urutan yang
     * bergantung pada indeks kolom berubah begitu indeksnya berubah.
     *
     * @return list<array{sku: string, pcs: int, price: string, scheme: ?string}>
     */
    public function rows(): array
    {
        return $this->consignment->stockLots
            ->sortBy('sequence')
            ->map(fn (StockLot $lot): array => [
                'sku' => $lot->sku,
                'pcs' => (int) $lot->qty_received,
                'price' => Format::rupiah($lot->list_price),
                'scheme' => $this->scheme($lot),
            ])
            ->values()
            ->all();
    }

    /**
     * Rincian sebagai baris teks untuk pesan.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map($this->line(...), $this->rows());
    }

    /**
     * Catatan selisih antara klaim penitip dan barang yang benar-benar diterima.
     *
     * Catatan milik dokumen didahulukan. Kalau angkanya dibandingkan ulang di
     * sini dan berbeda dari yang sudah ditulis Staff di dokumen, yang benar tetap
     * catatan Staff: dialah yang memegang barangnya, dan tabel penanganan error
     * inbound meminta selisih dicatat apa adanya.
     */
    public function varianceNote(): ?string
    {
        $note = trim((string) $this->consignment->variance_note);

        if ($note !== '') {
            return $note;
        }

        $claimed = $this->consignment->qty_claimed;
        $received = $this->consignment->qty_received;

        if ($claimed !== null && $received !== null && $claimed !== $received) {
            return sprintf('Penitip mengklaim %d pcs, diterima %d pcs.', $claimed, $received);
        }

        return null;
    }

    /**
     * Nama toko, sama untuk kertas dan pesan.
     */
    public function storeName(): string
    {
        $name = Setting::get('store.name');

        return is_string($name) && trim($name) !== '' ? $name : 'Hot Wheels Store';
    }

    /**
     * Rincian sebagai satu blok teks, dengan catatan varian di akhir.
     */
    public function detailText(): string
    {
        $lines = $this->lines();
        $variance = $this->varianceNote();

        if ($variance !== null) {
            $lines[] = 'Catatan: '.$variance;
        }

        return $lines === [] ? '-' : implode("\n", $lines);
    }

    /**
     * Satu SKU, satu baris.
     *
     * "item" di bukti terima berarti lot, bukan baris dokumen: menyamakan dengan
     * baris dokumen membuat angkanya berbeda dari yang dilihat penitip di nota.
     */
    private function line(array $row): string
    {
        return implode(' — ', array_filter([
            '· '.$row['sku'],
            $row['pcs'].' pcs',
            $row['price'],
            $row['scheme'],
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * Skema komisi dalam satu kalimat.
     *
     * Bentuknya mengikuti tabel detail dokumen supaya bukti yang dibaca penitip
     * memakai bahasa yang sama dengan yang Staff lihat di layar.
     */
    private function scheme(StockLot $lot): ?string
    {
        return match ($lot->scheme_type) {
            SchemeType::Percentage => 'skema '.$this->trimNumber((string) $lot->scheme_rate).'%',
            SchemeType::Nett, SchemeType::Flat => 'skema '.Format::rupiah($lot->scheme_amount).'/unit',
            default => null,
        };
    }

    /**
     * `scheme_rate` adalah `decimal`, jadi `20.00` harus jadi `20` dan bukan
     * `20,00` di belakang layar.
     */
    private function trimNumber(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
