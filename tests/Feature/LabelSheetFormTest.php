<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelPaperMode;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\StickerSheet;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validasi kolom kertas stiker di form Perangkat.
 *
 * Yang paling dijaga di sini adalah penempatan pesan. Form ini punya lima input
 * untuk satu grid, dan penolakan matematis bisa disebabkan input mana saja.
 * Kalau semua pesan ditumpuk di satu tempat, Owner harus menebak sendiri kolom
 * mana yang salah -- dan form yang bisa dinilai buruk karena Owner menyimpan
 * angka yang salah dengan cara yang tidak bisa dilacak.
 *
 * Karena itu setiap test di sini memeriksa dua hal sekaligus: pesannya ada,
 * dan pesannya menempel pada kolom yang benar.
 */
class LabelSheetFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua test di sini menyimpan sebagai Owner.
     *
     * Route pengaturan printer memakai middleware owner. Tanpa login, setiap
     * permintaan dialihkan ke halaman masuk dan tidak ada validasi yang jalan --
     * test-nya akan hijau karena "tidak ada error", padahal tidak ada yang
     * diuji sama sekali.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * Data form yang sudah lengkap, supaya setiap test cuma mengubah satu hal.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'default_template' => LabelTemplate::QrOnly->value,
            'qr_side_cm' => null,
            'paper_mode' => LabelPaperMode::Sheet->value,
            'sheet_media_width_mm' => '100',
            'sheet_media_height_mm' => '150',
            'sheet_has_gap' => '1',
            'sheet_gap_mm' => '2',
            'max_print_width_mm' => '108',
        ];
    }

    private function submit(array $overrides = [])
    {
        return $this->put(route('setting.perangkat.label.update'), $this->form($overrides));
    }

    #[Test]
    public function the_verified_sheet_is_accepted(): void
    {
        $this->submit()
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /**
     * Kertas yang lebih lebar dari area cetak printer ditolak di kolom lebar.
     *
     * Pesan ini yang paling penting penempatannya: kertas 120 mm tetap bisa
     * muat di grid 6 kolom, jadi tanpa pesan di kolom lebar Owner akan melihat
     * "semua angka masuk akal" lalu menemukan stiker kolom paling kanan terpotong
     * setelah dicetak.
     */
    #[Test]
    public function paper_wider_than_the_print_area_is_rejected_on_the_width_field(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '120',
            'max_print_width_mm' => '108',
        ])
            ->assertSessionHasErrors('sheet_media_width_mm')
            ->assertSessionDoesntHaveErrors(['sheet_media_height_mm', 'sheet_gap_mm']);
    }

    #[Test]
    public function a_label_too_wide_for_the_paper_is_rejected_on_the_width_field(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '10',
        ])
            ->assertSessionHasErrors('sheet_media_width_mm');
    }

    #[Test]
    public function a_paper_too_short_for_the_label_is_rejected_on_the_height_field(): void
    {
        $this->submit([
            'sheet_media_height_mm' => '10',
        ])
            ->assertSessionHasErrors('sheet_media_height_mm')
            ->assertSessionDoesntHaveErrors('sheet_media_width_mm');
    }

    /**
     * Celah di luar rentang ditolak hanya di kolom celah.
     *
     * Kolom kertas tidak boleh ikut error: Owner yang salah mengetik celah tetap
     * boleh menyimpan ukuran kertasnya yang sudah benar, lalu memperbaiki celah
     * saja di percobaan berikutnya.
     */
    #[Test]
    public function a_gap_outside_the_range_is_rejected_on_the_gap_field_only(): void
    {
        $this->submit(['sheet_gap_mm' => '9'])
            ->assertSessionHasErrors('sheet_gap_mm')
            ->assertSessionDoesntHaveErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    /**
     * Pesan batas celah menyebut angkanya, bukan "tipe data salah".
     */
    #[Test]
    public function the_gap_range_message_states_the_limit(): void
    {
        $this->submit(['sheet_gap_mm' => '9'])
            ->assertSessionHasErrors([
                'sheet_gap_mm' => 'Celah maksimal 5 mm. Celah sebesar itu hampir selalu berarti ada angka lain yang keliru.',
            ]);
    }

    /**
     * Lebar dan tinggi kertas kosong ditolak terpisah, bukan jadi satu pesan.
     *
     * Satu pesan gabungan membuat Owner tidak tahu kolom mana yang salah.
     */
    #[Test]
    public function empty_paper_sizes_are_rejected_on_their_own_fields(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '',
            'sheet_media_height_mm' => '',
        ])
            ->assertSessionHasErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    #[Test]
    public function non_numeric_paper_sizes_are_rejected(): void
    {
        $this->submit([
            'sheet_media_width_mm' => 'seratus',
            'sheet_media_height_mm' => 'putih',
        ])
            ->assertSessionHasErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    #[Test]
    public function the_paper_size_must_be_inside_a_readable_range(): void
    {
        $this->submit(['sheet_media_width_mm' => '5000'])
            ->assertSessionHasErrors('sheet_media_width_mm');

        $this->submit(['sheet_media_height_mm' => '0'])
            ->assertSessionHasErrors('sheet_media_height_mm');
    }

    #[Test]
    public function the_gap_range_is_enforced_on_its_own_field(): void
    {
        $this->submit(['sheet_gap_mm' => '0.05'])
            ->assertSessionHasErrors('sheet_gap_mm');

        $this->submit(['sheet_gap_mm' => '9'])
            ->assertSessionHasErrors('sheet_gap_mm');
    }

    /**
     * Celah menyala tapi kosong ditolak dengan pesan yang menyebut pilihannya.
     *
     * Dua jalan keluar yang sah: isi angkanya, atau matikan centangnya. Pesan
     * yang hanya menyebut "wajib diisi" membuat Owner mengira form-nya rusak.
     */
    #[Test]
    public function an_empty_gap_with_the_checkbox_on_is_rejected(): void
    {
        $this->submit(['sheet_gap_mm' => ''])
            ->assertSessionHasErrors('sheet_gap_mm');

        $errors = session('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString(
            'Isi celahnya, atau matikan centang celah.',
            implode(' ', $errors->get('sheet_gap_mm')),
        );
    }

    /**
     * Celah kosong tidak masalah kalau centangnya mati.
     *
     * Kolom yang tidak dipakai tidak boleh menghalangi penyimpanan -- kalau
     * iya, Owner harus mengetik angka yang memang tidak akan dipakai.
     */
    #[Test]
    public function an_empty_gap_with_the_checkbox_off_is_accepted(): void
    {
        $this->submit([
            'sheet_has_gap' => '0',
            'sheet_gap_mm' => '',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /**
     * Angka pada kolom yang tidak dipakai juga tidak menghalangi.
     *
     * Browser kadang mengirim nilai lama dari kolom yang sudah dinonaktifkan.
     * Menolaknya hanya akan membuat Owner mengulang yang tidak pernah dipakai.
     */
    #[Test]
    public function a_garbage_gap_with_the_checkbox_off_is_accepted(): void
    {
        $this->submit([
            'sheet_has_gap' => '0',
            'sheet_gap_mm' => 'bukan angka',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    #[Test]
    public function paper_sizes_are_required_in_sheet_mode(): void
    {
        $this->submit()
            ->assertSessionHasNoErrors();

        $this->put(route('setting.perangkat.label.update'), [
            'default_template' => LabelTemplate::QrOnly->value,
            'paper_mode' => LabelPaperMode::Sheet->value,
        ])->assertSessionHasErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    /**
     * Di mode gulungan kolom kertas tidak dicek sama sekali.
     *
     * Tidak ada grid yang perlu dihitung, jadi mewajibkan angka stiker hanya
     * akan mencegah Owner menyimpan mode gulungan karena kolom yang tidak
     * pernah dia pakai masih kosong.
     */
    #[Test]
    public function sheet_columns_are_ignored_in_roll_mode(): void
    {
        $this->put(route('setting.perangkat.label.update'), [
            'default_template' => LabelTemplate::QrOnly->value,
            'paper_mode' => LabelPaperMode::Roll->value,
            'sheet_media_width_mm' => 'abc',
            'sheet_media_height_mm' => '-1',
            'sheet_gap_mm' => 'seratus',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    #[Test]
    public function the_print_limit_is_validated_on_its_own_field(): void
    {
        $this->submit(['max_print_width_mm' => 'abc'])
            ->assertSessionHasErrors('max_print_width_mm');

        $this->submit(['max_print_width_mm' => '0'])
            ->assertSessionHasErrors('max_print_width_mm');
    }

    /**
     * Setiap preset label diperiksa terhadap kertas yang sama.
     */
    #[Test]
    #[DataProvider('templatePresets')]
    public function every_label_preset_is_checked_against_the_same_paper(string $template): void
    {
        // 4x3 cm (40 x 30 mm) tidak muat di kertas 30 mm yang juga dipakai test
        // lain, jadi test ini memakai kertas besar untuk ketiga preset.
        $this->submit([
            'default_template' => $template,
            'sheet_media_width_mm' => '210',
            'sheet_media_height_mm' => '297',
            'max_print_width_mm' => '216',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templatePresets(): array
    {
        return [
            '1,5 x 1,5 cm' => [LabelTemplate::QrOnly->value],
            '3 x 2 cm' => [LabelTemplate::ThreeByTwo->value],
            '4 x 3 cm' => [LabelTemplate::FourByThree->value],
        ];
    }

    /**
     * Masalah hubungan tetap tertangkap meski semua angkanya dalam rentang.
     *
     * 10 mm lolos aturan rentang untuk kertas, tapi label 15 x 15 mm tidak muat
     * di sana. Tidak ada aturan `min` atau `max` yang bisa menangkap ini --
     * hanya perbandingan antara kertas dan label.
     */
    #[Test]
    public function relational_problems_are_caught_even_when_every_number_is_in_range(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '10',
            'sheet_media_height_mm' => '10',
        ])->assertSessionHasErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    /**
     * Kertas yang muat tapi melebihi area cetak printer juga harus ditolak.
     *
     * 120 mm ada di dalam rentang yang valid, dan grid-nya sendiri bisa
     * dihitung. Yang menolak adalah batas printer -- masalah yang tidak punya
     * aturan rentang sama sekali.
     */
    #[Test]
    public function paper_beyond_the_print_area_is_rejected_although_the_numbers_are_in_range(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '120',
            'max_print_width_mm' => '108',
        ])->assertSessionHasErrors([
            'sheet_media_width_mm' => 'Kertas 120 mm lebih lebar dari area cetak printer 108 mm. Kolom paling kanan akan terpotong. Pilih kertas yang lebih sempit, atau perbarui batas cetak printer.',
        ]);
    }

    /**
     * Simpan yang lolos validasi harus benar-benar tersimpan dan jadi grid yang
     * sama dengan yang dilihat Owner.
     *
     * Ini yang menutup jalur dari form ke kalkulator. Tanpa test ini, request
     * bisa menerima semua angka, return dengan toast sukses, lalu tidak menulis
     * apa pun -- dan gejalanya baru muncul di depan printer sebagai label yang
     * tercetak satu per halaman.
     */
    #[Test]
    public function the_saved_numbers_produce_the_grid_the_form_previewed(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '100',
            'sheet_media_height_mm' => '150',
            'sheet_has_gap' => '1',
            'sheet_gap_mm' => '2',
        ])->assertSessionHasNoErrors();

        $this->assertSame(100.0, (float) Setting::get(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY));
        $this->assertSame(150.0, (float) Setting::get(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY));
        $this->assertSame(2.0, (float) Setting::get(LabelPrinterSettings::SHEET_GAP_MM_KEY));

        $grid = app(LabelPrinterSettings::class)->sheet()->gridFor(LabelTemplate::QrOnly);

        $this->assertSame(6, $grid->columns);
        $this->assertSame(8, $grid->rows);
        $this->assertSame(48, $grid->labelsPerSheet());
    }

    /**
     * Mematikan celah tidak boleh menghapus angkanya.
     *
     * Angka celah yang disimpan bukan "nilai yang dipakai" tapi "jarak yang
     * pernah dipilih", jadi checkbox-nya bisa dinyalakan lagi tanpa Owner harus
     * mengetik ulang. Yang menentukan grid adalah `effectiveGapMm()`, dan test
     * ini memastikan kedua angka itu memang terpisah: 2 mm tersimpan, 0 mm
     * yang dipakai.
     */
    #[Test]
    public function turning_the_gap_off_keeps_the_number_but_stops_using_it(): void
    {
        $this->submit(['sheet_has_gap' => '0', 'sheet_gap_mm' => '2'])
            ->assertSessionHasNoErrors();

        $sheet = app(LabelPrinterSettings::class)->sheet();

        $this->assertFalse($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm, 'Angka celah yang diketik harus tetap tersimpan.');
        $this->assertSame(0.0, $sheet->effectiveGapMm());
        $this->assertSame(60, $sheet->gridFor(LabelTemplate::QrOnly)->labelsPerSheet());
    }

    /**
     * Kembali ke mode gulungan menghapus ukuran kertas, tapi tidak batas cetak.
     *
     * Batas cetak ikut berlaku di mode gulungan: printer yang bisa mencetak
     * 216 mm tidak boleh diam-diam dikembalikan ke 108 mm karena Owner sedang
     * menyimpan mode gulungan.
     */
    #[Test]
    public function switching_to_roll_forgets_the_paper_but_keeps_the_print_limit(): void
    {
        $this->submit(['max_print_width_mm' => '216'])->assertSessionHasNoErrors();

        $this->submit(['paper_mode' => LabelPaperMode::Roll->value, 'max_print_width_mm' => '216'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::get(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY));
        $this->assertNull(Setting::get(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY));
        $this->assertNull(Setting::get(LabelPrinterSettings::SHEET_GAP_MM_KEY));
        $this->assertSame(216.0, (float) Setting::get(LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY));
    }

    /**
     * Blueprint lama dan angka baru tidak boleh jadi dua sumber yang sama.
     *
     * Keduanya menyebut ukuran kertas, jadi membiarkannya berdua membuat
     * pertanyaan "kertas seperti apa yang dipakai waktu label ini dicetak?"
     * punya dua jawaban. Angka baru menang di pembacaan, jadi yang lama boleh
     * dihapus -- tapi hanya setelah penggantinya benar-benar tersimpan.
     */
    #[Test]
    public function the_legacy_blueprint_key_is_dropped_once_the_numbers_are_saved(): void
    {
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, StickerSheet::BpTd110BtA6->value);

        $this->submit([
            'sheet_media_width_mm' => '120',
            'sheet_media_height_mm' => '180',
            'sheet_has_gap' => '0',
            'sheet_gap_mm' => '2',
            'max_print_width_mm' => '216',
        ])->assertSessionHasNoErrors();

        $this->assertNull(
            Setting::get(LabelPrinterSettings::STICKER_SHEET_KEY),
            'Blueprint lama harus dihapus setelah angka barunya tersimpan.',
        );

        $sheet = app(LabelPrinterSettings::class)->sheet();

        $this->assertSame(120.0, $sheet->mediaWidthMm, 'Angka baru harus menang atas blueprint lama.');
        $this->assertSame(180.0, $sheet->mediaHeightMm);
    }

    /**
     * Form yang tidak mengirim batas cetak tidak boleh mengubahnya.
     *
     * Kolom ini ditambahkan belakangan, jadi request lama -- tab yang sudah
     * terbuka saat Owner menyimpan pengaturan lain, integrasi lain yang masih
     * memakai endpoint ini -- tidak mengirimnya sama sekali. Memperlakukannya
     * sebagai "hapus" akan mengembalikan printer 216 mm ke 108 mm tanpa ada
     * yang menyentuhnya.
     */
    #[Test]
    public function a_form_without_the_print_limit_keeps_the_stored_one(): void
    {
        Setting::set(LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY, '216');

        $payload = $this->form();
        unset($payload['max_print_width_mm']);

        $this->put(route('setting.perangkat.label.update'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(216.0, (float) Setting::get(LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY));
    }

    /**
     * Jejak audit harus menyebut ukuran kertas yang berlaku.
     *
     * Ukuran kertas menentukan isi cetakan, jadi ketika ada label yang ternyata
     * salah cetak, setelan yang berlaku waktu itu sudah tidak ada lagi. Baris
     * audit adalah satu-satunya tempat yang masih bisa menjawabnya.
     */
    #[Test]
    public function the_audit_trail_records_the_paper_size(): void
    {
        $this->submit([
            'sheet_media_width_mm' => '100',
            'sheet_media_height_mm' => '150',
            'sheet_has_gap' => '1',
            'sheet_gap_mm' => '2',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'UPDATE_PRINTER_SETTINGS')
            ->latest('id')
            ->firstOrFail();

        /**
         * Angka lewat `cast` karena audit disimpan sebagai JSON: `100.0`
         * ditulis sebagai `100`, jadi yang kembali adalah integer. Tanpa cast,
         * test akan gagal pada bentuk JSON yang memang tidak memengaruhi
         * pembacaan audit -- yang hanya butuh angkanya, bukan tipe PHP-nya.
         */
        $this->assertSame(100.0, (float) $log->after['sheet_media_width_mm']);
        $this->assertSame(150.0, (float) $log->after['sheet_media_height_mm']);
        $this->assertSame(2.0, (float) $log->after['sheet_gap_mm']);
    }

    /**
     * Celah yang dicatat di audit adalah yang aktif, bukan yang tersimpan.
     *
     * Kalau celah dimatikan, angka 2 mm masih ada di `Setting` sebagai
     * pengingat, tapi yang dicetak jaraknya 0. Audit yang mencatat angka yang
     * tidak dipakai akan membuat "celah 2 mm" terlihat benar di catatan,
     * padahal cetakannya berjarak 0.
     */
    #[Test]
    public function the_audit_trail_records_the_gap_that_is_actually_used(): void
    {
        $this->submit(['sheet_has_gap' => '0', 'sheet_gap_mm' => '2'])
            ->assertSessionHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'UPDATE_PRINTER_SETTINGS')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(0.0, (float) $log->after['sheet_gap_mm']);
        $this->assertFalse($log->after['sheet_has_gap']);
    }

    /**
     * Komponen pratinjau harus utuh setelah diurai HTML.
     *
     * Isi `x-data` adalah JavaScript di dalam nilai atribut yang diapit kutip
     * ganda, jadi satu tanda kutip ganda pun di dalam badan komponen -- termasuk
     * di dalam komentar, misalnya `` `type="number"` `` -- membuat parser HTML
     * menutup atribut lebih awal. Efeknya bukan komponen mati: seluruh sisa
     * JavaScript bocor jadi teks yang bisa dibaca Owner di halaman, dan semua
     * test yang cuma mencari teks `loadPreview` di respons tetap hijau karena
     * teks itu memang ada di sana.
     *
     * Test ini потому mengurai respons seperti browser dan membaca atributnya,
     * supaya yang diperiksa bukan string-nya, tapi yang benar-benar diterima
     * browser.
     */
    #[Test]
    public function the_preview_component_attribute_survives_html_parsing(): void
    {
        $html = $this->get(route('setting.perangkat'))->assertOk()->getContent();

        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);
        $components = (new DOMXPath($document))->query('//*[@x-data[contains(., "loadPreview")]]');

        $this->assertSame(1, $components->length, 'Komponen pratinjau harus ada tepat sekali di halaman.');
        $this->assertNotNull($components->item(0), 'Elemen pratinjau tidak bisa dibaca.');

        $component = $components->item(0)->getAttribute('x-data');

        $this->assertStringContainsString('previewUrl:', $component);
        $this->assertStringContainsString('async loadPreview()', $component);
        $this->assertStringContainsString('previewErrorList()', $component);
        $this->assertStringContainsString('savedMediaWidth', $component);
    }
}
