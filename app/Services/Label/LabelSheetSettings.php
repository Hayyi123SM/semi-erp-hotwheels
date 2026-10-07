<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Ukuran kertas stiker dan celah yang disimpan Owner, dalam milimeter.
 *
 * Yang diinput cuma lima angka: ukuran kertas, celah, dan batas cetak printer.
 * Jumlah kolom, jumlah baris, dan jumlah label per lembar semuanya turunan dan
 * dihitung oleh `SheetGridCalculator`.
 *
 * Bawaannya bukan angka sembarang. Lebar 100 mm, tinggi 150 mm, dan celah 2 mm
 * adalah kertas stiker yang sudah dipakai sistem dan sudah diverifikasi terhadap
 * printer BP-TD110BT, jadi Owner yang belum pernah menyentuh pengaturan ini
 * tetap mendapat grid yang sama seperti sebelumnya -- 6 x 8 = 48 label dengan
 * sisa bawah 16 mm.
 *
 * `$maxPrintWidthMm` berbeda sifatnya dari empat angka lain: itu kemampuan
 * printer, bukan sifat kertas. Kertas bisa lebih lebar dari area yang bisa
 * dicetak, tapi grid yang melebihi batas printer tidak akan keluar utuh -- jadi
 * angka ini ikut dibawa di sini supaya satu panggilan sudah menjawab "apakah
 * sheet ini bisa dicetak", bukan cuma "berapa labelnya".
 */
