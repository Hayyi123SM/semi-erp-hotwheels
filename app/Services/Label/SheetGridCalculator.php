<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Menghitung grid label di atas kertas dari lima angka yang diminta Owner.
 *
 * Kelas ini adalah satu-satunya tempat rumus turunan grid hidup. Form
 * pengaturan, pratinjau, dan halaman cetak semuanya memakainya, jadi angka
 * yang tampil di layar dan angka yang keluar dari printer tidak mungkin
 * berbeda -- yang mustahil terjadi kalau masing-masing tempat menghitung
 * sendiri dengan rumus yang ditulis ulang.
 *
 * Yang diinput cuma tiga hal: ukuran kertas, ukuran label, dan celah.
 * Jumlah kolom, jumlah baris, dan jumlah label per lembar semuanya turunan.
 * Alasannya sederhana: kalau "6 kolom" boleh diketik, salah ketik tidak ada
 * yang menangkapnya dan grid yang salah tetap terlihat waras di layar.
 *
 * Kelas ini nilai murni: tidak menyentuh model, database, atau framework.
 */
final class SheetGridCalculator
{
    /**
     * Toleransi pembulatan floating point, dalam milimeter.
     *
     * `floor()` dari hasil bagi bisa jatuh satu langkah di bawah batasnya kalau
     * hasilnya 5,9999999999 alih-alih 6,0. Efeknya satu baris hilang --
     * hilang 8 label yang tidak ikut tercetak tanpa ada pesan apa pun.
     *
     * Nilainya jauh di bawah satu dot printer (0,125 mm), jadi tidak pernah
     * membebaskan kolom yang memang tidak muat.
     */
    private const float FLOAT_GUARD_MM = 1e-9;

    /**
     * Batas bawah celah dalam milimeter, untuk stiker yang punya celah.
     *
     * Di bawah 0,1 mm celahnya sudah bukan ruang yang bisa dilihat, dan pada
     * 203 dpi 0,1 mm hanya 0,8 dot -- printer membulatkan dan hasilnya bukan
     * lagi jarak yang dimaksud.
     */
    public const float MIN_GAP_MM = 0.1;

    /**
     * Batas atas celah dalam milimeter.
     *
     * Bukan karena ada produk seperti itu, tapi karena celah yang besar
     * selalu berarti ada angka lain yang keliru -- misalnya kertas yang salah
     * dipilih. Menolak di sini lebih murah daripada Owner menemukan sendiri
     * setelah mencetak 20 lembar.
     */
    public const float MAX_GAP_MM = 5.0;

    /**
     * Hitung grid dari ukuran kertas, ukuran label, dan celah.
     *
     * Nilai yang tidak bisa dicetak tidak dilempar sebagai exception, tapi
     * dikembalikan sebagai daftar `$rejections`. Ini form pengaturan: yang
     * dibutuhkan bukan exception, tapi kalimat yang bisa ditampilkan di bawah
     * input yang salah.
     *
     * Angka yang tidak masuk akal (nol, negatif) ikut ditangani di sini dan
     * bukan di lapisan validasi request, karena `SheetGrid` harus tetap
     * berisi angka yang terhingga dimanapun ia dibuat -- termasuk dari test.
     */
    public static function calculate(
        float $mediaWidthMm,
        float $mediaHeightMm,
        float $labelWidthMm,
        float $labelHeightMm,
        float $gapMm = 0.0,
    ): SheetGrid {
        $rejections = array_column(
            self::diagnose($mediaWidthMm, $mediaHeightMm, $labelWidthMm, $labelHeightMm, $gapMm),
            'message',
        );

        $columns = self::count(
            $mediaWidthMm,
            $labelWidthMm,
            $gapMm,
        );
        $rows = self::count(
            $mediaHeightMm,
            $labelHeightMm,
            $gapMm,
        );

        $usedWidthMm = self::used($columns, $labelWidthMm, $gapMm);
        $usedHeightMm = self::used($rows, $labelHeightMm, $gapMm);

        return new SheetGrid(
            mediaWidthMm: $mediaWidthMm,
            mediaHeightMm: $mediaHeightMm,
            labelWidthMm: $labelWidthMm,
            labelHeightMm: $labelHeightMm,
            gapMm: $gapMm,
            columns: $columns,
            rows: $rows,
            usedWidthMm: $usedWidthMm,
            usedHeightMm: $usedHeightMm,
            slackWidthMm: $mediaWidthMm - $usedWidthMm,
            slackHeightMm: $mediaHeightMm - $usedHeightMm,
            rejections: $rejections,
            warnings: self::warnings(
                $mediaWidthMm,
                $mediaHeightMm,
                $labelWidthMm,
                $labelHeightMm,
                $gapMm,
                $mediaWidthMm - $usedWidthMm,
                $columns,
                $rows,
            ),
        );
    }

