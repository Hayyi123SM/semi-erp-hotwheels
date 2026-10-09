<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelPaperMode;
use App\Models\LabelPrintJob;
use App\Models\Rack;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mode cetak di atas kertas stiker.
 *
 * Feature test ini menutup rantai yang lengkap: dari angka yang diketik Owner,
 * lewat perhitungan grid, sampai HTML yang benar-benar sampai ke browser. Test
 * geometri memastikan angkanya benar; test ini memastikan angka itu benar-benar
 * terpakai -- karena `@page` yang salah memberikan gejala yang tidak terlihat:
 * label tetap tercetak pada ukuran yang benar, cuma posisinya meleset.
 *
 * Yang paling dijaga adalah `@page` mengikuti angka Owner. Kalau cetakan diam-diam
 * kembali ke kertas bawaan 100 x 150 mm, semua test lain tetap hijau -- formnya
 * sudah benar, gejalanya cuma muncul di printer.
 *
 * Yang paling dijaga adalah sheet mode TIDAK merusak mode gulungan. Mode
 * gulungan sudah dipakai sejak awal, jadi setiap perubahan di sini harus
 * terbukti tidak menyentuhnya.
 */
class LabelStickerSheetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Aktifkan mode stiker lewat Setting, bukan lewat form.
     *
     * Form dan validasinya diuji di `PrinterSettingsTest`. Di sini yang perlu
     * dijamin adalah apa yang terjadi *setelah* setting tersimpan, jadi menyetel
     * langsung ke tabel membuat test ini tidak ikut gagal karena perubahan
     * validasi form.
     */
    private function useStickerSheet(
        ?string $template = null,
        float $widthMm = 100.0,
        float $heightMm = 150.0,
        float $gapMm = 2.0,
    ): void {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Sheet->value);
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, $widthMm);
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, $heightMm);
        Setting::set(LabelPrinterSettings::SHEET_HAS_GAP_KEY, $gapMm > 0.0);
        Setting::set(LabelPrinterSettings::SHEET_GAP_MM_KEY, $gapMm);
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, $template ?? '1.5x1.5');
    }

    private function job(int $copies): LabelPrintJob
    {
        return LabelPrintJob::factory()->create([
            'lot_id' => StockLot::factory()->create(['sku' => 'CN01-HW-001-U03'])->id,
            'copies' => $copies,
        ]);
    }

    private function printJobs(int $copies): string
    {
        $job = $this->job($copies);

        return $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk()
            ->getContent();
    }

    /**
     * Jumlah label per lembar, sesuai urutan lembar.
     *
     * Dipecah pada pembuka `.label-sheet` lalu dihitung per bagian, bukan
     * dengan regex non-greedy sampai `</div>` pertama: label punya banyak
     * elemen anak, jadi pola seperti itu selalu berhenti di label pertama dan
     * melaporkan 1 untuk semua lembar.
     *
     * @return list<int>
     */
    private function labelsPerSheetIn(string $html): array
    {
        $parts = preg_split('/<div class="label-sheet/', $html) ?: [];

        // Bagian pertama adalah kepala dokumen, bukan lembar.
        array_shift($parts);

        return array_map(
            static fn (string $part): int => substr_count($part, 'class="label label--'),
            $parts,
        );
    }

    private function sheetCountIn(string $html): int
    {
        return substr_count($html, 'label-sheet--grid');
    }

    #[Test]
    public function the_page_size_is_the_sheet_not_the_label(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(3);

        // 100 mm x 150 mm, bukan 1,5 x 1,5 cm. Kalau yang ini salah, dialog
        // cetak menawarkan kertas seukuran satu stiker untuk 48 stiker.
        $this->assertStringContainsString('size: 100mm 150mm', $html);
        $this->assertStringNotContainsString('size: 1.5cm 1.5cm', $html);
        $this->assertStringContainsString('margin: 0', $html);
    }

    #[Test]
    public function the_grid_geometry_is_written_onto_the_sheet(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(1);

        // Angka ini datang dari kalkulator grid, bukan dari stylesheet, dan
        // CSS membacanya lewat var(). Kalau satuannya hilang, `repeat(6, 15)`
        // tidak valid dan grid runtuh tanpa pesan error.
        $this->assertStringContainsString(
            '--sheet-w:100mm;--sheet-h:150mm;--label-w:15mm;--label-h:15mm;--gap:2mm;--cols:6;--rows:8',
            $html,
        );
    }

    #[Test]
    public function a_partial_sheet_is_still_padded_to_a_full_grid(): void
    {
        $this->useStickerSheet();

        // 3 label pada lembar 48 kolom x 8 baris: grid harus tetap 6 kolom, sisa
        // sel kosong dan tidak boleh menyusut jadi 3 kolom.
        $this->assertStringContainsString('--cols:6', $this->printJobs(3));
    }

    #[Test]
    public function labels_beyond_one_sheet_are_split_across_several_sheets(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(120);

        // 120 = 48 + 48 + 24. Lembar terakhir memang setengah kosong, dan itu
        // lebih aman daripada memotong sisanya jadi "nanti saja" -- operator yang
        // menekan cetak sekali harus mendapatkan semuanya.
        $this->assertSame(3, $this->sheetCountIn($html));
        $this->assertSame([48, 48, 24], $this->labelsPerSheetIn($html));
        $this->assertSame(3, substr_count($html, 'data-sheet-total="3"'));
    }

    /**
     * 49 label adalah kasus yang paling mudah salah: satu label meleset ke
     * lembar berikutnya berarti label ke-49 keluar sendirian dan operator
     * harus mengganti kertas untuk satu stiker.
     */
    #[Test]
    public function the_sheet_boundary_does_not_split_a_label(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(49);

        $this->assertSame(2, $this->sheetCountIn($html));
        $this->assertSame([48, 1], $this->labelsPerSheetIn($html));
    }

    #[Test]
    public function the_page_states_how_many_labels_come_out_per_page(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(1);

        // Angka yang dibandingkan operator dengan isi laci. Tanpa ini, halaman
        // hanya menampilkan "ukuran label 1,5 x 1,5" padahal yang keluar 48
        // label per halaman.
        $this->assertStringContainsString('48 label per halaman', $html);
        $this->assertStringContainsString('6 kolom', $html);
        $this->assertStringContainsString('Sisa bawah 16 mm', $html);

        // Dialog cetak harus diminta Custom, Margins: None, Scale: 100%.
        $this->assertStringContainsString('Custom', $html);
        $this->assertStringContainsString('Margins: None', $html);
        $this->assertStringContainsString('Scale: 100%', $html);
    }

    #[Test]
    public function the_test_print_follows_the_active_paper_mode(): void
    {
        $this->useStickerSheet();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('size: 100mm 150mm', $html);
        $this->assertSame(1, $this->sheetCountIn($html));
    }

    /**
     * Mode gulungan adalah bawaan dan tidak boleh berubah karena fitur ini ada.
     *
     * Diuji dengan `@page` DAN struktur HTML, karena pemecahan per halaman di
     * CSS ikut berubah selector-nya -- dan selector yang salah akan membuat satu
     * label jadi 48 halaman tanpa ada yang gagal di sini.
     */
    #[Test]
    public function the_roll_mode_is_untouched(): void
    {
        // Template yang sama persis dengan test mode stiker, supaya yang dibandingkan
        // benar-benar bedanya mode kertas dan bukan bedanya ukuran label.
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, '1.5x1.5');

        $html = $this->printJobs(3);

        $this->assertStringContainsString('size: 1.5cm 1.5cm', $html);
        $this->assertStringNotContainsString('label-sheet--grid', $html);
        $this->assertStringNotContainsString('--cols', $html);
        $this->assertSame(1, substr_count($html, 'class="label-sheet"'));
        $this->assertStringContainsString('1 label per halaman', $html);
    }

    #[Test]
    public function the_roll_mode_is_the_default_when_nothing_is_configured(): void
    {
        // Tidak ada Setting sama sekali: ini keadaan semua instalasi yang belum
        // pernah menyentuh halaman Perangkat. `PAPER_MODE_KEY` tidak pernah ada
        // sebelum fitur ini, jadi "tidak ada" harus dibaca sebagai gulungan.
        $this->assertNull(Setting::get(LabelPrinterSettings::PAPER_MODE_KEY));

        $html = $this->printJobs(1);

        // Ukuran label bawaannya 3 x 2 cm, jadi `@page` juga 3 x 2 cm.
        $this->assertStringContainsString('size: 3cm 2cm', $html);
        $this->assertSame(0, $this->sheetCountIn($html));
    }

    #[Test]
    public function an_unknown_saved_paper_mode_falls_back_to_roll(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, 'kertas-hantu');
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, '1.5x1.5');

        $html = $this->printJobs(1);

        $this->assertStringContainsString('size: 1.5cm 1.5cm', $html);
        $this->assertSame(0, $this->sheetCountIn($html));
    }

    /**
     * Kertas yang lebih kecil daripada label tidak boleh dipaksa jadi grid.
     *
     * Label yang lebih besar dari kertasnya akan menimpa stiker sebelahnya dan
     * menutupi celah, dan printer thermal bisa membacanya sebagai media
     * continuous. Degradasinya ke mode gulungan -- ukuran label tetap benar, hanya
     * tidak digabung -- dan pencatatannya ditulis supaya tidak terlihat seperti
     * setelan itu sengaja.
     *
     * Skenarionya kertas 30 x 30 mm dengan label 4 x 3 cm, bukan "label 3 x 2 di
     * kertas 100 x 150". Label yang lebih besar dari kolom blueprint lama memang
     * tidak bisa terjadi lagi: ukuran label sekarang datang dari preset, jadi
     * 3 x 2 cm muat wajar di atas 100 x 150 mm.
     */
    #[Test]
    public function a_label_that_does_not_fit_the_paper_falls_back_to_roll_and_is_logged(): void
    {
        Log::spy();

        $this->useStickerSheet('4x3', widthMm: 30.0, heightMm: 30.0);

        $html = $this->printJobs(3);

        $this->assertStringContainsString('size: 4cm 3cm', $html);
        $this->assertSame(0, $this->sheetCountIn($html));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Mode stiker diabaikan')
                && ($context['template'] ?? null) === '4x3'
                && ($context['media_width_mm'] ?? null) === 30.0)
            ->once();
    }

    /**
     * Angka kertas Owner harus sampai ke `@page` dan ke grid.
     *
     * Ini yang membuat seluruh fitur ini berarti: kalau cetakan diam-diam tetap
     * memakai 100 x 150 mm bawaan, formnya sudah benar, test formnya juga hijau,
     * dan yang salah baru muncul di printer.
     */
    #[Test]
    public function the_numbers_the_owner_typed_reach_the_print(): void
    {
        $this->useStickerSheet('3x2', widthMm: 70.0, heightMm: 30.0, gapMm: 1.0);

        $html = $this->printJobs(4);

        $this->assertStringContainsString('size: 70mm 30mm', $html);
        $this->assertStringContainsString(
            '70 x 30 mm · 2 kolom x 1 baris = 2 label per halaman',
            $html,
        );
        $this->assertStringContainsString(
            '--sheet-w:70mm;--sheet-h:30mm;--label-w:30mm;--label-h:20mm;--gap:1mm;--cols:2;--rows:1',
            $html,
        );
        $this->assertSame([2, 2], $this->labelsPerSheetIn($html));
    }

    /**
     * Instalasi yang masih menyimpan preset lama harus tetap tercetak sama.
     *
     * Tanpa test ini, penghapusan jalur lama terlihat aman: preset lama tidak
     * lagi dibaca sebagai grid, jadi kalau penerjemahannya rusak, cetakannya
     * diam-diam jatuh ke gulungan dengan satu label per halaman -- dan tidak ada
     * test lain yang gagal.
     */
    #[Test]
    public function an_install_that_only_still_has_the_old_preset_prints_the_same_sheet(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Sheet->value);
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, 'bp-td110bt-100x150');
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, '1.5x1.5');

        $html = $this->printJobs(49);

        $this->assertStringContainsString('size: 100mm 150mm', $html);
        $this->assertStringContainsString(
            '100 x 150 mm · 6 kolom x 8 baris = 48 label per halaman',
            $html,
            'Preset lama harus diterjemahkan jadi grid yang sama, bukan jadi bawaan lain.',
        );
        $this->assertSame([48, 1], $this->labelsPerSheetIn($html));
    }

    /**
     * Celah yang dimatikan ikut hilang dari hitungan, bukan cuma diabaikan
     * tampilan.
     */
    #[Test]
    public function turning_the_gap_off_tightens_the_grid_on_print(): void
    {
        $this->useStickerSheet('1.5x1.5', gapMm: 0.0);

        $html = $this->printJobs(4);

        $this->assertStringContainsString(
            '100 x 150 mm · 6 kolom x 10 baris = 60 label per halaman',
            $html,
        );
        $this->assertStringContainsString('--gap:0mm;--cols:6;--rows:10', $html);
    }

    #[Test]
    public function rack_labels_in_sheet_mode_still_use_the_sheet(): void
    {
        $this->useStickerSheet();

        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $html = $this->actingAs(User::factory()->owner()->create())
            ->post(route('master.lokasi-rak.print-labels'), [
                'rack_ids' => [$rack->id],
                'template' => '1.5x1.5',
                'copies' => 2,
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('size: 100mm 150mm', $html);
        $this->assertSame(1, $this->sheetCountIn($html));
    }

    /**
     * Label rak memakai ukuran yang dipilih di form-nya, bukan ukuran bawaan.
     *
     * Kalau kertas yang aktif lebih kecil dari label rak yang dipilih, grid harus
     * dinolkan -- sama seperti label barang.
     */
    #[Test]
    public function a_rack_label_that_does_not_fit_the_paper_falls_back_to_roll(): void
    {
        $this->useStickerSheet('4x3', widthMm: 30.0, heightMm: 30.0);

        $rack = Rack::factory()->create(['code' => 'A-01-04']);

        $html = $this->actingAs(User::factory()->owner()->create())
            ->post(route('master.lokasi-rak.print-labels'), [
                'rack_ids' => [$rack->id],
                'template' => '4x3',
                'copies' => 1,
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('size: 4cm 3cm', $html);
        $this->assertSame(0, $this->sheetCountIn($html));
    }

    /**
     * Batas 600 label per dokumen tetap berlaku di mode stiker.
     *
     * Batasnya melindungi memori render, jadi memindahkannya ke "600 per lembar"
     * akan mengubah artinya jadi 12.000 label untuk 250 lembar.
     */
    #[Test]
    public function the_document_label_cap_still_applies_in_sheet_mode(): void
    {
        $this->useStickerSheet();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$this->job(601)->id]])
            ->assertStatus(500);
    }

    /**
     * Aturan cetak yang membuat 48 label jadi satu halaman, bukan 48 halaman.
     *
     * Feature test lain hanya memeriksa HTML yang dikembalikan. Semuanya akan
     * tetap hijau kalau rulesheet ini dihapus -- dan gejalanya di dunia nyata
     * adalah printer mengeluarkan 48 kertas 15 x 15 mm untuk satu lembar stiker.
     * Uji cascade tidak bisa menangkapnya, jadi aturan yang jadi kontrak
     * cetak dicek langsung di stylesheet.
     */
    #[Test]
    public function the_print_stylesheet_keeps_one_sheet_per_page(): void
    {
        $css = $this->labelCss();
        $print = $this->printMediaBlock($css);

        // Aturan di luar @media print dan aturan di dalamnya sengaja dipisah.
        // Keduanya menyebut selector yang sama, jadi kalau diperiksa bersama-sama,
        // assertion bisa terpenuhi oleh aturan yang salah tempat -- dan
        // `gap: var(--gap)` di @media print akan meniru assertion `gap` yang
        // sebenarnya dimaksud untuk ruleset layar.
        $screen = str_replace($print, '', $css);

        // Satu lembar stiker = satu halaman.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*break-after:\s*page/',
            $print,
            'Lembar stiker harus dipecah per halaman.',
        );

        // Lembar terakhir tidak boleh menyisakan halaman kosong di belakang.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid:last-child\s*\{[^}]*break-after:\s*auto/',
            $print,
            'Lembar terakhir tidak boleh menambah halaman kosong.',
        );

        // Pemecahan per label harus khusus mode gulungan. Kalau selector-nya
        // polos, 48 label jadi 48 halaman.
        $this->assertDoesNotMatchRegularExpression(
            '/\.label-sheet--grid\s*>\s*\.label\s*\{[^}]*break-after:\s*page/',
            $print,
            'Label di dalam lembar stiker tidak boleh dipecah satu per halaman.',
        );
        $this->assertMatchesRegularExpression(
            '/\.label-sheet:not\(\.label-sheet--grid\)\s*>\s*\.label\s*\{[^}]*break-after:\s*page/',
            $print,
            'Pemecahan per label harus tetap ada, tapi hanya untuk mode gulungan.',
        );

        // Label tidak boleh terbelah dua antar halaman.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*>\s*\.label\s*\{[^}]*break-inside:\s*avoid/',
            $print,
            'Label stiker harus dijaga agar tidak terbelah antar halaman.',
        );

        // Grid harus tetap grid dan tetap memakai celah dari geometri. Aturan
        // mode gulungan menimpa `display` jadi block dan `gap` jadi 0, jadi
        // tanpa penimpaan balik, 48 label keluar rapat dan tidak membentuk grid.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*display:\s*grid/',
            $print,
            'Di @media print, .label-sheet menimpa display jadi block; grid harus menimpanya kembali.',
        );
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*gap:\s*var\(--gap\)/',
            $print,
            'Celah di @media print harus kembali ke 2 mm dari geometri.',
        );

        // Ukuran grid di layar harus datang dari custom property, bukan dari
        // angka yang ditulis ulang di CSS.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*grid-template-columns:\s*repeat\(var\(--cols[^;]*var\(--label-w/',
            $screen,
        );
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*grid-auto-rows:\s*var\(--label-h/',
            $screen,
        );

        // `align-content: start` dan tinggi lembar wajib. Tanpa yang pertama,
        // baris meregang sampai memenuhi tinggi kertas dan label 15 mm bergeser
        // ke sel berikutnya tanpa ada error. Tanpa yang kedua, kotak grid ikut
        // menyesuaikan diri dengan isinya: 48 label jadi kotak setinggi 134 mm
        // pada halaman 150 mm, jadi printer menerima lembar yang 16 mm lebih
        // pendek dan baris terakhir terpotong.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*align-content:\s*start/',
            $screen,
        );
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*min-height:\s*var\(--sheet-h/',
            $screen,
        );

        // Celah 2 mm itu yang membuat 6 x 15 + 5 x 2 = 100 mm persis. Kalau
        // celah hilang, kolom melar dan label meleset dari kolom blueprintnya.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*gap:\s*var\(--gap/',
            $screen,
        );

        // Lebar lembar mengikuti media: 800 dot pada 203 dpi, bukan selebar
        // label. Kalau hilang, grid memakai lebar isinya dan `@page` tidak
        // lagi sama dengan kotak yang diprinter.
        $this->assertMatchesRegularExpression(
            '/\.label-sheet--grid\s*\{[^}]*width:\s*var\(--sheet-w/',
            $screen,
        );
    }

    /**
     * Isi `resources/css/label.css` dengan komentar dibuang.
     *
     * Tanpa ini assertion bisa terpenuhi oleh penjelasan di dalam komentar --
     * dan komentar sering kali menyebut aturan yang SUDAH dihapus, persis yang
     * perlu dicek.
     */
    private function labelCss(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents(base_path('resources/css/label.css')),
        );
    }

    /**
     * Isi `@media print`, brace-nya dihitung.
     *
     * Regex non-greedy sampai `}` pertama akan berhenti di blok pertama,
     * karena `@media print` berisi blok aturan yang bersarang.
     */
    private function printMediaBlock(string $css): string
    {
        $start = strpos($css, '@media print');
        $this->assertNotFalse($start, 'Stylesheet label harus punya blok @media print.');

        $depth = 0;
        $length = strlen($css);

        for ($i = $start; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        $this->fail('Blok @media print tidak tertutup.');
    }

    /**
     * Bar kendali harus utuh sebagai satu elemen yang bisa ditutup.
     *
     * Dua panel fixed terpisah pernah dipakai di sini: tombol di kanan atas,
     * peringatan di kiri bawah, keduanya lebar sampai 12 cm. Di tablet dan HP
     * keduanya bertumpuk, dan yang menutupi tombol bukan cuma informasinya --
     * tapi tepat saat operator paling butuh menekan cetak.
     *
     * Yang dijaga di sini bentuknya, bukan warnanya: `position: fixed` inline
     * tidak boleh muncul lagi di halaman ini. Kalau tombolnya suatu saat sengaja
     * dibesarkan supaya lebih enak diklik, tes inilah yang gagal lebih dulu.
     */
    #[Test]
    public function the_controls_live_in_one_closable_bar(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(1);

        $this->assertStringContainsString('class="no-print label-toolbar"', $html);
        $this->assertStringContainsString('label-toolbar__actions', $html);
        $this->assertStringContainsString('<details class="label-toolbar__info">', $html);

        // Ringkasan di `<summary>` supaya panel tetap informatif saat ditutup.
        $this->assertStringContainsString('48 label per halaman', $html);

        $this->assertStringNotContainsString(
            'position:fixed',
            $html,
            'Posisi panel tidak boleh inline: aturan display dan tablet.GONE harus di CSS.',
        );
    }

    /**
     * Info yang hilang dari panel harus tetap tertulis.
     *
     * Panel sekarang bisa ditutup, jadi isinya yang menentukan apakah operator
     * masih bisa tahu langkah demi langkah. Yang dijaga di sini adalah isinya,
     * bukan posisi panelnya.
     */
    #[Test]
    public function the_control_bar_still_carries_every_instruction(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(1);

        foreach ([
            'Cetak 1 label',
            'Kembali',
            'Ukuran label aktif',
            'Sisa bawah 16 mm',
            'lebar printer 800 dot',
            'Margins: None',
            'Scale: 100%',
        ] as $text) {
            $this->assertStringContainsString($text, $html, "Panel harus menyebut '{$text}'.");
        }
    }

    /**
     * Peringatan uji cetak ikut di panel, bukan hanya di kertas.
     *
     * Kalau operator memakai stiker label barang sungguhan, label yang sama
     * persis akan keluar lalu lot ikut berubah tanpa ada job label. Itu
     * kerusakan stok, jadi peringatannya harus tampil di layar juga.
     */
    #[Test]
    public function the_test_print_warning_stays_on_screen(): void
    {
        $this->useStickerSheet();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '1.5x1.5']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Uji cetak.', $html);
        $this->assertStringContainsString('Jangan ditempel di rak', $html);
    }

    /**
     * Komentar Blade tidak boleh pernah bocor ke halaman.
     *
     * Satu kurung kurawal hilang di pembuka komentar panel kendali -- `{--`
     * alih-alih `{{--` -- dan karena itu kompilator Blade tidak pernah
     * menghapusnya. Isinya terkirim apa adanya ke browser, lengkap dengan
     * `<style>` di dalamnya, sehingga browser membaca sisanya sebagai CSS.
     * Yang dilihat operator setengah paragraf teks lalu layar "hilang",
     * padahal tombol cetaknya ada di balik CSS yang tertelan.
     *
     * Kenapa tidak terdeteksi: tidak ada satu pun test yang memeriksa
     * ketiadaan komentar. Itu lubangnya, jadi sekarang lubangnya ditutup dua
     * cara -- penanda komentar apa pun, dan isi komentar yang memang ada di
     * berkas sumber halaman ini.
     */
    #[Test]
    public function no_blade_comment_leaks_into_the_page(): void
    {
        $this->useStickerSheet();

        $html = $this->printJobs(1);

        foreach (['{--', '--}}'] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                "Penanda komentar Blade '{$marker}' bocor ke halaman.",
            );
        }

        $this->assertStringNotContainsString(
            'Panel kendali: satu elemen fixed',
            $html,
            'Isi komentar panel kendali ikut terkirim sebagai teks biasa.',
        );
    }
}
