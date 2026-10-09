<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Inventory\StockLotSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aturan cetak ulang (FR-IB-21).
 *
 * Dua hal yang dijaga di sini. Pertama, cetak ulang membuat job BARU: job lama
 * yang sudah CONFIRMED adalah bukti label pernah keluar dan angkanya sudah
 * dihitung, jadi menghidupkannya lagi akan menghitung label yang sama dua kali.
 *
 * Kedua, `payload` beku TIDAK ikut ke job baru. Kalau ikut, re-print karena
 * `PRICE_CHANGE` akan mencetak harga yang sama seperti cetakan pertama, dan
 * alasan `PRICE_CHANGE` tidak akan pernah berguna.
 */
class LabelReprintTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->owner()->create();
    }

    private function lot(array $attributes = [], string $sku = 'CN01-HW-001-U03'): StockLot
    {
        return StockLot::factory()->create(array_merge([
            'sku' => $sku,
            'owner_code' => 'CN01',
            'qty_received' => 12,
            'labels_printed' => 0,
            'reprint_count' => 0,
        ], $attributes));
    }

    private function reprint(array $lotIds, int $copies = 1, string $reason = LabelReason::LabelDamaged->value)
    {
        $this->actingAs($this->operator);

        return $this->post(route('inbound.cetak-label.reprint'), [
            'lot_ids' => $lotIds,
            'copies' => $copies,
            'reason' => $reason,
        ]);
    }

    // ------------------------------------------------------- job baru ---

    #[Test]
    public function reprint_creates_a_new_job_for_the_lot(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], copies: 2)->assertRedirect(route('inbound.cetak-label'));

        $job = LabelPrintJob::sole();
        $this->assertSame(LabelStatus::Queued, $job->status);
        $this->assertSame(2, $job->copies);
        $this->assertSame(LabelReason::LabelDamaged, $job->reason);
    }

    /**
     * Job lama yang sudah CONFIRMED tidak boleh dihidupkan kembali.
     */
    #[Test]
    public function reprint_leaves_the_confirmed_job_untouched(): void
    {
        $lot = $this->lot();
        $original = LabelPrintJob::factory()->create([
            'lot_id' => $lot->id,
            'status' => LabelStatus::Confirmed,
            'reason' => LabelReason::Initial,
        ]);
        $lot->forceFill(['labels_printed' => 1])->save();

        $this->reprint([$lot->id]);

        $original->refresh();
        $this->assertSame(LabelStatus::Confirmed, $original->status);
        $this->assertSame(LabelReason::Initial, $original->reason);
        // Job masih satu, dan yang kedua adalah job BARU.
        $this->assertCount(2, LabelPrintJob::all());
        $this->assertNotSame($original->id, LabelPrintJob::latest('id')->first()->id);
    }

    #[Test]
    public function reprint_does_not_count_the_label_before_it_is_confirmed(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->create([
            'lot_id' => $lot->id,
            'status' => LabelStatus::Confirmed,
        ]);
        $lot->forceFill(['labels_printed' => 1])->save();

        $this->reprint([$lot->id]);

        $this->assertSame(1, $lot->fresh()->labels_printed);
    }

    /**
     * Job baru tidak boleh mewarisi isi label lama, kalau tidak alasan
     * `PRICE_CHANGE` tidak pernah menghasilkan harga baru.
     */
    #[Test]
    public function reprint_does_not_inherit_the_frozen_payload(): void
    {
        $lot = $this->lot();
        $old = LabelPrintJob::factory()->create([
            'lot_id' => $lot->id,
            'status' => LabelStatus::Confirmed,
        ]);
        $old->forceFill([
            'payload' => ['sku' => $lot->sku, 'list_price' => 50_000],
        ])->save();

        $this->reprint([$lot->id], reason: LabelReason::PriceChange->value);

        $fresh = LabelPrintJob::whereKeyNot($old->id)->sole();
        $this->assertNull($fresh->payload);
    }

    // -------------------------------------------------------- alasan ---

    #[Test]
    public function a_reason_is_required(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], reason: '')->assertSessionHasErrors('reason');

        $this->assertCount(0, LabelPrintJob::all());
    }

    /**
     * `INITIAL` bukan alasan cetak ulang. Kalau boleh dipilih,
     * `reprint_count` naik untuk cetakan yang bukan cetakan ulang.
     */
    #[Test]
    public function the_initial_reason_cannot_be_used_for_a_reprint(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], reason: LabelReason::Initial->value)
            ->assertSessionHasErrors('reason');

        $this->assertCount(0, LabelPrintJob::all());
    }

    #[Test]
    public function an_unknown_reason_is_rejected(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], reason: 'SOAL')->assertSessionHasErrors('reason');
    }

    #[Test]
    #[DataProvider('reprintReasons')]
    public function every_specified_reprint_reason_is_accepted(string $reason): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], reason: $reason)->assertSessionHasNoErrors();

        $this->assertSame(LabelReason::from($reason), LabelPrintJob::sole()->reason);
    }

    public static function reprintReasons(): array
    {
        return [
            'label rusak' => [LabelReason::LabelDamaged->value],
            'label hilang' => [LabelReason::LabelLost->value],
            'cetak salah' => [LabelReason::Misprint->value],
            'unit tambahan' => [LabelReason::AdditionalUnits->value],
            'harga berubah' => [LabelReason::PriceChange->value],
        ];
    }

    // ------------------------------------------------------ reprint_count ---

    #[Test]
    public function reprint_counts_the_labels(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], copies: 3);

        $this->assertSame(3, $lot->fresh()->reprint_count);
    }

    #[Test]
    public function reprint_count_does_not_depend_on_the_label_being_confirmed(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], copies: 2);

        // Yang dihitung adalah berapa kali dicetak ulang, bukan berapa label
        // yang konfirmasi keluar. Menunggu konfirmasi akan menyembunyikan pola
        // cetakan berulang, yang justru hal yang dicari.
        $this->assertSame(2, $lot->fresh()->reprint_count);
        $this->assertSame(0, $lot->fresh()->labels_printed);
    }

    // ------------------------------------------------------------- audit ---

    #[Test]
    public function a_reprint_is_audited_with_its_reason(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], reason: LabelReason::LabelLost->value);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'REPRINT_LABELS',
            'entity' => 'LabelPrintJob',
            'reason' => LabelReason::LabelLost->value,
        ]);
    }

    // ------------------------------------------------------ banyak lot ---

    #[Test]
    public function several_lots_can_be_reprinted_at_once(): void
    {
        $first = $this->lot(sku: 'CN01-HW-001-U03');
        $second = $this->lot(sku: 'CN01-HW-002-U04');

        $this->reprint([$first->id, $second->id], copies: 2);

        $this->assertCount(2, LabelPrintJob::all());
        $this->assertSame(2, $first->fresh()->reprint_count);
        $this->assertSame(2, $second->fresh()->reprint_count);
    }

    #[Test]
    public function guests_cannot_reprint(): void
    {
        $lot = $this->lot();

        $this->post(route('inbound.cetak-label.reprint'), [
            'lot_ids' => [$lot->id],
            'copies' => 1,
            'reason' => LabelReason::LabelLost->value,
        ])->assertRedirect(route('login'));

        $this->assertCount(0, LabelPrintJob::all());
    }

    // ------------------------------------------------------ pencarian lot ---

    #[Test]
    public function search_finds_a_lot_by_sku(): void
    {
        $lot = $this->lot(sku: 'CN01-HW-777-U99');

        $this->app->make(StockLotSearch::class)->search('HW-777');

        $this->assertTrue(
            $this->app->make(StockLotSearch::class)->search('HW-777')->contains('id', $lot->id),
        );
    }

    #[Test]
    public function search_finds_a_lot_by_product_name(): void
    {
        $product = Product::factory()->create(['name' => 'Mazda RX-7 FD3S']);
        $lot = $this->lot(['product_id' => $product->id]);

        $this->assertTrue(
            $this->app->make(StockLotSearch::class)->search('RX-7')->contains('id', $lot->id),
        );
    }

    #[Test]
    public function search_finds_a_lot_by_consignor_name(): void
    {
        $consignor = Consignor::factory()->create(['name' => 'Budi Santoso']);
        $lot = $this->lot(['consignor_id' => $consignor->id]);

        $this->assertTrue(
            $this->app->make(StockLotSearch::class)->search('Budi')->contains('id', $lot->id),
        );
    }

    #[Test]
    public function search_finds_a_lot_by_consignment_number(): void
    {
        $consignment = Consignment::factory()->create(['doc_no' => 'CN-2026-0042']);
        $lot = $this->lot(['consignment_id' => $consignment->id]);

        $this->assertTrue(
            $this->app->make(StockLotSearch::class)->search('2026-0042')->contains('id', $lot->id),
        );
    }

    #[Test]
    public function search_ignores_a_single_character(): void
    {
        $this->lot(sku: 'CN01-HW-001-U03');

        // Satu huruf akan menarik sebagian besar tabel beserta relasinya. Halaman
        // ini dipakai sambil memegang barang, jadi jangan melakukan query itu.
        $this->assertCount(0, $this->app->make(StockLotSearch::class)->search('C'));
        $this->assertCount(0, $this->app->make(StockLotSearch::class)->search(''));
    }

    /**
     * `_` dan `%` harus dicocokkan sebagai karakter biasa.
     */
    #[Test]
    public function search_treats_like_wildcards_as_literal_characters(): void
    {
        $this->lot(sku: 'CN01-HW-001-U03');

        $search = $this->app->make(StockLotSearch::class);

        $this->assertCount(0, $search->search('___'), 'garis bawah adalah wildcard, bukan teks');
        $this->assertCount(0, $search->search('%%%'), 'persen adalah wildcard, bukan teks');
    }

    /**
     * Lot yang labelnya masih tertunda muncul lebih dulu: itu yang paling
     * mungkin sedang dicari operator.
     */
    #[Test]
    public function search_lists_lots_with_a_pending_label_first(): void
    {
        $quiet = $this->lot(sku: 'CN01-HW-001-U01');
        $pending = $this->lot(sku: 'CN01-HW-001-U02');
        LabelPrintJob::factory()->create([
            'lot_id' => $pending->id,
            'status' => LabelStatus::Sent,
        ]);

        $results = $this->app->make(StockLotSearch::class)->search('CN01-HW-001');

        $this->assertSame(
            [$pending->id, $quiet->id],
            $results->pluck('id')->all(),
        );
    }

    #[Test]
    public function the_queue_page_shows_the_reprint_panel_with_its_results(): void
    {
        $lot = $this->lot(sku: 'CN01-HW-777-U99');

        $html = $this->actingAs($this->operator)
            ->get(route('inbound.cetak-label', ['q' => 'HW-777']))
            ->assertOk()
            ->assertSee($lot->sku)
            ->assertSee('Cetak Ulang')
            ->getContent();

        // Select-all harus memakai daftar lot yang benar-benar dirender. Kalau
        // yang dikirim adalah id lot lain, "pilih semua" akan membuat operator
        // mengirim lot yang tidak dia lihat di tabel.
        preg_match('/reprintForm\\(.*?\\}\\, JSON\\.parse\(\'(.*?)\'\\)\\)/s', $html, $matches);

        $this->assertNotEmpty($matches, 'form cetak ulang harus menerima daftar lot yang dirender');
        $this->assertSame([$lot->id], json_decode($matches[1], true));

        // Lot-nya harus masuk state Alpine. Kalau tidak, header tetap kelihatan
        // seperti checkbox biasa yang tidak melakukan apa-apa saat ditekan.
        $this->assertStringContainsString('x-model.number="selected"', $html);
        $this->assertStringContainsString(':disabled="busy || ! canSubmit()"', $html);
    }

    private function queueView(): string
    {
        return (string) file_get_contents(resource_path('views/pages/inbound/cetak-label.blade.php'));
    }

    #[Test]
    public function the_search_row_aligns_its_bottom_because_the_field_carries_no_hint(): void
    {
        $view = $this->queueView();

        $this->assertSame(
            1,
            preg_match('/<x-ui\.field label="Cari lot" name="q"([^>]*)>/', $view, $field),
            'field pencarian harus tetap ada dan tanpa atribut lain',
        );

        // `sm:items-end` menyamakan DASAR kolom. Selama kolom kiri sama
        // dengan kolom tombol, field-nya harus setinggi `label` + `input` saja.
        // `x-ui.field` merender hint di bawah slot, jadi satu baris hint
        // ditambah 18px ke tinggi kolom dan dasar tombol ikut bergeser turun --
        // persis bugs yang terjadi sebelum hint ini dipindah ke bawah form.
        $this->assertStringNotContainsString(
            'hint=',
            $field[1],
            'field "Cari lot" tidak boleh punya hint di dalam field.',
        );

        // Hint tetap harus ada, hanya pindah tempat: sekarang sebagai paragraf
        // setelah form. Menghapusnya berarti operator kehilangan tahu kenapa
        // pencarian satu huruf tidak mengubah apa pun.
        $this->assertSame(
            1,
            substr_count($view, 'Minimal 2 karakter.'),
            'hint "Minimal 2 karakter." harus muncul tepat sekali.',
        );

        // Di luar form, bukan sekadar di dalam field. Form pencarian adalah
        // flex row; paragraf yang diletakkan DI DALAM form tapi sesudah tombol
        // akan jadi anak flex ketiga dan menambah tinggi baris -- masalah yang
        // sama, hanya pindah tempat. Menaruhnya sesudah `</form>` membuatnya
        // mustahil memengaruhi alignment.
        $searchForm = strpos($view, 'class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"');
        $this->assertIsInt($searchForm, 'form pencarian harus bisa ditemukan');

        $searchFormEnd = strpos($view, '</form>', $searchForm);
        $hint = strpos($view, 'Minimal 2 karakter.');

        $this->assertIsInt($searchFormEnd);
        $this->assertIsInt($hint);
        $this->assertGreaterThan(
            $searchFormEnd,
            $hint,
            'hint harus berada SETELAH </form> form pencarian, bukan di dalam baris flex-nya.',
        );
    }

    #[Test]
    public function the_reprint_controls_align_on_their_bottom_not_their_middle(): void
    {
        $this->assertSame(
            1,
            preg_match('/<div class="(sm:grid[^"]*sm:grid-cols-\[minmax\(0,18rem\)_7\.5rem_auto\][^"]*)">/', $this->queueView(), $grid),
            'grid kontrol cetak ulang harus tetap menemukan lebar kolomnya.',
        );

        // Kolom field = `label` + `input` = 66px, tombol = 44px. Kalau yang
        // disamakan adalah pusat (`items-center`), tombol naik 11px dari input
        // karena yang disejajarkan adalah titik tengah dua kotak yang tingginya
        // berbeda, bukan dua bagian yang sama-sama setinggi.
        $this->assertStringContainsString(
            'sm:items-end',
            $grid[1],
            'baris kontrol cetak ulang harus menyamakan dasar kolom.',
        );

        $this->assertStringNotContainsString(
            'sm:items-center',
            $grid[1],
            '`sm:items-center` membuat tombol naik 11px dari input.',
        );
    }

    #[Test]
    public function the_page_offers_no_button_that_prints_the_admin_ui(): void
    {
        $html = $this->actingAs($this->operator)
            ->get(route('inbound.cetak-label'))
            ->assertOk()
            ->getContent();

        // `window.print()` mencetak tab yang aktif, dan tab ini bukan dokumen
        // cetak: tidak ada `label.css`, tidak ada `.label-sheet`, tidak ada
        // `no-print`. Hasilnya seluruh UI admin tercetak tanpa satu pun label.
        // Alur yang benar sudah ada di "Tampilkan untuk Dicetak" yang membuka
        // `inbound.cetak-label.render` di tab baru.
        $this->assertStringNotContainsString('window.print', $html);
        $this->assertStringNotContainsString('Cetak Halaman', $html);

        // Slot actions tidak boleh jadi kosong setelah tombol itu dihapus.
        $this->assertStringContainsString('Uji Cetak', $html);
    }
}