    /**
     * Berapa label yang muat ke satu arah.
     *
     * `n * label + (n - 1) * gap <= media` ditulis ulang jadi
     * `n * (label + gap) <= media + gap` supaya pembagiannya sekali jalan.
     *
     * `+ gap` di pembagi dan `- gap` di pengali bukan hiasan: deret label
     * memakai `n * label + (n - 1) * gap`, jadi sisa untuk label terakhir
     * adalah media ditambah satu gap. Tanpa itu, kertas yang pas persis
     * kehilangan satu baris -- dan satu baris itu 6 label yang tidak ikut
     * tercetak.
     */
    private static function count(float $mediaMm, float $labelMm, float $gapMm): int
    {
        $pitchMm = $labelMm + $gapMm;

        if ($pitchMm <= 0.0 || $mediaMm <= 0.0) {
            return 0;
        }

        $count = (int) floor((($mediaMm + $gapMm) / $pitchMm) + self::FLOAT_GUARD_MM);

        return max(0, $count);
    }

    /**
     * Panjang yang dipakai deret label pada satu arah.
     */
    private static function used(int $count, float $labelMm, float $gapMm): float
    {
        if ($count < 1) {
            return 0.0;
        }

        return ($count * $labelMm) + (($count - 1) * $gapMm);
    }

    /**
     * Penolakan dikelompokkan per setelan yang salah, bukan per nama kolom form.
     *
     * "Per setelan" adalah satuan yang bisa dipakai di mana saja: form
     * pemetaannya ke kolom, sedangkan printer dan audit tinggal memakainya apa
     * adanya. Kalau kodenya memakai nama kolom form, setiap kelas di luar
     * controller ikut terikat ke bentuk form.
     */
    public const string REJECTION_MEDIA_WIDTH_MM = 'media_width_mm';

    public const string REJECTION_MEDIA_HEIGHT_MM = 'media_height_mm';

    public const string REJECTION_LABEL_MM = 'label_mm';

    public const string REJECTION_GAP_MM = 'gap_mm';

    /**
     * Penolakan yang dikelompokkan per setelan, dengan pesannya.
     *
     * Dipakai form Pengaturan supaya setiap pesan muncul di bawah input yang
     * salahnya. Bentuk kembaliannya dictionary supaya pemanggil bebas
     * menambahkan pesan sendiri tanpa menyusun ulang apa pun.
     *
     * @return array<string, list<string>>
     */
    public static function rejectionsBySetting(
        float $mediaWidthMm,
        float $mediaHeightMm,
        float $labelWidthMm,
        float $labelHeightMm,
        float $gapMm = 0.0,
    ): array {
        $grouped = [];

        foreach (self::diagnose($mediaWidthMm, $mediaHeightMm, $labelWidthMm, $labelHeightMm, $gapMm) as $rejection) {
            $grouped[$rejection['setting']][] = $rejection['message'];
        }

        return $grouped;
    }

