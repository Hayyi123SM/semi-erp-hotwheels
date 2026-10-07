<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\ShiftStatus;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DeviceId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Siklus hidup shift kasir: membuka, menghitung, menutup.
 *
 * Semua aturan yang menentukan "berapa uang yang seharusnya ada di laci" hidup di
 * sini, bukan di controller dan bukan di Blade, supaya halaman Shift Kasir,
 * endpoint yang dipanggil ulang, dan pengujian semuanya membaca angka yang sama.
 * Kalau rumus "opening + tunai" ditulis di tiga tempat, tiga tempat itu akan
 * berbeda begitu satu saja diperbaiki.
 *
 * Bentuk uang yang dipakai semua method di sini adalah rupiah bulat. Tidak ada
 * pecahan sen, dan kolomnya sudah bulat di database juga: pembulatan hanya
 *belongs di tampilan.
 */
final class ShiftService
{
    public function __construct(
        private readonly PosSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Shift yang sedang dibuka oleh kasir ini di perangkat ini, atau `null`.
     *
     * Dipakai halaman Shift Kasir untuk menentukan apakah ia harus menampilkan
     * form buka shift atau rekap shift berjalan.
     */
    public function currentFor(?User $actor, ?string $deviceId = null): ?Shift
    {
        $deviceId ??= DeviceId::current();

        $byDevice = Shift::openForDevice($deviceId);
        $byUser = Shift::openForUser($actor?->getKey());

        // Kalau dua baris berbeda ditemukan, yang dipakai kasir adalah miliknya
        // sendiri: itu shift yang sedang ia kerjakan, sedangkan yang lain milik
        // perangkat ini adalah masalah yang harus dilaporkan, bukan shift yang
        // bisa ia tutup dengan sendirinya.
        if ($byUser !== null) {
            return $byUser;
        }

        return $byDevice;
    }

    /**
     * Buka shift baru.
     *
     * Syaratnya dua, dan keduanya diperiksa di dalam transaksi yang sama dengan
     * penambahan barisnya, setelah baris kasirnya dikunci: satu perangkat tidak
     * boleh punya dua shift terbuka, dan satu kasir tidak boleh punya dua shift
     * terbuka di perangkat mana pun. Pemeriksaan yang dilakukan sebelum transaksi
     * akan terlihat benar sampai dua kasir menekan tombol yang sama pada detik
     * yang sama -- dan dua shift terbuka untuk satu kasir berarti uang pembuka
     * terhitung dua kali.
     *
     * @param  string|null  $deviceId  perangkat tempat shift dibuka; `null` berarti perangkat yang sedang dipakai sekarang
     *
     * @throws ShiftAlreadyOpenException
     */
    public function open(
        ?User $actor,
        int $openingCash,
        ?string $deviceId = null,
        ?string $notes = null,
    ): Shift {
        $deviceId ??= DeviceId::current();

        return DB::transaction(function () use ($actor, $openingCash, $deviceId, $notes): Shift {
            // Dikunci SEBELUM diperiksa, bukan sesudahnya. Dua permintaan yang
            // datang pada milidetik yang sama akan sama-sama membaca "belum ada
            // shift" kalau pemeriksaannya tidak dikunci, lalu sama-sama menulis
            // -- dan dua shift terbuka milik satu kasir berarti uang pembuka
            // terhitung dua kali.
            //
            // Yang dikunci adalah baris kasirnya, karena itu satu-satunya baris
            // yang pasti ada. Aturan per-perangkat tidak bisa dikunci dengan
            // cara yang sama: MySQL tidak punya unique index sebagian, jadi
            // "satu shift terbuka per perangkat" tidak punya baris yang bisa
            // dikunci dan tidak punya index yang bisa menjaganya. Itu masih
            // diperiksa di sini, dan cukup untuk pemakaian nyata -- satu kasir
            // pada satu perangkat -- tetapi tidak untuk dua perangkat yang
            // tidak sengaja memakai id yang sama.
            if ($actor !== null) {
                User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            }

            $this->refuseDoubleOpen($actor, $deviceId);

            $shift = Shift::create([
                'device_id' => $deviceId,
                'user_id' => $actor?->getKey(),
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'status' => ShiftStatus::Open,
                'notes' => $notes,
            ]);

            // `after` yang sama persis dengan yang akan dipakai `before`-nya saat
            // penutupan. Laporan audit menampilkan `OPEN_SHIFT` dan
            // `CLOSE_SHIFT` sebagai satu rangkaian shift; kalau keduanya memakai
            // bentuk yang berbeda, satu dari keduanya harus dibaca dengan cara
            // sendiri, dan itulah saat angka-angkanya mulai disalahartikan.
            $this->audit->log('OPEN_SHIFT', Shift::class, $shift->getKey(), [], $this->auditableState($shift));

            return $shift;
        });
    }

    /**
     * Tutup shift dan hitung selisih kasnya.
     *
     * Satu-satunya jalan untuk menutup shift. Semua pemanggil harus lewat sini,
     * karena di sinilah angka laci dibandingkan dengan rekap dan di sinilah
     * selisih yang di luar ambang berhenti sebelum sempat ditulis.
     *
     * @param  User|null  $approver  Owner yang mengesahkan selisih di luar ambang; `null` kalau selisihnya masih dalam ambang
     *
     * @throws ShiftAlreadyClosedException
     */
    public function close(Shift $shift, int $closingCash, ?User $actor = null, ?User $approver = null, ?string $notes = null): Shift
    {
        return DB::transaction(function () use ($shift, $closingCash, $actor, $approver, $notes): Shift {
            // Dikunci supaya dua permintaan tutup yang datang bersamaan tidak
            // sama-sama membaca `OPEN` lalu sama-sama menulis `CLOSED` dengan
            // angka yang berbeda. Tanpa kunci, audit mencatat dua penutupan atas
            // satu shift.
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());

            if ($locked->status === ShiftStatus::Closed) {
                throw new ShiftAlreadyClosedException;
            }

            // Dibaca SEBELUM ditulis. Setelah `save()`, `closing_cash` sudah berisi
            // angka yang baru diketik, jadi "before" yang diambil belakangan akan
            // persis sama dengan "after" -- dan satu-satunya jejak yang tersisa
            // hanyalah `status`, yang tidak bisa menjelaskan selisihnya.
            $before = $this->auditableState($locked);

            $locked->forceFill([
                'closing_cash' => $closingCash,
                'cash_diff' => $closingCash - $this->expectedCash($locked),
                'closed_at' => now(),
                'closed_by' => $actor?->getKey(),
                'cash_diff_approved_by' => $approver?->getKey(),
                'status' => ShiftStatus::Closed,
                'notes' => $notes ?? $locked->notes,
            ])->save();

            $this->audit->log('CLOSE_SHIFT', Shift::class, $locked->getKey(), $before, $this->auditableState($locked));

            return $locked->refresh();
        });
    }

