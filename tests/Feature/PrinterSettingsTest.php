<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelPaperMode;
use App\Enums\PrintMethod;
use App\Http\Requests\Settings\SavePrinterSettingsRequest;
use App\Models\AuditLog;
use App\Models\Rack;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\SheetGridCalculator;
use App\Services\Label\StickerSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pengaturan ukuran label dan sisi QR oleh Owner.
 *
 * Dua hal yang dijaga di sini. Pertama, simpanannya benar-benar terpakai:
 * template yang dipilih Owner harus muncul di label yang berikutnya dicetak,
 * bukan cuma tersimpan di tabel `settings`. Kedua, permintaan yang tidak masuk
 * akal ditolak sebelum sampai ke printer -- QR yang lebih besar dari label
 * menghasilkan stiker dengan QR terpotong, dan itu baru ketahuan setelah lotnya
 * dicetak.
 */
class PrinterSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $data)
    {
        /**
         * Mode kertas dan blueprint stiker ditambahkan setelah test-test ini
         * ditulis, dan keduanya jadi wajib di form. Default-nya diisi di sini
         * supaya test yang memang tidak sedang menguji mode tidak ikut gagal
         * hanya karena kolomnya kosong.
         *
         * Mengubah 20-an pemanggilan test untuk menyebut `roll` tidak menambah
         * cakupan pengujian sama sekali -- itu hanya membuat setiap baris jadi
         * lebih panjang tanpa memeriksa hal baru.
         *
         * `+` bukan `array_merge`: nilai yang sudah ada di `$data` harus menang,
         * jadi test yang sedang menguji mode tetap bisa menimpanya.
         */
        $data += [
            'paper_mode' => LabelPaperMode::Roll->value,
            'sticker_sheet' => null,
            /**
             * Ukuran kertas dan celah juga ditambahkan setelah test-test ini
             * ditulis, dan keduanya wajib di mode stiker. Angka defaults-nya
             * sama dengan blueprint BP-TD110BT yang dipakai test lain, jadi
             * test yang memang sedang menguji preset lama tidak ikut gagal
             * hanya karena kolom barunya belum diisi.
             */
            'sheet_media_width_mm' => '100',
            'sheet_media_height_mm' => '150',
            'sheet_has_gap' => '1',
            'sheet_gap_mm' => '2',
            'max_print_width_mm' => '108',
        ];

        return $this->put(route('setting.perangkat.label.update'), $data);
    }

    #[Test]
    public function an_owner_can_save_the_default_template_and_qr_size(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '4x3',
                'qr_side_cm' => '1.45',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(
            LabelTemplate::FourByThree,
            $printer->defaultTemplate(),
            'Template yang disimpan harus jadi bacaan setelan, bukan cuma angka di database.',
        );
        $this->assertSame(1.45, $printer->qrSideCm(), 'Sisi QR harus ikut tersimpan.');
    }

    /**
     * Bawaan sistem harus tetap berlaku kalau Owner belum menyimpan apa pun.
     */
    #[Test]
    public function an_unsaved_setting_falls_back_to_the_system_default(): void
    {
        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(LabelTemplate::ThreeByTwo, $printer->defaultTemplate());
        $this->assertNull($printer->qrSideCm(), 'Tanpa setelan, QR mengikuti bawaan geometry.');
        $this->assertSame(PrintMethod::Browser, $printer->printMethod());
    }

    #[Test]
    public function an_owner_can_save_the_print_method(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '3x2', 'print_method' => PrintMethod::Thermal->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(
            PrintMethod::Thermal,
            $printer->printMethod(),
            'Cara cetak yang disimpan harus jadi bacaan setelan, bukan cuma angka di database.',
        );
    }

    /**
     * Form lama tidak mengirim kolom `print_method`.
     *
     * Instalasi yang sudah memilih `thermal` tidak boleh dikembalikan ke
     * `browser` hanya karena Owner menyimpan ukuran kertas dari form versi
     * lama -- persis aturan `max_print_width_mm`.
     */
    #[Test]
    public function a_save_without_print_method_keeps_the_stored_choice(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '3x2', 'print_method' => PrintMethod::Thermal->value])
            ->assertSessionHasNoErrors();

        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->save(['default_template' => '3x2'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PrintMethod::Thermal, app(LabelPrinterSettings::class)->printMethod());
    }

    #[Test]
    public function an_unknown_print_method_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '3x2', 'print_method' => 'bluetooth'])
            ->assertSessionHasErrors('print_method');
    }

    #[Test]
    public function the_audit_trail_covers_the_print_method(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->save(['default_template' => '3x2'])->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->save(['default_template' => '3x2', 'print_method' => PrintMethod::Thermal->value])
            ->assertSessionHasNoErrors();

        $second = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(PrintMethod::Browser->value, $second->before['print_method']);
        $this->assertSame(PrintMethod::Thermal->value, $second->after['print_method']);
    }

    /**
     * `Setting` tidak punya primary key, jadi identitasnya masuk ke `entity_key`
     * dan `entity_id` dibiarkan kosong.
     *
     * Ini yang membuat halaman ini 500 di MySQL sebelum diperbaiki: kuncinya
     * pernah dikirim ke `entity_id` yang `unsignedBigInteger`. Test ini akan
     * hijau di SQLite kalau bentuk salahnya masih lolos, jadi `tests/Mysql` yang
     * benar-benar menahan -- lihat `PrinterSettingsOnMysqlTest`.
     */
    #[Test]
    public function the_audit_trail_identifies_the_setting_by_its_key(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')->sole();

        $this->assertSame('Setting', $log->entity);
        $this->assertSame('label.printer', $log->entity_key);
        $this->assertNull($log->entity_id, 'Entitas tanpa primary key tidak boleh mengisi entity_id.');
        $this->assertSame('label.printer', $log->identity());
    }

    /**
     * Jejak audit harus bisa dibaca sebagai diff: field yang sama di kedua sisi.
     *
     * Bentuk lama menaruh `from`/`to` bersarang di dalam `after` dan mengisi
     * `before` dengan nilai baru. Laporan audit menampilkan keduanya berdampingan,
     * jadi bentuk rata membuat diff-nya terlihat tanpa logika per halaman.
     */
    #[Test]
    public function the_audit_trail_can_be_read_as_a_diff(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')->sole();

        // Urutan field sengaja tidak diuji: MySQL menyusun ulang kunci objek pada
        // tipe `json`-nya, jadi urutan tidak bisa dijadikan klaim lintas mesin.
        // Yang dijaga adalah field-nya sama, supaya bisa berdampingan.
        $this->assertEqualsCanonicalizing(
            [
                'default_template',
                'qr_side_cm',
                'paper_mode',
                'print_method',
                'sticker_sheet',
                'sheet_media_width_mm',
                'sheet_media_height_mm',
                'sheet_has_gap',
                'sheet_gap_mm',
                'max_print_width_mm',
            ],
            array_keys($log->after),
        );
        $this->assertEqualsCanonicalizing(
            array_keys($log->after),
            array_keys($log->before),
            'before dan after harus punya field yang sama supaya berdampingan.',
        );

        $this->assertSame(LabelTemplate::ThreeByTwo->value, $log->before['default_template']);
        $this->assertSame(LabelTemplate::FourByThree->value, $log->after['default_template']);
        $this->assertNull($log->before['qr_side_cm']);
        $this->assertSame(1.45, $log->after['qr_side_cm']);
    }

    /**
     * Jejak audit harus menyimpan nilai yang benar-benar berlaku, bukan yang
     * baru saja dikirimkan form.
     *
     * Simpanan berikutnya membaca nilai sekarang untuk jadi snapshot, jadi
     * satu baris yang salah-pressed akan merusak semua jejak setelahnya --
     * persis saat dibutuhkan untuk membongkar label yang salah cetak.
     */
    #[Test]
    public function the_audit_trail_records_the_settings_that_were_in_effect(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->save(['default_template' => '1.5x1.5', 'qr_side_cm' => null])
            ->assertSessionHasNoErrors();

        $second = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(
            LabelTemplate::FourByThree->value,
            $second->before['default_template'],
            'Nilai lama di baris kedua harus yang benar-benar dipakai, bukan bawaan sistem.',
        );
        $this->assertSame(1.45, $second->before['qr_side_cm']);
        $this->assertNull($second->after['qr_side_cm'], 'Mengosongkan kolom berarti kembali ke bawaan.');
    }

    /**
     * Simpanan dan jejaknya harus jadi satu kesatuan.
     *
     * Tanpa transaksi, `Setting` ter-commit sebelum baris audit ditulis. Kalau
     * auditnya gagal, Owner melihat 500 lalu menekan simpan lagi -- padahal
     * pengaturannya sudah tersimpan di server. Yang diuji di sini
     * justru akibatnya: kegagalan menulis jejak tidak boleh meninggalkan
     * setengah perubahan di database.
     */
    #[Test]
    public function a_failing_audit_trail_rolls_the_setting_back(): void
    {
        $owner = User::factory()->owner()->create();

        $this->partialMock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('Simulasi kegagalan menulis jejak audit.'));

        $this->actingAs($owner)
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45']);

        $this->assertNull(
            Setting::get(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY),
            'Setelan tidak boleh tersisa kalau jejaknya gagal ditulis.',
        );
        $this->assertNull(Setting::get(LabelPrinterSettings::QR_SIDE_KEY));
        $this->assertSame(0, AuditLog::count());
    }

    #[Test]
    public function staff_cannot_save_printer_settings(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->save(['default_template' => '4x3'])
            ->assertForbidden();

        $this->assertSame(
            LabelTemplate::ThreeByTwo,
            app(LabelPrinterSettings::class)->defaultTemplate(),
            'Penolakan tidak boleh diam-diam mengubah setelan.',
        );
    }

    /**
     * Staff tetap boleh melihat ukuran yang aktif: dia perlu tahu kertas apa
     * yang diroll, cuma tidak boleh mengubahnya.
     */
    #[Test]
    public function staff_can_read_the_current_size_but_get_no_save_form(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '4x3', 'qr_side_cm' => null])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('4 x 3 cm', $html, 'Staff harus melihat ukuran yang aktif.');
        $this->assertStringNotContainsString(
            'Simpan Pengaturan Label',
            $html,
            'Staff tidak boleh diberi form yang pasti ditolak.',
        );
        $this->assertStringNotContainsString(
            'name="default_template"',
            $html,
            'Field yang tidak bisa disimpan jangan ikut dikirim ke layar Staff.',
        );
    }

    /**
     * Teks banner di blade pecah jadi beberapa baris supaya jelas dibaca di
     * sumber, jadi perbandingan dibuat setelah whitespace diratakan.
     */
    private function assertQrOnlyWarning(string $html, string $message = ''): void
    {
        $flat = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString(
            'QR, SKU, dan harga',
            $flat,
            $message,
        );
        $this->assertStringContainsString(
            'tanpa nama produk dan kondisi',
            $flat,
            $message,
        );
    }

    #[Test]
    public function the_owner_is_warned_before_saving_a_qr_only_template(): void
    {
        // Ukuran 1,5 x 1,5 cm tidak punya ruang untuk nama produk dan kondisi
        // -- hanya QR, SKU, dan harga. Kalau Owner tidak diberi
        // tahu, dia akan menyimpan setting yang lolos validasi tapi menghasilkan
        // label yang isinya lebih sedikit dari yang dia harapkan.
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        $this->assertQrOnlyWarning($html, 'Owner harus diberi tahu sebelum memilih ukuran QR-saja.');

        // Banner itu digerakkan state Alpine, jadi yang dijaga di sini adalah
        // syaratnya -- bukan hanya teks banner yang kebetulan sudah terbaca.
        $this->assertStringContainsString('x-model="template"', $html);
        // `@js` menulis literal dengan tanda kutip tunggal, jadi itu yang
        // ditulis di sini, bukan `json_encode`.
        $this->assertStringContainsString(
            "x-show=\"template === '".LabelTemplate::QrOnly->value."'\"",
            $html,
        );
    }

    /**
     * Ringkasan grid harus benar-benar sekarang ikut angka yang diketik, bukan
     * hanya angka tersimpan.
     *
     * Ini dijaga di sini karena mudah kelewat: ringkasan yang terkunci ke angka
     * tersimpan masih terlihat benar saat form baru dibuka, dan baru salah
     * setelah Owner mengubah angkanya -- yaitu setelah semua test render lama
     * hijau. Yang diperiksa adalah syarat gerakannya: form harus memanggil
     * endpoint preview, dan endpoint itu harus punya nama route yang sama.
     */
    #[Test]
    public function the_grid_summary_follows_the_numbers_being_typed(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        // `@js()` menulis literal JavaScript, jadi garis miringnya di-escape:
        // `/settings/...` keluar sebagai `\/settings\/...`.
        $this->assertStringContainsString(
            str_replace('/', '\\/', route('setting.perangkat.label.preview')),
            $html,
            'Ringkasan grid harus dihitung dari angka yang sedang diketik.',
        );

        foreach ([
            'x-model="mediaWidth"',
            'x-model="mediaHeight"',
            'x-model="hasGap"',
            'x-model="gap"',
            'x-model="maxPrintWidth"',
        ] as $binding) {
            $this->assertStringContainsString($binding, $html);
        }

        // Angka yang dihitung server berarti perubahan harus triggering
        // permintaan baru; tanpa itu, ringkasan hanya bergerak saat halaman
        // dimuat ulang.
        $this->assertStringContainsString('this.$watch(\'mediaWidth\'', $html);
        $this->assertStringContainsString('this.queuePreview()', $html);
        $this->assertStringContainsString('previewErrorList()', $html);
    }

    #[Test]
    public function staff_sees_the_qr_only_warning_for_the_active_setting(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '1.5x1.5'])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        // Staff tidak punya form, jadi tidak mungkin salah memilih -- tapi tetap
        // perlu tahu label aktifnya cuma QR sebelum dia menyuruh orang scan.
        $this->assertQrOnlyWarning($html, 'Staff harus tahu ukuran aktifnya hanya QR, SKU, dan harga.');
    }

    #[Test]
    public function no_qr_only_warning_is_shown_for_a_label_that_carries_text(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '3x2'])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        // Peringatan yang salah lebih buruk daripada tidak ada: untuk ukuran
        // yang masih membawa teks, banner ini hanya menambah tinggi layar.
        $this->assertStringNotContainsString(
            'tanpa nama produk dan kondisi',
            preg_replace('/\s+/', ' ', $html),
        );
    }

    #[Test]
    public function an_unknown_template_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '5x7',
                'qr_side_cm' => '1.20',
            ])
            ->assertSessionHasErrors('default_template');

        $this->assertSame(LabelTemplate::ThreeByTwo, app(LabelPrinterSettings::class)->defaultTemplate());
    }

    #[Test]
    public function a_missing_template_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['qr_side_cm' => '1.20'])
            ->assertSessionHasErrors('default_template');
    }

    /**
     * QR 1,90 cm di label 1,5 cm tidak bisa muat. Angka ini lolos batas rentang
     * global, jadi hanya perbandingan dengan geometry yang bisa menahannya.
     */
    #[Test]
    public function a_qr_larger_than_the_chosen_label_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'qr_side_cm' => '1.90',
            ])
            ->assertSessionHasErrors('qr_side_cm');

        $this->assertNull(app(LabelPrinterSettings::class)->qrSideCm());
    }

    #[Test]
    public function a_qr_below_the_scannable_floor_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '4x3',
                'qr_side_cm' => '0.40',
            ])
            ->assertSessionHasErrors('qr_side_cm');
    }

    /**
     * Batas bawah 0,60 cm memang belum ideal untuk pemindai lambat, tapi itu
     * angka yang sudah disepakati. Yang ditolak di sini hanya yang di bawah
     * batas, jadi rentang yang tertulis di form dan yang ditegakkan pengatur sama.
     */
    #[Test]
    public function the_qr_floor_is_exactly_where_the_form_says_it_is(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '4x3',
                'qr_side_cm' => '0.60',
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Mengosongkan kolom harus benar-benar mengembalikan perilaku ke bawaan
     * geometry. Ini yang paling mudah salah: kalau barisnya ditulis `null` saja,
     * pemformattersan tetap menampilkan kolom kosong sementara printer tetap
     * memakai angka lama.
     */
    #[Test]
    public function clearing_the_qr_size_restores_the_geometry_default(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->save(['default_template' => '4x3', 'qr_side_cm' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull(app(LabelPrinterSettings::class)->qrSideCm());
    }

    /**
     * Template yang disimpan harus benar-benar dipakai saat label dibuat, bukan
     * hanya tampil di form pengaturan.
     */
    #[Test]
    public function the_saved_template_reaches_the_next_label_print(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '1.5x1.5', 'qr_side_cm' => null])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('label--qr-only', $html);
    }

    /**
     * Sisi QR dari Owner harus sampai ke ukuran QR di HTML, karena angka itu
     * yang menentukan jumlah modul di printer.
     *
     * Angkanya 1,45 cm, bukan 1,80 cm seperti sebelumnya. 1,80 cm masih muat
     * di label 4x3 secara fisik -- tapi kolom teksnya jadi 1,68 cm dan SKU
     * 15 karakter tidak muat lagi, jadi label tercetak dengan identitas unit
     * yang salah. Penjaga di `LabelPrinterSettings` menolak sejak awal.
     */
    #[Test]
    public function the_saved_qr_size_reaches_the_printed_label(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '4x3']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="label__qr" style="width:1\.45cm;height:1\.45cm"/',
            $html,
            'QR 1,45 cm harus sampai ke style label.',
        );
    }

    /**
     * QR yang muat di label tapi merusak teksnya harus ditolak.
     *
     * Dua-duanya ini dulu lolos. Pemeriksaan yang ada hanya memastikan QR tidak
     * melewati tepi stiker, jadi Owner bisa menyimpan angka yang membuat harga
     * tercetak `Rp100.0...` -- angka yang salah, bukan sekadar kurang terbaca --
     * tanpa satu pun peringatan.
     *
     * Yang diperiksa setiap baris, bukan cuma SKU: pada 3x2 harga yang lebih
     * dulu rusak, dan kalau hanya SKU yang diawasi label tetap tercetak salah.
     */
    #[Test]
    #[DataProvider('qrSizesThatBreakTheText')]
    public function a_qr_size_that_breaks_the_text_is_rejected(string $template, string $qrSideCm): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => $template, 'qr_side_cm' => $qrSideCm])
            ->assertSessionHasErrors('qr_side_cm');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function qrSizesThatBreakTheText(): array
    {
        return [
            // 3x2: harga rusak sejak 1,10 cm, SKU masih muat sampai 1,30 cm.
            'harga rusak duluan di 3x2' => ['3x2', '1.50'],
            'SKU terpotong di 3x2' => ['3x2', '1.70'],
            // 4x3: kode rak terpotong dulu, sebelum harga.
            'kode rak terpotong di 4x3' => ['4x3', '1.60'],
            'SKU terpotong di 4x3' => ['4x3', '1.90'],
        ];
    }

    /**
     * Sisi QR tepat di batas yang masih aman harus diterima.
     *
     * Penjaga yang terlalu ketat sama saja dengan tidak ada penjaga: Owner lalu
     * menyimpan QR sekecil mungkin supaya aman, dan modulnya jadi sulit discan.
     * Batasnya harus mengizinkan angka yang memang masih terbaca.
     */
    #[Test]
    #[DataProvider('qrSizesThatAreStillReadable')]
    public function a_qr_size_that_keeps_every_row_readable_is_accepted(string $template, string $qrSideCm): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => $template, 'qr_side_cm' => $qrSideCm])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function qrSizesThatAreStillReadable(): array
    {
        return [
            'bawaan 3x2' => ['3x2', '1.05'],
            'batas atas 3x2' => ['3x2', '1.09'],
            'bawaan 4x3' => ['4x3', '1.45'],
            'batas atas 4x3, dibatasi kode rak' => ['4x3', '1.48'],
        ];
    }

    /**
     * Label rak 3x2 sengaja tidak punya QR. Pengaturan umum tidak boleh
     * membatalkannya, karena kode rak jadi terpotong di setiap label rak yang
     * dicetak setelah Owner mengubah setelan.
     */
    #[Test]
    public function the_global_qr_setting_never_adds_a_qr_to_a_rack_label_without_one(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '4x3', 'qr_side_cm' => '1.45'])
            ->assertSessionHasNoErrors();

        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('master.lokasi-rak.print-labels'), [
                'rack_ids' => [$rack->id],
                'template' => '3x2',
                'copies' => 1,
            ])
            ->assertSee('RK:A-01-03', escape: false)
            ->assertDontSee('label__qr', escape: false);
    }

    /**
     * Setelan yang tidak bisa dibaca harus tetap menghasilkan label, bukan
     * membuat halaman error. Tabel `settings` bisa berisi apa saja, termasuk
     * angka yang diubah manual di luar aplikasi.
     */
    #[Test]
    public function an_unreadable_stored_value_falls_back_instead_of_throwing(): void
    {
        Setting::set('label.default_template', 'ukuran-ngawur');
        Setting::set('label.qr_side_cm', 'bukan-angka');

        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(LabelTemplate::ThreeByTwo, $printer->defaultTemplate());
        $this->assertNull($printer->qrSideCm());
    }

    /**
     * Uji cetak harus memakai ukuran yang sedang aktif, karena itulah yang
     * diukur Owner tepat setelah mengubah setelan.
     */
    #[Test]
    public function the_test_print_page_follows_the_saved_template(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '1.5x1.5', 'qr_side_cm' => null])
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertSee('label--qr-only', escape: false);
    }

    /**
     * Ukuran kertas harus benar-benar tersimpan dan ikut ke halaman cetak --
     * bukan cuma tampil sebagai angka di form.
     */
    #[Test]
    public function an_owner_can_save_the_sticker_sheet_mode(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sticker_sheet' => StickerSheet::BpTd110BtA6->value,
            ])
            ->assertSessionHasNoErrors();

        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(LabelPaperMode::Sheet, $printer->paperMode());
        $this->assertSame(StickerSheet::BpTd110BtA6, $printer->stickerSheet());

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertSee('size: 100mm 150mm', escape: false);
    }

    /**
     * Label yang lebih besar dari "kolom blueprint" sekarang justru boleh disimpan.
     *
     * Dulu kertas 100 x 150 mm selalu dipecah jadi 6 kolom 15 mm, jadi label
     * 3 x 2 cm ditolak di layar pengaturan walau muat dengan wajar di atas
     * kertas itu sendiri: 3 kolom x 6 baris = 18 label. Sekarang ukuran label
     * datang dari preset dan kertas tidak lagi dibagi rata, jadi kombinasi yang
     * dulu ditolak justru hasil yang benar.
     *
     * Test ini dikunci karena penolakan lamanya dihapus: kalau suatu saat
     * penolakan berbasis "kolom" muncul lagi, yang kembali bukan hanya pesannya.
     */
    #[Test]
    public function a_label_that_fits_the_paper_is_accepted_even_when_it_exceeded_the_old_column_width(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '3x2',
                'paper_mode' => LabelPaperMode::Sheet->value,
            ])
            ->assertSessionHasNoErrors();

        $grid = app(LabelPrinterSettings::class)->sheetGrid(LabelTemplate::ThreeByTwo);

        $this->assertNotNull($grid);
        $this->assertSame(3, $grid->columns);
        $this->assertSame(6, $grid->rows);
        $this->assertSame(18, $grid->labelsPerSheet());

        // Dan angka itu yang benar-benar dipakai printer.
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertSee('size: 100mm 150mm', escape: false);
    }

    /**
     * Label yang lebih besar dari kertasnya sendiri tetap ditolak, di kolom
     * kertas -- bukan di kolom label, karena Owner tidak bisa mengetik ukuran
     * label.
     */
    #[Test]
    public function a_label_wider_than_the_paper_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '4x3',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sheet_media_width_mm' => '30',
                'sheet_media_height_mm' => '20',
            ])
            ->assertSessionHasErrors('sheet_media_width_mm')
            ->assertSessionHasErrors('sheet_media_height_mm');

        $this->assertNull(
            Setting::get(LabelPrinterSettings::PAPER_MODE_KEY),
            'Kombinasi yang ditolak tidak boleh meninggalkan mode stiker aktif.',
        );
    }

    /**
     * Mode stiker tanpa ukuran kertas tidak bisa dicetak: tidak ada yang tahu
     * kertas dan gridnya. Menjamakan nilainya dengan angka bawaan akan
     * menyembunyikan bahwa Owner sebenarnya belum memilih kertas yang benar.
     *
     * Dulu yang wajib di sini adalah blueprint. Sekarang blueprint sudah
     * digantikan ukuran kertas dan celah, jadi yang wajib adalah kedua angka itu
     * -- langkah yang sama, syarat yang lebih dekat dengan yang diketik Owner.
     */
    #[Test]
    public function the_sheet_mode_requires_the_paper_size(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sheet_media_width_mm' => null,
                'sheet_media_height_mm' => null,
            ])
            ->assertSessionHasErrors(['sheet_media_width_mm', 'sheet_media_height_mm']);
    }

    #[Test]
    public function an_unknown_paper_mode_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save(['default_template' => '1.5x1.5', 'paper_mode' => 'kertas-hantu'])
            ->assertSessionHasErrors('paper_mode');
    }

    #[Test]
    public function an_unknown_sticker_sheet_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sheet_media_width_mm' => '100',
                'sheet_media_height_mm' => '150',
                'sheet_has_gap' => '1',
                'sheet_gap_mm' => '2',
                'sticker_sheet' => 'blueprint-ngawur',
            ])
            ->assertSessionHasErrors('sticker_sheet');
    }

    /**
     * Kembali ke mode gulungan harus menghapus preset, bukan menyimpannya diam-diam.
     *
     * Baris yang tidak berlaku lebih membingungkan daripada tidak ada: pembacaan
     * audit "blueprint apa yang dipakai waktu label ini dicetak?" akan menemukan
     * preset yang ternyata tidak pernah dipakai.
     */
    #[Test]
    public function switching_back_to_roll_forgets_the_sticker_sheet(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sticker_sheet' => StickerSheet::BpTd110BtA6->value,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Roll->value,
                'sticker_sheet' => StickerSheet::BpTd110BtA6->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::get(LabelPrinterSettings::STICKER_SHEET_KEY));
        $this->assertNull(app(LabelPrinterSettings::class)->stickerSheet());
    }

    /**
     * Label besar tetap boleh disimpan saat mode gulungan, walau tidak muat di
     * kertas stiker.
     *
     * Ukuran kertas hanya dipakai di mode stiker, jadi menolaknya akan mencegah
     * Owner menyimpan mode gulungan dengan label besar -- kombinasi yang
     * sebenarnya valid dan justru yang paling umum.
     */
    #[Test]
    public function the_roll_mode_accepts_a_label_the_paper_cannot_hold(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '3x2',
                'paper_mode' => LabelPaperMode::Roll->value,
                'sticker_sheet' => StickerSheet::BpTd110BtA6->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(LabelPaperMode::Roll, app(LabelPrinterSettings::class)->paperMode());
    }

    #[Test]
    public function the_audit_trail_records_the_paper_mode_change(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sticker_sheet' => StickerSheet::BpTd110BtA6->value,
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')->latest('id')->firstOrFail();

        $this->assertSame(LabelPaperMode::Roll->value, $log->before['paper_mode']);
        $this->assertNull($log->before['sticker_sheet']);
        $this->assertSame(LabelPaperMode::Sheet->value, $log->after['paper_mode']);
        $this->assertSame(StickerSheet::BpTd110BtA6->value, $log->after['sticker_sheet']);
    }

    /**
     * Staff boleh membaca mode dan ukuran kertas yang aktif, tapi tidak boleh
     * mengubahnya.
     *
     * Yang tampil adalah ukuran kertas dan celah yang benar-benar berlaku,
     * termasuk yang Owner ketik sendiri. Installasi lama yang masih menyimpan
     * blueprint harus tetap terbaca: angkanya diturunkan dari preset itu, jadi
     * Staff tidak melihat "Stiker 100 x 150 mm" yang hilang begitu saja saat
     * form Owner mengubah cara memorinya.
     */
    #[Test]
    public function staff_can_read_the_active_paper_mode_but_get_no_save_form(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Sheet->value);
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, StickerSheet::BpTd110BtA6->value);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->assertSee('100 x 150 mm, celah 2 mm')
            ->assertDontSee('name="paper_mode"', escape: false);
    }

    /**
     * Ukuran yang Owner ketik sendiri, bukan yang diturunkan dari preset, yang
     * tampil ke Staff.
     *
     * Ini bentuk yang akan dibaca setiap orang yang memecah kertas stiker, jadi
     * angkanya harus berasal dari sumber yang sama dengan halaman cetak. Kalau
     * preset lama ikut diprioritaskan di sini, Staff akan memecah kertas
     * berdasarkan ukuran yang sudah tidak berlaku.
     */
    #[Test]
    public function staff_sees_the_paper_size_the_owner_typed(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sheet_media_width_mm' => '120',
                'sheet_media_height_mm' => '180',
                'sheet_has_gap' => '0',
                'sheet_gap_mm' => '2',
                'max_print_width_mm' => '216',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->assertSee('120 x 180 mm, tanpa celah');
    }

    /**
     * Mode gulungan tidak boleh menampilkan ukuran kertas.
     *
     * Di mode gulungan tidak ada grid, jadi angka kertas yang tampil di bawah
     * "Gulungan" cuma memberi Staff sesuatu yang tidak berlaku.
     */
    #[Test]
    public function the_paper_size_is_hidden_while_roll_mode_is_active(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Roll->value,
                'sheet_media_width_mm' => '120',
                'sheet_media_height_mm' => '180',
            ])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('120 x 180 mm', $html);
    }

    /**
     * Kolom kertas dan celah harus benar-benar ada di form Owner, lengkap dengan
     * batas angkanya.
     *
     * Atribut `min`/`max` bukan hiasan: mereka satu-satunya petunjuk yang ada
     * sebelum Owner menekan Simpan.
     */
    #[Test]
    public function the_owner_form_has_the_paper_and_gap_inputs_with_their_limits(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        foreach ([
            'sheet_media_width_mm',
            'sheet_media_height_mm',
            'sheet_gap_mm',
            'max_print_width_mm',
            'sheet_has_gap',
        ] as $name) {
            $this->assertStringContainsString(
                'name="'.$name.'"',
                $html,
                "Kolom {$name} harus ada di form.",
            );
        }

        $this->assertStringContainsString(
            'min="'.SheetGridCalculator::MIN_GAP_MM.'"',
            $html,
            'Batas bawah celah harus ikut di kolomnya.',
        );
        $this->assertStringContainsString(
            'max="'.SavePrinterSettingsRequest::MAX_MEDIA_MM.'"',
            $html,
            'Batas atas ukuran kertas harus ikut di kolomnya.',
        );

        // Blueprint lama sudah tidak jadi masukan, jadi tidak boleh lagi ada di
        // form. Kalau select-nya masih ada, Owner bisa mengisinya dan mengira
        // itu yang menentukan grid.
        $this->assertStringNotContainsString('name="sticker_sheet"', $html);
    }

    /**
     * Angka pecahan di kolom form harus ditulis dengan titik, bukan koma.
     *
     * Angka yang dibaca manusia di halaman ini memang memakai koma, tapi kolomnya
     * `type="number"`: browser hanya menerima titik dan mengirim apa adanya yang
     * tertulis. Koma yang lolos ke server ditolak aturan `numeric`, jadi Owner
     * akan melihat "Celah harus berupa angka" untuk angka yang dia sendiri
     * ketik -- dan angka 2,5 mm tidak akan bisa disimpan sama sekali.
     */
    #[Test]
    public function fractional_inputs_are_filled_in_with_a_dot(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->save([
                'default_template' => '1.5x1.5',
                'paper_mode' => LabelPaperMode::Sheet->value,
                'sheet_media_width_mm' => '100',
                'sheet_media_height_mm' => '150',
                'sheet_has_gap' => '1',
                'sheet_gap_mm' => '2.5',
                'max_print_width_mm' => '108',
            ])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="2.5"', $html);
        $this->assertStringNotContainsString('value="2,5"', $html);
    }

    /**
     * Nilai yang rusak di database tidak boleh membuat halaman pengaturan gagal.
     */
    #[Test]
    public function an_unreadable_paper_mode_falls_back_to_roll(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, 'kertas-hantu');
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, 'blueprint-hantu');

        $printer = app(LabelPrinterSettings::class);

        $this->assertSame(LabelPaperMode::Roll, $printer->paperMode());
        $this->assertNull($printer->stickerSheet());
    }
}