    /**
     * Setiap penolakan membawa setelan yang salahnya, bukan cuma teksnya.
     *
     * Tanpa itu, form hanya bisa menumpuk semua pesan di satu tempat, dan
     * Owner tidak tahu harus memperbaiki kolom yang mana. Karena itu
     * `rejectionsBySetting()` mengembalikan semuanya sudah dikelompokkan, dan
     * pemanggil tidak perlu menebak dari isi pesannya.
     *
     * Urutannya dari yang paling mendasar: ukuran yang tidak masuk akal dulu,
     * baru hubungannya. Urutan ini bukan untuk mesin -- pesan pertama yang
     * dibaca user adalah yang paling mungkin jadi penyebab semua pesan lain.
     *
     * @return list<array{setting: string, message: string}>
     */
    private static function diagnose(
        float $mediaWidthMm,
        float $mediaHeightMm,
        float $labelWidthMm,
        float $labelHeightMm,
        float $gapMm,
    ): array {
        $rejections = [];

        // Lebar dan tinggi dicek terpisah, bukan sebagai satu kondisi "kertas".
        // Satu pesan gabungan untuk dua masalah membuat Owner menebak kolom mana
        // yang salah, padahal tinggal dua baris errornya.
        if ($mediaWidthMm <= 0.0) {
            $rejections[] = self::rejection(self::REJECTION_MEDIA_WIDTH_MM, 'Lebar kertas harus lebih besar dari nol.');
        }

        if ($mediaHeightMm <= 0.0) {
            $rejections[] = self::rejection(self::REJECTION_MEDIA_HEIGHT_MM, 'Tinggi kertas harus lebih besar dari nol.');
        }

        if ($labelWidthMm <= 0.0 || $labelHeightMm <= 0.0) {
            $rejections[] = self::rejection(self::REJECTION_LABEL_MM, 'Ukuran label harus lebih besar dari nol.');

            return $rejections;
        }

        if ($gapMm < 0.0) {
            $rejections[] = self::rejection(self::REJECTION_GAP_MM, 'Celah tidak boleh negatif.');

            return $rejections;
        }

        if ($gapMm > 0.0 && $gapMm >= min($labelWidthMm, $labelHeightMm)) {
            $rejections[] = self::rejection(self::REJECTION_GAP_MM, sprintf(
                'Celah %s mm tidak mungkin untuk label %s x %s mm. Celah selalu lebih kecil dari labelnya.',
                SheetGrid::mm($gapMm),
                SheetGrid::mm($labelWidthMm),
                SheetGrid::mm($labelHeightMm),
            ));

            return $rejections;
        }

        // Celah di luar rentang yang masuk akal ditolak di sini supaya
        // perkecilannya tidak diam-diam membulatkan atau memperlebar grid.
        if ($gapMm > 0.0 && ($gapMm < self::MIN_GAP_MM || $gapMm > self::MAX_GAP_MM)) {
            $rejections[] = self::rejection(self::REJECTION_GAP_MM, sprintf(
                'Celah %s mm di luar rentang %s sampai %s mm.',
                SheetGrid::mm($gapMm),
                SheetGrid::mm(self::MIN_GAP_MM),
                SheetGrid::mm(self::MAX_GAP_MM),
            ));

            return $rejections;
        }

        // Penolakan ini disetelkan ke sisi KERTAS, bukan ke sisi label, karena
        // Owner tidak mengubah ukuran label di form ini -- dia memilih preset.
        // Yang bisa dia ubah adalah kertasnya, jadi pesan harus mengarah ke sana.
        //
        // Hanya bermakna setelah ukuran mentah lolos: "label lebih lebar dari
        // kertas" tidak bisa dibaca kalau kertasnya sendiri belum masuk akal.
        if ($labelWidthMm > $mediaWidthMm) {
            $rejections[] = self::rejection(self::REJECTION_MEDIA_WIDTH_MM, sprintf(
                'Label selebar %s mm tidak muat di kertas selebar %s mm.',
                SheetGrid::mm($labelWidthMm),
                SheetGrid::mm($mediaWidthMm),
            ));
        }

        if ($labelHeightMm > $mediaHeightMm) {
            $rejections[] = self::rejection(self::REJECTION_MEDIA_HEIGHT_MM, sprintf(
                'Label setinggi %s mm tidak muat di kertas setinggi %s mm.',
                SheetGrid::mm($labelHeightMm),
                SheetGrid::mm($mediaHeightMm),
            ));
        }

        return $rejections;
    }

