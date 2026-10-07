<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaperSize;
use App\Models\Setting;
use App\Services\Print\PrintSettings;

/**
 * Angka dan pilihan yang mengikat mesin kasir.
 *
 * Dua hal di sini punya dua pemakai: form Pengaturan, dan jalur transaksi. Batas
 * diskon dibaca kasir saat ia memotong harga, ambang selisih kas dibaca server
 * saat shift ditutup. Kalau angka yang sama ditulis ulang di dua tempat, salah
 * satunya akan menarik kendali dari yang lain tanpa ada yang mengetahuinya:
 * kasir melihat batas diskon 10 persen di layar, sementara server mengizinkan 20.
 *
 * Kertas struk POS TIDAK disimpan di sini lagi -- kertas sudah jadi milik
 * `PrintSettings`, satu sumber untuk semua cetakan. `receiptPaper()` tinggal
 * jalan pintas ke sana supaya pemanggil lama tidak perlu tahu dari mana asalnya.
 *
 * Yang tetap di luar sini: aturan yang tidak boleh berubah dari layar. Batas
 * diskon dan ambang selisih kas boleh diubah Owner, tapi setiap perubahannya
 * dicatat di `audit_logs` oleh `SettingController`, jadi angka yang sempat berlaku
 * tetap bisa ditelusuri.
 *
 * Pembacaan tidak pernah melempar, sama seperti `ReceiptPrinterSettings`: baris
 * `Setting` bisa berisi apa saja, termasuk nilai lama atau hasil ketik yang
 * terpotong. Nilai yang tidak terbaca menghasilkan bawaan, bukan halaman POS yang
 * gagal dimuat, karena halaman itu sedang dipegang kasir di depan pelanggan.
 */
final class PosSettings
{
    /**
     * Potongan harga maksimal yang boleh diberikan kasir tanpa PIN Owner, dalam persen.
     */
    public const STAFF_DISCOUNT_LIMIT_KEY = 'pos.staff_discount_limit_percent';

    /**
     * Selisih kas melebihi berapa rupiah yang perlu persetujuan Owner.
     */
    public const CASH_DIFFERENCE_THRESHOLD_KEY = 'pos.cash_difference_threshold';

    /**
     * Kasir tidak boleh memberi diskon apa pun sampai Owner mengatakannya sendiri.
     *
     * Nol berarti tidak ada diskon yang boleh diberikan, bukan berarti bebas.
     */
    public const DEFAULT_STAFF_DISCOUNT_LIMIT_PERCENT = 0;

    /**
     * Selisih sebesar ini dianggap pembulatan, bukan selisih yang perlu diperiksa.
     */
    public const DEFAULT_CASH_DIFFERENCE_THRESHOLD = 5_000;

    /**
     * Batas atas untuk kedua angka rupiah.
     *
     * Ada supaya nilai yang tidak masuk akal tetap tersimpan sebagai kesalahan ketik
     * yang bisa dikoreksi Owner, bukan ditolak oleh validasi yang membuat Owner
     * mengira form tidak bisa diisi.
     */
    public const MAX_AMOUNT = 1_000_000_000;

    /**
     * Batas atas persentase diskon: di atas 100 persen berarti pelanggan bawa uang
     * kembalian, dan itu bukan diskon yang layak diaktifkan lewat form.
     */
    public const MAX_DISCOUNT_PERCENT = 100;

    public function __construct(
        private readonly PrintSettings $printSettings,
    ) {}

    /**
     * Bawaan untuk yang belum pernah disimpan.
     *
     * Dikembalikan sekaligus supaya form Pengaturan tidak perlu memanggil accessor
     * satu per satu dan kemudian lupa bahwa ada dua nilai yang harus di-default.
     *
     * @return array{staff_discount_limit_percent: int, cash_difference_threshold: int}
     */
    public function defaults(): array
    {
        return [
            'staff_discount_limit_percent' => self::DEFAULT_STAFF_DISCOUNT_LIMIT_PERCENT,
            'cash_difference_threshold' => self::DEFAULT_CASH_DIFFERENCE_THRESHOLD,
        ];
    }

    /**
     * Nilai yang berlaku sekarang, dengan bawaannya untuk yang belum disimpan.
     *
     * Pembacaan memakai accessor yang sama dengan penyimpanan -- `percentOr` untuk
     * diskon, `amountOr` untuk rupiah. Menzalimi keduanya dengan satu pembaca
     * tunggal akan membuka jurang yang lebih lebar dari yang ditutupnya: nilai 250
     * persen tidak bisa disimpan lewat form, tapi kalau dibaca dengan `amountOr`
     * ia tetap berlaku, jadi kasir diberi batas yang tidak pernah bisa ditulis
     * siapa pun dan tidak bisa dikoreksi dari layar.
     *
     * @return array{staff_discount_limit_percent: int, cash_difference_threshold: int}
     */
    public function current(): array
    {
        $stored = Setting::many([
            self::STAFF_DISCOUNT_LIMIT_KEY => null,
            self::CASH_DIFFERENCE_THRESHOLD_KEY => null,
        ]);

        return [
            'staff_discount_limit_percent' => $this->percentOr(
                $stored[self::STAFF_DISCOUNT_LIMIT_KEY],
                self::DEFAULT_STAFF_DISCOUNT_LIMIT_PERCENT,
            ),
            'cash_difference_threshold' => $this->amountOr(
                $stored[self::CASH_DIFFERENCE_THRESHOLD_KEY],
                self::DEFAULT_CASH_DIFFERENCE_THRESHOLD,
            ),
        ];
    }

