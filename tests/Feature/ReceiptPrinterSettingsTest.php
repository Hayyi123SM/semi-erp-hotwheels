<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaperSize;
use App\Enums\PrintMethod;
use App\Models\AuditLog;
use App\Models\Consignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Consignment\ReceiptPrinterSettings;
use App\Services\Consignment\ReceiptSheet;
use App\Services\Print\PrintSettings;
use App\Services\Print\Thermal\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ukuran kertas dan cara cetak oleh Owner.
 *
 * Yang dijaga di sini bukan cuma "nilainya tersimpan", tapi dua hal yang
 * membuat pengaturan ini bisa merusak bukti di tangan penitip:
 *
 * 1. Nilai yang sudah disimpan harus benar-benar dipakai. Setting yang cuma
 *    ada di tabel `settings` tidak berguna: halaman cetak tetap keluar 80 mm
 *    dan tidak ada yang tahu, karena Owner sudah yakin sudah mengubahnya.
 * 2. Nilai yang tidak dikenal harus ditolak di layar. Kertas 62 mm tidak ada
 *    di daftar, dan diam-diam jatuh ke bawaan lebih buruk daripada ditolak:
 *    Owner menekan Simpan, melihat pesan sukses, lalu mencetak dengan kertas
 *    yang salah.
 *
 * Kertas di sini adalah pengaturan GLOBAL (`PrintSettings`), bukan kertas milik
 * bukti terima -- struk POS ikut memakainya. `ReceiptPrinterSettings::paper()`
 * tinggal jalan pintas ke sana.
 */
class ReceiptPrinterSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('paperCases')]
    public function an_owner_can_save_the_global_paper(PaperSize $paper): void
    {
        $this->save(['paper' => $paper->value, 'method' => 'browser'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $paper,
            app(PrintSettings::class)->paper(),
            'Kertas yang disimpan harus jadi bacaan setelan, bukan cuma nilai di database.',
        );
    }

    /**
     * @return array<string, array{0: PaperSize}>
     */
    public static function paperCases(): array
    {
        return [
            'struk 58' => [PaperSize::Mm58],
            'struk 80' => [PaperSize::Mm80],
            'A4' => [PaperSize::A4],
        ];
    }

    #[Test]
    #[DataProvider('methodCases')]
    public function an_owner_can_save_the_print_method(PrintMethod $method): void
    {
        $this->save(['paper' => '80mm', 'method' => $method->value])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $method,
            app(PrintSettings::class)->method(),
        );
    }

    /**
     * @return array<string, array{0: PrintMethod}>
     */
    public static function methodCases(): array
    {
        return [
            'browser' => [PrintMethod::Browser],
            'thermal' => [PrintMethod::Thermal],
        ];
    }

    #[Test]
    public function it_falls_back_to_browser_and_80mm_until_something_is_saved(): void
    {
        $settings = app(PrintSettings::class);

        // Default cara cetak adalah browser: browser adalah satu-satunya cara
        // yang pernah dipakai aplikasi, jadi instalasi lama tidak ikut berubah.
        $this->assertSame(PrintMethod::Browser, $settings->method());
        $this->assertSame(PaperSize::Mm80, $settings->paper());

        // Belum ada yang tersimpan harus bisa dibedakan dari "sudah memilih".
        // Kalau tidak, form menampilkan pilihan bawaan seolah sudah beres.
        $this->assertNull($settings->storedMethod());
        $this->assertNull($settings->storedPaper());
    }

    #[Test]
    public function the_form_distinguishes_a_chosen_paper_from_an_untouched_one(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->assertSee('Belum disimpan', escape: false);

        $this->save(['paper' => 'a4', 'method' => 'browser']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->assertSee('A4');
    }

    #[Test]
    #[DataProvider('rejectedPapers')]
    public function an_unknown_paper_is_rejected_instead_of_falling_back_silently(?string $submitted): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from(route('setting.perangkat'))
            ->put(route('setting.perangkat.struk.update'), ['paper' => $submitted, 'method' => 'thermal'])
            ->assertRedirect(route('setting.perangkat'))
            ->assertSessionHasErrors('paper');

        $this->assertDatabaseMissing('settings', ['key' => PrintSettings::PAPER_KEY]);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function rejectedPapers(): array
    {
        return [
            'kosong' => [''],
            'ukuran yang tidak pernah ada' => ['62mm'],
            'nilai enum, bukan nilai simpanannya' => ['A4'],
        ];
    }

    #[Test]
    public function an_unknown_method_is_rejected_instead_of_quietly_sending_thermal_bytes(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->from(route('setting.perangkat'))
            ->put(route('setting.perangkat.struk.update'), ['paper' => '80mm', 'method' => 'mesin-lama'])
            ->assertRedirect(route('setting.perangkat'))
            ->assertSessionHasErrors('method');

        $this->assertDatabaseMissing('settings', ['key' => PrintSettings::METHOD_KEY]);
    }

    #[Test]
    public function a4_with_thermal_is_stored_but_the_renderer_still_refuses_it(): void
    {
        // Owner boleh menyimpan kombinasi ini: menolaknya akan membuat Owner
        // mengira pengaturan rusak. Halaman cetak yang tahu A4 tidak bisa
        // thermal yang menurunkan cara cetaknya, dan renderer juga menolak.
        $this->save(['paper' => 'a4', 'method' => 'thermal']);

        $this->assertSame(PaperSize::A4, app(PrintSettings::class)->paper());

        $this->expectException(InvalidArgumentException::class);
        ReceiptRenderer::render(new ReceiptSheet(
            Consignment::factory()->completed()->create(),
            PaperSize::A4,
            User::factory()->staff()->create(),
            autoPrint: false,
        ));
    }

    #[Test]
    public function a_stored_value_from_an_older_version_does_not_break_the_page(): void
    {
        // Ditulis langsung ke database, bukan lewat form: baris `settings` bisa
        // berisi apa saja. Halaman cetak tidak boleh gagal dimuat karena itu.
        Setting::set(PrintSettings::PAPER_KEY, 'roll-thermal');
        Setting::set(PrintSettings::METHOD_KEY, 'kirim-saja');

        $this->assertSame(PaperSize::Mm80, app(PrintSettings::class)->paper());
        $this->assertSame(PrintMethod::Browser, app(PrintSettings::class)->method());
    }

    #[Test]
    public function legacy_papers_are_read_until_the_global_one_is_saved(): void
    {
        // Instalasi lama menyimpan kertas di `receipt.paper` dan
        // `pos.receipt_paper`. Keduanya tetap dipakai sebagai fallback.
        Setting::set(PrintSettings::LEGACY_RECEIPT_PAPER_KEY, '58mm');

        $this->assertSame(PaperSize::Mm58, app(ReceiptPrinterSettings::class)->paper());

        Setting::set(PrintSettings::PAPER_KEY, 'a4');

        $this->assertSame(PaperSize::A4, app(ReceiptPrinterSettings::class)->paper());
    }

    #[Test]
    public function staff_may_look_but_not_change_the_settings(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->put(route('setting.perangkat.struk.update'), ['paper' => 'a4', 'method' => 'thermal'])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => PrintSettings::PAPER_KEY]);
        $this->assertDatabaseMissing('settings', ['key' => PrintSettings::METHOD_KEY]);
    }

    #[Test]
    public function saving_is_audited_with_the_previous_values_attached(): void
    {
        $owner = User::factory()->owner()->create();

        $this->save(['paper' => '58mm', 'method' => 'browser'], $owner);
        $this->save(['paper' => 'a4', 'method' => 'thermal'], $owner);

        $log = AuditLog::query()
            ->where('action', 'UPDATE_RECEIPT_PRINTER_SETTINGS')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('print', $log->entity_key);
        $this->assertSame(['paper' => '58mm', 'method' => 'browser'], $log->before);
        $this->assertSame(
            ['paper' => 'a4', 'method' => 'thermal', 'post_commit_mode' => 'auto_print'],
            $log->after,
        );
        $this->assertSame($owner->id, $log->user_id);
    }

    /**
     * Pelakunya boleh diberi, karena `AuditLogger` mencatat `auth()->id()`.
     * Tanpa itu, test audit akan membandingkan ID Owner dari simpanan pertama
     * dengan ID Owner dari simpanan kedua -- yang bedanya hanya soal urutan
     * pembuatan, bukan soal isi lognya.
     *
     * @param  array<string, string>  $payload
     */
    private function save(array $payload, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? User::factory()->owner()->create())
            ->put(route('setting.perangkat.struk.update'), $payload)
            ->assertSessionHasNoErrors();
    }
}