    /**
     * Satu penolakan beserta setelan yang salahnya.
     *
     * @return array{setting: string, message: string}
     */
    private static function rejection(string $setting, string $message): array
    {
        return ['setting' => $setting, 'message' => $message];
    }

    /**
     * Hal yang sah dicetak tapi perlu dilihat orang.
     *
     * Peringatan tidak memblokir penyimpanan. Ada sheet yang memang menyisakan
     * ruang kosong, dan teguran yang tidak bisa ditabay hanya mengajarkan orang
     * untuk mengabaikan peringatan yang benar.
     *
     * Sisa VERTIKAL sengaja tidak diperingatkan. Kolom terisi penuh dan sisa
     * bawah adalah hal wajar pada kertas stiker: ruang sisa itu dipakai printer
     * untuk menerima kertas. Yang mencurigakan hanya sisa horizontal, karena
     * kertas die-cut memenuhi lebar kertasnya.
     *
     * @return list<string>
     */
    private static function warnings(
        float $mediaWidthMm,
        float $mediaHeightMm,
        float $labelWidthMm,
        float $labelHeightMm,
        float $gapMm,
        float $slackWidthMm,
        int $columns,
        int $rows,
    ): array {
        $warnings = [];

        if ($slackWidthMm > SheetGrid::SLACK_TOLERANCE_MM) {
            $warnings[] = self::widthSlackWarning(
                $mediaWidthMm,
                $mediaHeightMm,
                $labelWidthMm,
                $labelHeightMm,
                $gapMm,
                $slackWidthMm,
                $columns,
                $rows,
            );
        }

        foreach (
            [
                'lebar kertas' => $mediaWidthMm,
                'tinggi kertas' => $mediaHeightMm,
                'lebar label' => $labelWidthMm,
                'tinggi label' => $labelHeightMm,
                'celah' => $gapMm,
            ] as $name => $value
        ) {
            if ($value <= 0.0) {
                continue;
            }

            if (self::fitsPrinterDots($value)) {
                continue;
            }

            // Besar errornya dinyatakan dalam DOT, bukan milimeter. Setengah
            // dot = 0,0625 mm, dan angka itu tidak bisa ditulis dengan satu
            // angka desimal tanpa kehilangan maknanya -- "0,06 mm" dibaca
            // sebagai kelipatan yang bisa digambar, padahal bukan.
            $warnings[] = sprintf(
                '%s %s mm bukan kelipatan 1 dot (%s mm), jadi printer 203 dpi akan '
                .'membulatkan dan label bisa bergeser sampai 0,5 dot.',
                ucfirst($name),
                SheetGrid::mm($value),
                SheetGrid::dotQuantumMm(),
            );
        }

        return $warnings;
    }

