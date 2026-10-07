<?php

declare(strict_types=1);

namespace App\Services\Pos;

/**
 * Angka satu shift yang sedang dibuka kasir, dihitung sekali saat halaman dimuat.
 *
 * Ada karena hitungannya berasal dari tiga tabel -- `shifts`, `sales`,
 * `sale_payments` -- sementara yang ditampilkan ke kasir adalah satu layar. Kalau
 * Blade yang menghitungnya, setiap angka di layar jadi query sendiri dan angka
 * yang tampil bisa berasal dari hitungan yang berbeda satu sama lain: kasir bisa
 * melihat "tunai Rp 1.200.000" untuk kas yang seharusnya Rp 1.150.000 karena
 * keduanya dibaca pada saat yang berbeda, dan tidak ada yang bisa bilang mana yang
 * benar.
 *
 * `closingCash` dan `cashDiff` bisa `null` karena keduanya belum ada sebelum shift
 * ditutup. Null di sini berarti "belum diketahui", bukan nol: kas yang memang nol
 * adalah jawaban, dan membedakannya dari "belum dihitung" mencegah Owner dimintai
 * persetujuan untuk menutup shift dengan uang nol.
 */
final readonly class ShiftSummary
{
    /**
     * @param  int  $openingCash  uang yang ada di laci saat shift dibuka
     * @param  int  $cashReceived  total pembayaran tunai yang sudah dibayar
     * @param  int  $expectedCash  openingCash + cashReceived, yaitu yang seharusnya ada
     * @param  int|null  $closingCash  uang yang ada di laci saat ditutup, `null` sebelum itu
     * @param  int|null  $cashDiff  closingCash - expectedCash, `null` sebelum ditutup
     * @param  array<string, array{label: string, count: int, total: int}>  $methods  rekap per metode pembayaran
     * @param  int  $saleCount  jumlah transaksi yang dihitung, bukan jumlah baris item
     * @param  int  $saleTotal  nominal seluruh transaksi yang dihitung
     */
    public function __construct(
        public int $openingCash,
        public int $cashReceived,
        public int $expectedCash,
        public ?int $closingCash,
        public ?int $cashDiff,
        public array $methods,
        public int $saleCount,
        public int $saleTotal,
    ) {}

    /**
     * Uang yang dihitung berbeda dari yang seharusnya ada, atau `null` sebelum ditutup.
     *
     * `abs()`, bukan `> 0`: selisih negatif sama-sama uang yang hilang, dan tutup
     * shift dengan selisih negatif butuh persetujuan Owner seperti yang positif.
     */
    public function difference(): ?int
    {
        return $this->cashDiff;
    }

    public function hasDifference(): bool
    {
        return $this->cashDiff !== null && $this->cashDiff !== 0;
    }
}
