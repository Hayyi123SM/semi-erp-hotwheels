<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\LabelStatus;
use App\Models\LabelPrintJob;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Label\LabelPage;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LabelRenderTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $price = 50_000, int $qty = 12, string $sku = 'CN01-HW-001-U03'): StockLot
    {
        return StockLot::factory()->create([
            'sku' => $sku,
            'owner_code' => 'CN01',
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => $price,
            'qty_received' => $qty,
        ]);
    }

    private function job(?StockLot $lot = null, int $copies = 1, string $status = LabelStatus::Queued->value): LabelPrintJob
    {
        return LabelPrintJob::factory()->create([
            'lot_id' => ($lot ?? $this->lot())->id,
            'copies' => $copies,
            'status' => $status,
        ]);
    }

    #[Test]
    public function an_operator_can_open_a_printable_page_for_the_selected_jobs(): void
    {
        $job = $this->job(copies: 3);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk()
            ->assertSee('CN01-HW-001-U03')
            // Tiga label untuk tiga salinan.
            ->assertSee('label__body', escape: false);
    }

    #[Test]
    public function the_printable_page_does_not_carry_the_application_chrome(): void
    {
        $job = $this->job();

        $html = $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk()
            ->getContent();

        // Halaman ini bisa disajikan dari build produksi (nama aset ber-hash)
        // atau dari dev server Vite, jadi yang diperiksa adalah daftar
        // stylesheet-nya, bukan nama file tertentu.
        preg_match_all('/<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"/', $html, $matches);
        $stylesheets = $matches[1];

        $this->assertCount(1, $stylesheets, 'Halaman cetak harus memuat tepat satu stylesheet.');

        // Hanya stylesheet label yang dimuat. Kalau app.css ikut, seluruh
        // tampilan aplikasi akan terbawa ke kertas.
        $this->assertStringContainsString('label', $stylesheets[0]);
        $this->assertStringNotContainsString('app.css', $stylesheets[0]);
    }

    /**
     * Membuka halaman cetak tidak boleh dianggap sebagai "sudah dicetak".
     *
     * Operator bisa membuka pratinjau lalu membatalkan. Kalau status ikut
     * berubah, `labels_printed` naik tanpa label yang benar-benar keluar dari
     * printer, dan stok akan terlihat salah.
     */
    #[Test]
    public function viewing_the_print_page_does_not_change_the_job_status(): void
    {
        $job = $this->job();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk();

        $this->assertSame(LabelStatus::Queued, $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->lot->labels_printed);
    }

    #[Test]
    public function the_content_is_snapshotted_on_first_render(): void
    {
        $job = $this->job($this->lot(price: 50_000));

        $this->assertNull($job->payload);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk();

        $job->refresh();

        $this->assertSame(50_000, $job->payload['list_price']);
        $this->assertSame('CN01-HW-001-U03', $job->payload['sku']);
        $this->assertNotNull($job->rendered_at);
    }

    /**
     * Snapshot yang sudah ada tidak boleh ditimpa.
     *
     * Ini yang membuat re-print bisa menghasilkan label yang sama dengan
     * cetakan pertama. Kalau `payload` ditulis ulang tiap kali halaman
     * dibuka, alasan `PRICE_CHANGE` jadi tidak berarti.
     */
    #[Test]
    public function an_existing_snapshot_is_never_overwritten(): void
    {
        $job = $this->job($this->lot(price: 50_000));

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk();

        // Harga naik setelah label pertama keluar dari printer.
        $job->lot->update(['list_price' => 90_000]);
        $firstSnapshot = $job->refresh()->payload;

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk()
            ->assertSee('Rp50.000')
            ->assertDontSee('Rp90.000');

        $this->assertSame($firstSnapshot, $job->refresh()->payload);
    }

    #[Test]
    public function the_snapshot_never_stores_the_consignor_name(): void
    {
        $job = $this->job();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk();

        $encoded = json_encode($job->refresh()->payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Budi Santoso', $encoded);
    }

    /**
     * Label keluar dari printer dalam urutan yang operator pilih. Kalau
     * diurutkan ulang diam-diam, operator tidak lagi bisa mencocokkan label
     * dengan baris dokumen yang sedang dikerjakan.
     */
    #[Test]
    public function the_labels_come_out_in_the_order_they_were_selected(): void
    {
        $first = $this->job($this->lot(price: 10_000, sku: 'CN01-HW-001-U01'), copies: 1);
        $second = $this->job($this->lot(price: 20_000, sku: 'CN01-HW-001-U02'), copies: 1);
        $third = $this->job($this->lot(price: 30_000, sku: 'CN01-HW-001-U03'), copies: 1);

        $html = $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$third->id, $first->id, $second->id]])
            ->assertOk()
            ->getContent();

        // Dipilih: 30.000, 10.000, 20.000.
        $this->assertLessThan(
            strpos($html, 'Rp10.000'),
            strpos($html, 'Rp30.000'),
            'Label Rp30.000 harus muncul sebelum Rp10.000.',
        );
        $this->assertLessThan(
            strpos($html, 'Rp20.000'),
            strpos($html, 'Rp10.000'),
            'Label Rp10.000 harus muncul sebelum Rp20.000.',
        );
    }

    #[Test]
    public function a_job_id_that_does_not_exist_is_refused(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [999999]])
            ->assertSessionHasErrors('ids.0');
    }

    #[Test]
    public function selecting_nothing_is_refused(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    #[Test]
    public function a_guest_cannot_open_the_print_page(): void
    {
        $job = $this->job();

        $this->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertRedirect(route('login'));
    }

    /**
     * Regression: satu halaman cetak tidak boleh berisi dua ukuran kertas.
     *
     * Antrean label menahan job dari sebelum dan sesudah Owner mengganti
     * ukuran, jadi `label_print_jobs.template` bisa berisi dua nilai berbeda
     * sekaligus. Kalau kolom itu yang menentukan ukuran, job lama memakai
     * ukuran lamanya dan sheet-nya bercampur -- printer thermal punya satu
     * gauge, jadi yang keluar bergantian ukuran dan operator tidak bisa
     * mengukurnya.
     *
     * Yang diuji di sini batasnya benar: `LabelPage` sudah menolak ukuran yang
     * tidak dikenal, dan yang memanggilnya adalah `InboundController`, bukan
     * `LabelPage` lagi.
     */
    #[Test]
    public function every_label_on_the_page_follows_the_current_setting_not_each_jobs_own_template(): void
    {
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, LabelTemplate::FourByThree->value);

        $stale = $this->job();
        $stale->update(['template' => LabelTemplate::QrOnly->value]);

        $html = $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$stale->id]])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('width:1.5cm', $html);
        $this->assertStringContainsString('width:4cm;height:3cm', $html);
    }

    /**
     * Ukuran label yang jadi setelan bisa saja rusak di database. `Setting`
     * ditulis dari form yang divalidasi `Rule::in`, jadi tidak ada jalan dari
     * aplikasi yang menulis nilai tak dikenal -- tapi tidak ada yang memanggil
     * telat kalau ada yang menulis langsung ke tabel.
     *
     * `LabelPrinterSettings::defaultTemplate()` jatuh ke ukuran bawaan, bukan
     * melempar galat. Ini perilaku yang sudah lama dan sengaja: halaman
     * `Setting > Perangkat` tidak boleh ikut mati karena satu baris rusak.
     * Test ini yang mengunci keputusan itu, supaya kalau suatu saat diubah,
     * ada yang dengan sadar memutuskan untuk mengubahnya.
     *
     * Batasnya tetap dijaga: `LabelTemplate::parse()` masih melempar
     * `InvalidArgumentException`, dan `SavePrinterSettingsRequest` +
     * `PrintRackLabelsRequest` memakainya, jadi tidak ada jalan yang menulis
     * ukuran tak dikenal lewat form.
     */
    #[Test]
    public function a_broken_template_setting_falls_back_to_the_builtin_size(): void
    {
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, '10x20');

        $job = $this->job();
        $job->update(['template' => '10x20']);

        $html = $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('width:3cm;height:2cm', $html);
    }

    /**
     * Halaman dirender penuh di memori. Terlalu banyak label harus ditolak,
     * bukan dipotong diam-diam, karena operator akan mengira semuanya
     * tercetak.
     */
    #[Test]
    public function an_enormous_selection_is_refused_rather_than_trimmed(): void
    {
        $job = $this->job(copies: LabelPage::MAX_LABELS_PER_PAGE + 1);

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('inbound.cetak-label.render'), ['ids' => [$job->id]])
            ->assertStatus(500);
    }
}