    /**
     * Pesan sisa lebar horizontal, berikut angka yang harus diubah.
     *
     * Pesan yang hanya menyalahkan tidak berguna: "sisa lebar 16,6 mm,
     * biasanya salah satu ukuran keliru" itu benar, tapi Owner tidak tahu
     * angka mana yang harus diganti. Padahal celah adalah angka yang paling
     * mungkin salah, karena Owner menurunkannya dari pengukuran celah fisik,
     * dan hasil ukur jarang sama persis dengan angka yang benar.
     *
     * Celah terbesar yang masih memuat satu kolom tambahan dihitung di sini,
     * jadi pesannya menyebut solusinya: "dengan celah 2 mm atau kurang, muat
     * 6 kolom". Di kertas 100 x 150 mm dengan label 15 mm, inilah selisih antara
     * 5 dan 6 kolom: celah 2,1 mm membuang satu kolom, jadi delapan label per
     * lembar hilang tanpa jejak.
     *
     * Bila baris ikut berubah pada celah itu, ikut disebut di pesan yang sama.
     * Melewatkan begitu saja akan menyesatkan: Owner bisa mendapat satu kolom
     * lebih sekaligus satu baris tambahan yang tidak ada di kertas fisiknya,
     * dan jumlah label per lembar jadi tidak sesuai.
     */
    private static function widthSlackWarning(
        float $mediaWidthMm,
        float $mediaHeightMm,
        float $labelWidthMm,
        float $labelHeightMm,
        float $gapMm,
        float $slackWidthMm,
        int $columns,
        int $rows,
    ): string {
        $ceiling = self::extraColumnGapCeilingMm($columns, $mediaWidthMm, $labelWidthMm);

        if ($ceiling === null || $ceiling >= $gapMm) {
            return sprintf(
                'Sisa lebar %s mm. Kertas stiker hasil die-cut memenuhi lebar kertasnya, '
                .'jadi angka ini biasanya berarti salah satu ukuran keliru.',
                SheetGrid::mm($slackWidthMm),
            );
        }

        $message = sprintf(
            'Sisa lebar %s mm. Kertas stiker hasil die-cut memenuhi lebar kertasnya: '
            .'dengan celah %s mm atau kurang, muat %d kolom (dipakai %s mm).',
            SheetGrid::mm($slackWidthMm),
            SheetGrid::mm($ceiling),
            $columns + 1,
            SheetGrid::mm(self::used($columns + 1, $labelWidthMm, $ceiling)),
        );

        $rowsAfter = self::count($mediaHeightMm, $labelHeightMm, $ceiling);

        if ($rowsAfter > $rows) {
            $message .= sprintf(
                ' Catatan: dengan celah segitu baris ikut menjadi %d, jadi jumlah label per lembar ikut berubah.',
                $rowsAfter,
            );
        }

        return $message;
    }

    /**
     * Celah terbesar yang masih memuat satu kolom tambahan.
     *
     * Dari `n * label + (n - 1) * gap <= media`, supaya kolom berjumlah `n + 1`
     * dibutuhkan `(n + 1) * label + n * gap <= media`, jadi batasnya
     * `(media - (n + 1) * label) / n`.
     *
     * Hasilnya dibulatkan ke BAWAH ke kelipatan 1 dot printer, dan itu bukan
     * detail teknis: saran yang tidak bisa digambar printer akan ditolak Owner
     * sebagai tidak masuk akal, sementara kelipatan terdekat yang lebih besar
     * -- misalnya 2,125 mm dari 2,1 mm -- justru membatalkan lagi kolom yang
     * baru saja disarankan.
     *
     * `null` kalau tidak ada celah yang membantu: labelnya sendiri sudah
     * memenuhi lebar kertas, atau batasnya jatuh di bawah celah terkecil yang
     * masih masuk akal.
     */
    public static function extraColumnGapCeilingMm(int $columns, float $mediaWidthMm, float $labelWidthMm): ?float
    {
        if ($columns < 1 || $labelWidthMm <= 0.0) {
            return null;
        }

        $rawCeiling = ($mediaWidthMm - (($columns + 1) * $labelWidthMm)) / $columns;

        if ($rawCeiling <= self::MIN_GAP_MM) {
            return null;
        }

        $quantumMm = 1 / SheetGrid::DOTS_PER_MM;
        $ceiling = floor(($rawCeiling / $quantumMm) + self::FLOAT_GUARD_MM) * $quantumMm;

        return $ceiling <= self::MIN_GAP_MM ? null : round($ceiling, 4);
    }

    /**
     * Apakah angka ini bisa digambar persis oleh printer 203 dpi.
     *
     * Satu dot = 0,125 mm. Angka yang bukan kelipatannya tidak akan pernah
     * tercetak persis -- printer membulatkan, jadi hasilnya meleset setengah
     * dot atau lebih.
     */
    private static function fitsPrinterDots(float $valueMm): bool
    {
        $quantumMm = 1 / SheetGrid::DOTS_PER_MM;

        return abs((round($valueMm / $quantumMm) * $quantumMm) - $valueMm) < self::FLOAT_GUARD_MM;
    }
}
