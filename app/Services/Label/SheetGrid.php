<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Hasil hitungan satu grid label di atas kertas.
 *
 * Angka di sini semua turunan dari lima angka masukannya: ukuran kertas,
 * ukuran label, dan celah. Tidak ada satu pun yang boleh diisi manual --
 * kalau jumlah kolom ikut diinput, salah ketik tidak ada yang menangkapnya
 * dan grid yang keluar tetap terlihat waras di layar.
 *
 * Kelas ini nilai murni: tidak menyentuh model, database, atau framework.
 * `SheetGridCalculator` yang menghitung; kelas ini hanya menyimpan hasil
 * dan menjawab pertanyaan turunannya.
 */
final readonly class SheetGrid
{
    /**
     * Dot per milimeter untuk printer 203 dpi.
     *
     * 203 dpi = 203/25,4 = 7,99 dot/mm, jadi 8 yang dipakai. Satu dot =
     * 0,125 mm, dan angka itu jadi batas ketelitian pengukuran yang
     * dilaporkan `SheetGridCalculator`.
     */
    public const int DOTS_PER_MM = 8;

    /**
     * Toleransi sisa horizontal, dalam milimeter.
     *
     * Sisa yang lebih besar dari ini dianggap mencurigakan, bukan sekadar
     * pembulatan, dan hanya di arah horizontal: Owner yang menghitung sendiri
     * akan melihat "3 x 6 = 18 label" pada kertas yang sebenarnya bisa
     * menampung 6 kolom. Itu informasi yang perlu dibaca, bukan angka yang boleh
     * dibulatkan diam-diam.
     *
     * Arah vertikal tidak diperiksa. Kertas stiker hasil die-cut tidak selalu
     * setinggi persis angka yang tertera, dan sisa bawah adalah hal yang wajar
     * pada kertas yang lebih tinggi dari grid -- menambah peringatan untuk itu
     * hanya membuat Owner mengabaikan peringatan yang memang perlu dibaca.
     */
    public const float SLACK_TOLERANCE_MM = 0.5;

    /**
     * @param  list<string>  $rejections  alasan grid ini tidak bisa dicetak
     * @param  list<string>  $warnings  angka yang sah tapi perlu dilihat
     */
    public function __construct(
        public float $mediaWidthMm,
        public float $mediaHeightMm,
        public float $labelWidthMm,
        public float $labelHeightMm,
        public float $gapMm,
        public int $columns,
        public int $rows,
        public float $usedWidthMm,
        public float $usedHeightMm,
        public float $slackWidthMm,
        public float $slackHeightMm,
        public array $rejections,
        public array $warnings,
    ) {}

    /**
     * Apakah grid ini menghasilkan label yang masuk akal untuk dicetak.
     *
     * Satu-satunya syaratnya ada kolom dan baris. Kalau tidak, tidak ada
     * tempat meletakkan label sama sekali.
     *
     * `$rejections` ikut diperiksa karena ada penolakan yang tidak berasal
     * dari jumlah kolom atau baris -- misalnya celah yang lebih lebar dari
     * labelnya sendiri. Grid seperti itu secara matematika bisa digambar,
     * tapi tidak akan pernah ada di toko.
     */
    public function isPrintable(): bool
    {
        return $this->columns >= 1
            && $this->rows >= 1
            && $this->rejections === [];
    }

    /**
     * Berapa label yang keluar dari satu lembar kertas.
     *
     * Nol kalau grid tidak bisa dicetak: lebih baik tidak mencetak apa pun
     * daripada mencetak label yang meleset dari kolomnya.
     */
    public function labelsPerSheet(): int
    {
        return $this->isPrintable() ? $this->columns * $this->rows : 0;
    }

    /**
     * Lebar media dalam dot, untuk dikalibrasi di depan printer.
     *
     * 100 mm pada 203 dpi = 800 dot. Angka inilah yang diukur operator dengan
     * penggaris setelah print pertama, karena dialog cetak browser bisa saja
     * menampilkan ukuran kertas berbeda dari yang sebenarnya dimuat dan
     * selisih yang kecil tidak akan terlihat di struk.
     */
    public function mediaWidthDots(): int
    {
        return (int) round($this->mediaWidthMm * self::DOTS_PER_MM);
    }

    /**
     * Tinggi media dalam dot.
     */
    public function mediaHeightDots(): int
    {
        return (int) round($this->mediaHeightMm * self::DOTS_PER_MM);
    }

    /**
     * Ukuran kertas untuk `@page`, dalam CSS.
     *
     * Ini ukuran MEDIA, bukan ukuran label. Mode gulungan memakai ukuran
     * label sebagai ukuran halaman karena printer thermal mencetak satu
     * label satu halaman; pada mode stiker, ukuran halaman harus ukuran
     * kertasnya. Kalau tidak, seluruh grid akan dicoba muat ke dalam halaman
     * sebesar satu stiker.
     */
    public function pageSizeCss(): string
    {
        return $this->cssMm($this->mediaWidthMm).' '.$this->cssMm($this->mediaHeightMm);
    }

    /**
     * Nilai custom property untuk `.label-sheet--grid`.
     *
     * CSS memakai `var()` untuk lebar, tinggi, dan celah, bukan angka tetap.
     * Itulah yang membuat kertas atau label baru tidak perlu menyentuh
     * stylesheet sama sekali, dan tidak ada angka grid yang hidup di dua
     * tempat.
     *
     * Panjang selalu membawa satuan `mm`; hanya jumlah kolom dan baris yang
     * tanpa satuan. `repeat(6, 15)` tanpa satuan tidak valid, dan grid akan
     * runtuh tanpa pesan error apa pun.
     *
     * `--rows` ikut dituliskan walaupun `grid-auto-rows` yang menggambar
     * tingginya. Grid memang tidak butuh baris untuk menggambar, tapi
     * nilainya yang dibandingkan lewat devtools saat ada grid yang meleset.
     *
     * @return array<string, string>
     */
    public function gridCustomProperties(): array
    {
        return [
            '--sheet-w' => $this->cssMm($this->mediaWidthMm),
            '--sheet-h' => $this->cssMm($this->mediaHeightMm),
            '--label-w' => $this->cssMm($this->labelWidthMm),
            '--label-h' => $this->cssMm($this->labelHeightMm),
            '--gap' => $this->cssMm($this->gapMm),
            '--cols' => (string) $this->columns,
            '--rows' => (string) $this->rows,
        ];
    }

    /**
     * Deklarasi custom property siap tempel di atribut `style`.
     */
    public function gridStyle(): string
    {
        $declarations = [];

        foreach ($this->gridCustomProperties() as $property => $value) {
            $declarations[] = $property.':'.$value;
        }

        return implode(';', $declarations);
    }

    /**
     * Ringkasan untuk badge di halaman cetak.
     *
     * Ringkas dan angka saja, karena yang dibutuhkan operator bukan teori
     * gridnya tapi jumlah label yang akan keluar per halaman.
     */
    public function summary(): string
    {
        return sprintf(
            '%s x %s mm · %d kolom x %d baris = %d label per halaman',
            self::mm($this->mediaWidthMm),
            self::mm($this->mediaHeightMm),
            $this->columns,
            $this->rows,
            $this->labelsPerSheet(),
        );
    }

    /**
     * Sisa tinggi untuk badge, lengkap dengan satuan.
     *
     * Dipakai di badge halaman cetak supaya format angkanya diputuskan di
     * PHP. Kalau `number_format` dipanggil di Blade, angka 16,0 bisa tampil
     * sebagai "16.0" atau "16" tergantung siapa yang menulis, dan tidak ada
     * test yang gagal karena Blade bukan kode yang diuji.
     */
    public function trailingSlackLabel(): string
    {
        return self::mm($this->slackHeightMm).' mm';
    }

    /**
     * Panjang CSS dengan satuan: `15mm`, `15.5mm`.
     *
     * Nol di belakang koma dibuang supaya `15.00mm` tidak ikut ke `@page`.
     * Browser yang membulatkan ukuran kertas karena nol excessif akan memotong
     * tepi kanan label, dan gejala itu jauh lebih sulit dibaca daripada
     * "ukuran kertas salah".
     */
    private function cssMm(float $value): string
    {
        return self::cssNumber($value).'mm';
    }

    /**
     * Angka CSS memakai TITIK sebagai pemisah desimal, bukan koma.
     *
     * Ini bukan pilihan gaya. `15,5mm` bukan nilai CSS yang valid, jadi grid
     * akan ditolak seluruhnya dan grid runtuh tanpa pesan error. Karena itu
     * pemformat CSS dan pemformat angka manusia di bawah ini tidak boleh
     * berbagi satu fungsi -- pemformat manusia selalu salah di sini.
     */
    private static function cssNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Angka untuk ditampilkan ke orang: koma sebagai pemisah desimal, tanpa
     * nol di belakang koma.
     *
     * Pemisahnya koma karena hasilnya dibaca orang di Indonesia. Angka
     * seperti ini tidak sah masuk ke CSS.
     */
    public static function mm(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Nilai untuk `<input type="number">`: titik sebagai pemisah desimal.
     *
     * Berbeda dengan `mm()` di atas, yang hasilnya dibaca manusia. Input angka
     * hanya menerima titik, dan browser mengirim apa adanya yang ada di kolom
     * itu -- jadi `mm()` yang menulis "2,5" akan sampai ke server sebagai "2,5",
     * ditolak aturan `numeric`, dan Owner melihat pesan "Celah harus berupa
     * angka" untuk angka yang dia ketik sendiri.
     *
     * Tiga angka desimal, bukan dua: satu dot printer = 0,125 mm, jadi celah
     * 0,125 mm harus bisa diketik tanpa dibulatkan jadi 0,13 -- angka yang
     * bukan lagi kelipatan yang bisa digambar printer. Nol di belakang titik
     * tetap dibuang supaya 100,0 tidak muncul sebagai "100.000" di kolom yang
     * isinya cuma beberapa digit.
     */
    public static function inputValue(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Satu dot printer dalam milimeter, untuk pesan peringatan.
     *
     * Tiga angka desimal, bukan dua. Satu dot = 0,125 mm, dan membulatkan ini
     * jadi "0,13 mm" mengubah artinya: 0,13 mm bukan kelipatan yang bisa digambar
     * printer, sementara 0,125 mm justru kelipatan yang tepat.
     */
    public static function dotQuantumMm(): string
    {
        return number_format(1 / self::DOTS_PER_MM, 3, ',', '');
    }
}