final readonly class LabelSheetSettings
{
    public const float DEFAULT_MEDIA_WIDTH_MM = 100.0;

    public const float DEFAULT_MEDIA_HEIGHT_MM = 150.0;

    public const bool DEFAULT_HAS_GAP = true;

    public const float DEFAULT_GAP_MM = 2.0;

    /**
     * Lebar cetak maksimal BP-TD110BT, dalam milimeter.
     *
     * Kertasnya bisa 118 mm, tapi yang bisa dicetak hanya 108 mm. Itulah
     * alasan pemeriksaannya ada: kertas yang bisa masuk ke printer belum
     * tentu muat di area cetaknya.
     */
    public const float DEFAULT_MAX_PRINT_WIDTH_MM = 108.0;

    /**
     * @param  float  $mediaWidthMm  lebar kertas, milimeter
     * @param  float  $mediaHeightMm  tinggi kertas, milimeter
     * @param  bool  $hasGap  apakah celah antar label dipakai
     * @param  float  $gapMm  jarak yang diminta; diabaikan kalau `$hasGap` mati
     * @param  float  $maxPrintWidthMm  lebar cetak maksimal printer
     */
    public function __construct(
        public float $mediaWidthMm,
        public float $mediaHeightMm,
        public bool $hasGap,
        public float $gapMm,
        public float $maxPrintWidthMm,
    ) {}

    /**
     * Pengaturan yang dipakai sebelum Owner pernah menyimpan apa pun.
     *
     * Ditulis eksplisit, bukan dari `StickerSheet`, supaya kelas ini tetap
     * bisa dipakai setelah preset lama dihapus tanpa mengubah hasilnya.
     */
    public static function default(): self
    {
        return new self(
            mediaWidthMm: self::DEFAULT_MEDIA_WIDTH_MM,
            mediaHeightMm: self::DEFAULT_MEDIA_HEIGHT_MM,
            hasGap: self::DEFAULT_HAS_GAP,
            gapMm: self::DEFAULT_GAP_MM,
            maxPrintWidthMm: self::DEFAULT_MAX_PRINT_WIDTH_MM,
        );
    }

    /**
     * Pengaturan hasil turunan dari blueprint lama yang masih tersimpan.
     *
     * Satu-satunya jalan masuk untuk instalasi yang memakai `label.sticker_sheet`
     * sebelum ada key baru. Angka blueprint lama justru dipakai apa adanya,
     * jadi Owner tidak perlu menebak angka yang harus diisi ulang.
     */
    public static function fromBlueprint(StickerSheet $blueprint): self
    {
        return new self(
            mediaWidthMm: $blueprint->mediaWidthMm(),
            mediaHeightMm: $blueprint->mediaHeightMm(),
            hasGap: $blueprint->gapMm() > 0.0,
            gapMm: $blueprint->gapMm(),
            maxPrintWidthMm: self::DEFAULT_MAX_PRINT_WIDTH_MM,
        );
    }

    /**
     * Celah yang benar-benar dipakai untuk menghitung grid.
     *
     * Owner bisa mematikan celah tanpa menghapus angkanya. Jadi angka celah
     * yang tersimpan dan yang aktif dibaca bukan dua hal yang sama -- kalau tidak,
     * mematikan centang celah tidak akan mengubah apa pun dan grid tetap memakai 2 mm.
     */
    public function effectiveGapMm(): float
    {
        return $this->hasGap ? $this->gapMm : 0.0;
    }

    /**
     * Grid untuk satu ukuran label.
     *
     * Ukuran label diambil dari preset, bukan dari Owner: isi label (font, QR,
     * padding) terikat ke ukuran fisiknya, jadi label 1,5 x 1,5 cm tidak bisa
     * diperbesar bebas tanpa membuat teksnya menimpa tepi.
     *
     * Grid selalu dikembalikan, termasuk yang tidak bisa dicetak. Form
     * Pengaturan butuh melihat hitungannya supaya Owner bisa membandingkan
     * sendiri sebelum menyimpan.
     */
    public function gridFor(LabelTemplate $template): SheetGrid
    {
        return SheetGridCalculator::calculate(
            mediaWidthMm: $this->mediaWidthMm,
            mediaHeightMm: $this->mediaHeightMm,
            labelWidthMm: $template->widthCm() * 10,
            labelHeightMm: $template->heightCm() * 10,
            gapMm: $this->effectiveGapMm(),
        );
    }

    /**
     * Semua alasan sheet ini tidak bisa dicetak, dalam bentuk datar.
     *
     * Untuk pemanggil yang hanya perlu tahu "boleh atau tidak" -- jalur cetak
     * dan audit. Yang butuh pesan per kolom form memakai
     * `rejectionsBySetting()`.
     *
     * Berisi penolakan geometri dari kalkulator ditambah batas printer yang
     * tidak bisa dikenali kalkulator, karena itu soal kemampuan alat, bukan
     * soal matematika kertas.
     *
     * @return list<string>
     */
    public function rejectionsFor(LabelTemplate $template): array
    {
        return array_merge(...array_values($this->rejectionsBySetting($template)));
    }

    /**
     * Penolakan yang dikelompokkan per setelan, untuk form Pengaturan.
     *
     * Satu-satunya tempat di mana batas printer digabung dengan penolakan
     * geometri. Keduanya dikelompokkan per setelan, jadi form bisa menaruh
     * setiap pesan di bawah input yang salahnya tanpa perlu tahu dari mana
     * asalnya.
     *
     * @return array<string, list<string>>
     */
    public function rejectionsBySetting(LabelTemplate $template): array
    {
        $grouped = SheetGridCalculator::rejectionsBySetting(
            mediaWidthMm: $this->mediaWidthMm,
            mediaHeightMm: $this->mediaHeightMm,
            labelWidthMm: $template->widthCm() * 10,
            labelHeightMm: $template->heightCm() * 10,
            gapMm: $this->effectiveGapMm(),
        );

        foreach ($this->printerRejections() as $message) {
            $grouped[SheetGridCalculator::REJECTION_MEDIA_WIDTH_MM][] = $message;
        }

        return $grouped;
    }

    /**
     * Grid yang siap dicetak, atau `null` kalau ada yang menolak.
     *
     * Ini yang dipakai halaman cetak. `null` berarti label dicetak seperti
     * gulungan satu per halaman, dengan satu peringatan di log -- degradasinya
     * aman, karena label tetap keluar pada ukuran yang benar, cuma tidak
     * digabung ke grid.
     */
    public function usableGridFor(LabelTemplate $template): ?SheetGrid
    {
        if ($this->rejectionsFor($template) !== []) {
            return null;
        }

        return $this->gridFor($template);
    }

    /**
     * Kertas lebih lebar dari area yang bisa dicetak printer.
     *
     * Grid yang muat di kertas tapi melebihi lebar cetak akan terpotong di tepi
     * kanan, dan tidak ada yang mengukurnya sampai Owner complaining bahwa
     * stiker paling kanan selalu rusak.
     *
     * @return list<string>
     */
    private function printerRejections(): array
    {
        if ($this->mediaWidthMm <= $this->maxPrintWidthMm) {
            return [];
        }

        return [sprintf(
            'Kertas %s mm lebih lebar dari area cetak printer %s mm. '
            .'Kolom paling kanan akan terpotong. Pilih kertas yang lebih sempit, atau perbarui batas cetak printer.',
            SheetGrid::mm($this->mediaWidthMm),
            SheetGrid::mm($this->maxPrintWidthMm),
        )];
    }
}