    public function staffDiscountLimitPercent(): int
    {
        return $this->current()['staff_discount_limit_percent'];
    }

    public function cashDifferenceThreshold(): int
    {
        return $this->current()['cash_difference_threshold'];
    }

    /**
     * Kertas untuk struk POS, diambil dari pengaturan cetak global.
     */
    public function receiptPaper(): PaperSize
    {
        return $this->printSettings->paper();
    }

    /**
     * Petakan nama field form ke key yang tersimpan.
     *
     * Satu tempat untuk pemetaan itu, supaya pemanggil tidak menulis nama key dua
     * kali: sekali saat membaca nilai lama untuk diff audit, sekali saat menyimpan.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, int|string>
     */
    public function toPersisted(array $values): array
    {
        $persisted = [];

        if (array_key_exists('staff_discount_limit_percent', $values)) {
            $persisted[self::STAFF_DISCOUNT_LIMIT_KEY] = $this->percentOr(
                $values['staff_discount_limit_percent'],
                self::DEFAULT_STAFF_DISCOUNT_LIMIT_PERCENT,
            );
        }

        if (array_key_exists('cash_difference_threshold', $values)) {
            $persisted[self::CASH_DIFFERENCE_THRESHOLD_KEY] = $this->amountOr(
                $values['cash_difference_threshold'],
                self::DEFAULT_CASH_DIFFERENCE_THRESHOLD,
            );
        }

        return $persisted;
    }

    /**
     * Keadaan lengkap pengaturan POS dalam bentuk yang enak dibaca diff audit.
     *
     * Kedua key selalu ikut, termasuk yang tidak berubah, supaya laporan audit
     * menampilkan keadaan pengaturan pada saat perubahan itu dibuat, bukan
     * potongan yang kebetulan berubah.
     *
     * @return array{staff_discount_limit_percent: int, cash_difference_threshold: int}
     */
    public function snapshot(): array
    {
        $current = $this->current();

        return [
            'staff_discount_limit_percent' => $current['staff_discount_limit_percent'],
            'cash_difference_threshold' => $current['cash_difference_threshold'],
        ];
    }

    /**
     * Rupiah yang tersimpan, atau bawaannya kalau isinya bukan rupiah yang masuk akal.
     *
     * Nilai negatif ditolak di sini, bukan hanya di form: ambang selisih kas negatif
     * membuat setiap penutupan shift selalu melewati ambang, jadi setiap tutup shift
     * akan berhenti minta PIN Owner tanpa sebab.
     */
    private function amountOr(mixed $stored, int $fallback): int
    {
        $amount = $this->intOrNull($stored);

        return $amount === null ? $fallback : $amount;
    }

    /**
     * Persentase yang tersimpan, dengan batas atas 100.
     *
     * Dibulatkan KE BAWAH, bukan ke nearest: yang dipegang di sini adalah batas,
     * dan pembulatan ke atas menaikkan batas yang tas orang tanpa pernah ada yang
     * menyetelkannya. `12.5` yang tersimpan menjadi `12`, bukan `13` -- kasir
     * menemukan diskon ditolak satu poin dan Owner menemukan angka yang tertulis
     * di Pengaturan lebih besar dari yang dipakai.
     *
     * Nilai yang tersimpan sebagai teks hasil ketik ikut dibuang, karena batas
     * diskon dibaca server setiap kali kasir memotong harga: batas yang tidak
     * terbaca berarti memotong harga tanpa batas sama sekali.
     */
    private function percentOr(mixed $stored, int $fallback): int
    {
        if (! is_numeric($stored)) {
            return $fallback;
        }

        $percent = (int) floor((float) $stored);

        return $percent < 0 || $percent > self::MAX_DISCOUNT_PERCENT ? $fallback : $percent;
    }

    /**
     * Bulat dari nilai settings, atau `null` kalau bukan angka atau di luar batas.
     *
     * `is_numeric` dulu, baru konversi, karena `(int)` diam-diam mengubah string
     * berisi teks menjadi 0, dan 0 untuk batas diskon berarti "diskon sama sekali
     * dilarang": aturan paling ketat yang ada, disetel tanpa ada yang mengira
     * itulah yang terjadi.
     */
    private function intOrNull(mixed $stored): ?int
    {
        if (! is_numeric($stored)) {
            return null;
        }

        $amount = (int) round((float) $stored);

        return $amount < 0 || $amount > self::MAX_AMOUNT ? null : $amount;
    }
}
