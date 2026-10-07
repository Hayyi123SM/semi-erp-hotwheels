<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Kartu kertas lama yang sudah pernah dipilih Owner, dalam milimeter.
 *
 * Enum ini bukan lagi sumber ukuran cetak. Ukuran kertas sekarang disimpan sebagai
 * lima angka bebas di `LabelSheetSettings`, dan gridnya dihitung dari angka itu
 * plus ukuran label preset. Enum ini tersisa hanya untuk menerjemahkan instalasi
 * lama: yang punya `label.sticker_sheet` dan belum pernah menyimpan angka baru
 * harus tetap mendapat grid yang sama seperti waktu blueprint-nya dipilih.
 *
 * Yang diterjemahkan hanya ukuran media dan celah. Jumlah kolom lama sengaja
 * tidak ikut, karena label sekarang tidak lagi dibagi rata ke kolom -- ukuran
 * label datang dari preset, jadi Owner yang memakai kartu 100 x 150 mm
 * juga boleh mencetak label 3 x 2 cm di atasnya, dan menyertakan "6 kolom"
 * hanya akan menahan pilihan yang sebenarnya valid.
 *
 * Hapus enum ini (bersama `label.sticker_sheet`) setelah tidak ada instalasi yang
 * masih menyimpan key itu. Tidak ada migration untuk menghapusnya: key lama
 * dibiarkan di database sebagai catatan, dan pembacaan key itu yang dihentikan.
 */
enum StickerSheet: string
{
    /**
     * BLUEPRINT BP-TD110BT (PT Berkah Prima Perkasa).
     *
     * Printer label thermal A6 dengan TSPL, 203 dpi (8 dot/mm), lebar cetak
     * maksimum 108 mm, lebar kertas maksimum 118 mm, tanpa auto-cutter.
     *
     * Angka media dan celah di bawah berasal dari kertas stiker 100 x 150 mm
     * dengan jarak 2 mm, yang pada zamannya dipecah menjadi 6 kolom selebar
     * 15 mm:
     *
     *     6 x 15 mm + 5 x 2 mm = 100 mm
     *
     * Persis sama dengan lebar media, tanpa sisa sama sekali. Angka itu tetap
     * dipakai apa adanya supaya Owner tidak perlu menebak ulang, dan grid yang
     * keluar untuk label 1,5 x 1,5 cm tetap 6 x 8 = 48 label -- sama seperti
     * sebelum kartu ini digantikan angka.
     */
    case BpTd110BtA6 = 'bp-td110bt-100x150';

    public function mediaWidthMm(): float
    {
        return match ($this) {
            self::BpTd110BtA6 => 100.0,
        };
    }

    public function mediaHeightMm(): float
    {
        return match ($this) {
            self::BpTd110BtA6 => 150.0,
        };
    }

    /**
     * Jarak antar stiker, untuk arah horizontal dan vertikal sekalian.
     *
     * Satu angka untuk kedua arah karena produk fisik menyediakannya dalam satu
     * ukuran. Kalau suatu saat stiker punya jarak berbeda secara horizontal dan
     * vertikal, angka kedua harus jadi properti terpisah -- bukan ditimpa dengan
     * yang lebih besar, karena itu diam-diam melonggarkan sisi yang seharusnya
     * rapat dan label bisa saling tumpang tindih.
     */
    public function gapMm(): float
    {
        return match ($this) {
            self::BpTd110BtA6 => 2.0,
        };
    }

    /**
     * Kartu yang dipakai kalau Owner belum pernah memilih apa pun.
     *
     * Ditulis eksplisit, bukan `self::cases()[0]`, supaya menambah kartu di
     * tengah enum tidak diam-diam mengubah kertas mana yang jadi bawaan -- dan
     * supaya nilai bawaan itu kelihatan ada di kodenya.
     */
    public static function default(): self
    {
        return self::BpTd110BtA6;
    }
}