    /**
     * Keadaan shift dalam bentuk rata untuk diff audit.
     *
     * `expected_cash` dan `cash_received` ikut di sini walaupun bukan kolom di
     * tabel `shifts`: kedua angka itulah yang membuat `cash_diff` bisa dibaca.
     * `cash_diff` tanpa penjelasan dazu hanya angka yang bisa dilihat, dan bukan
     * bisa diperiksa -- audit seperti itu hanya berguna kalau pembacanya sudah
     * punya catatan hitungannya sendiri.
     *
     * Dipanggil dua kali dalam satu penutupan -- sekali sebelum ditulis, sekali
     * sesudahnya -- jadi keduanya punya field dan urutan yang sama, persis yang
     * dibutuhkan `AuditLogger` untuk menampilkan keduanya berdampingan.
     *
     * @return array<string, mixed>
     */
    private function auditableState(Shift $shift): array
    {
        return [
            'device_id' => $shift->device_id,
            'user_id' => $shift->user_id,
            'opening_cash' => $shift->opening_cash,
            'opened_at' => $shift->opened_at?->toIso8601String(),
            'status' => $shift->status->value,
            'closing_cash' => $shift->closing_cash,
            'cash_diff' => $shift->cash_diff,
            'expected_cash' => $this->expectedCash($shift),
            'cash_received' => $this->cashReceived($shift),
            'sale_count' => (int) $this->paidSalesQuery($shift)->count(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'closed_by' => $shift->closed_by,
            'cash_diff_approved_by' => $shift->cash_diff_approved_by,
            'notes' => $shift->notes,
        ];
    }

    /**
     * Uang yang seharusnya ada di laci sekarang.
     *
     * Hanya penjualan yang sudah `PAID` yang dihitung. `VOIDED` berarti uangnya
     * sudah kembali ke pelanggan dan tidak pernah masuk ke laci; `SYNC_CONFLICT`
     * dan `TERMS_STALE` belum bisa dipercaya untuk dihitung sebagai kas, karena
     * keduanya sedang menunggu keputusan manusia.
     */
    public function expectedCash(Shift $shift): int
    {
        return $shift->opening_cash + $this->cashReceived($shift);
    }

    /**
     * Total pembayaran tunai di shift ini, dari penjualan yang sudah dibayar.
     */
    public function cashReceived(Shift $shift): int
    {
        return (int) $this->paidPaymentQuery($shift)
            ->where('sale_payments.method', PaymentMethod::Cash->value)
            ->sum('sale_payments.amount');
    }

    /**
     * Rekap halaman Shift Kasir untuk satu shift.
     *
     * Dipanggil saat halaman dimuat, jadi angkanya adalah keadaan shift pada saat
     * dimuat. Angka di layar tidak pernah dipakai sebagai pengganti perhitungan
     * saat tutup shift: `close()` menghitung ulang dari database, sehingga kasir
     * yang menutup shift di tab lain tidak bisa menutup shift dengan angka basi
     * yang sedang ia lihat.
     */
    public function summary(Shift $shift): ShiftSummary
    {
        $cashReceived = $this->cashReceived($shift);

        return new ShiftSummary(
            openingCash: $shift->opening_cash,
            cashReceived: $cashReceived,
            expectedCash: $shift->opening_cash + $cashReceived,
            closingCash: $shift->closing_cash,
            cashDiff: $shift->cash_diff,
            methods: $this->paymentBreakdown($shift),
            saleCount: (int) $this->paidSalesQuery($shift)->count(),
            saleTotal: (int) $this->paidSalesQuery($shift)->sum('sales.total'),
        );
    }

    /**
     * Ambang selisih kas yang perlu persetujuan Owner, dari pengaturan.
     */
    public function cashDifferenceThreshold(): int
    {
        return $this->settings->cashDifferenceThreshold();
    }

    /**
     * Apakah selisih sebesar ini perlu persetujuan Owner.
     *
     * `abs()` supaya uang yang kurang sebanyak yang lebih punya aturan yang sama:
     * keduanya uang yang tidak ada di laci, dan keduanya harus ditandatangani
     * orang yang bertanggung jawab atas laci itu.
     */
    public function differenceNeedsApproval(int $difference): bool
    {
        return abs($difference) > $this->cashDifferenceThreshold();
    }

    /**
     * Rekap per metode pembayaran, lengkap dengan metode yang nol.
     *
     * Metode yang tidak muncul sengaja ikut dikembalikan sebagai nol. Kasir yang
     * melihat "QRIS Rp 0" tahu tidak ada pembayaran QRIS; kasir yang tidak melihat
     * QRIS sama sekali tidak tahu apa yang dilewati, dan saat tutup shift dengan
     * uang kurang dia akan mencari penjelasan di tempat yang salah.
     *
     * Yang diiterasi `PaymentMethod::pos()`, bukan `cases()`: rekap ini adalah
     * rekap laci, jadi ia hanya boleh menyebut metode yang bisa masuk ke laci.
     *
     * @return array<string, array{label: string, count: int, total: int}>
     */
    private function paymentBreakdown(Shift $shift): array
    {
        $totals = $this->paidPaymentQuery($shift)
            ->selectRaw('sale_payments.method, COUNT(*) as payment_count, SUM(sale_payments.amount) as total')
            ->groupBy('sale_payments.method')
            ->get()
            ->keyBy('method');

        $breakdown = [];

        foreach (PaymentMethod::pos() as $method) {
            $row = $totals->get($method->value);

            $breakdown[$method->value] = [
                'label' => $method->label(),
                'count' => (int) ($row->payment_count ?? 0),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return $breakdown;
    }

    /**
     * Pembayaran dari penjualan di shift ini yang statusnya sudah `PAID`.
     *
     * `select` sengaja tidak dipakai: pemanggilnya semuanya menambahkan agregat,
     * dan `count()` maupun `sum()` di Laravel membuang daftar kolom sebelum
     * membangun SQL-nya, jadi tidak ada bagian lain dari kueri ini yang perlu
     * memilih kolom secara manual.
     */
    private function paidPaymentQuery(Shift $shift): Builder
    {
        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.shift_id', $shift->getKey())
            ->where('sales.status', SaleStatus::Paid->value);
    }

    /**
     * Penjualan di shift ini yang statusnya sudah `PAID`.
     */
    private function paidSalesQuery(Shift $shift): Builder
    {
        return Sale::query()
            ->where('sales.shift_id', $shift->getKey())
            ->where('sales.status', SaleStatus::Paid->value);
    }

    /**
     * Tolak shift kedua yang akan membuka shift pertama yang masih jalan.
     *
     * Dua pesan berbeda karena dua masalahnya berbeda. Kasir yang mendapat
     * "shift untukmu masih terbuka" tahu harus menutupnya dulu. Kasir yang
     * mendapat "perangkat ini masih dipakai" tahu harus mencari mesinnya, dan
     * pesan yang salah akan membuatnya menutup shift orang lain.
     */
    private function refuseDoubleOpen(?User $actor, ?string $deviceId): void
    {
        $byUser = Shift::openForUser($actor?->getKey());

        if ($byUser !== null) {
            throw new ShiftAlreadyOpenException(
                sprintf(
                    'Shift milikmu masih terbuka sejak %s. Tutup shift itu sebelum membuka shift baru.',
                    $byUser->opened_at?->translatedFormat('d M Y H:i') ?? 'sebelumnya',
                ),
                $byUser,
            );
        }

        $byDevice = Shift::openForDevice($deviceId);

        if ($byDevice !== null) {
            throw new ShiftAlreadyOpenException(
                sprintf(
                    'Perangkat ini masih menjalankan shift milik %s. Tutup shift itu dulu sebelum membuka shift baru.',
                    $byDevice->user?->name ?? 'kasir lain',
                ),
                $byDevice,
            );
        }
    }
}
