<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Label\LabelGeometry;
use App\Services\Label\LabelTemplate;
use App\Services\Label\RackCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Label rak untuk put-away dan opname (FR-MD-21).
 *
 * Yang dijaga di sini bukan tampilan, tapi dua hal yang tidak terlihat dari
 * layar: kode rak selalu membawa prefix `RK:` supaya tidak tertukar dengan SKU
 * saat discan, dan kode rak yang kepanjangan DITOLAK, bukan dicetak terpotong.
 * Label rak yang terpotong lebih berbahaya daripada tidak ada label, karena
 * dua rak bersebelahan bisa tampil sama dan isinya tertukar.
 */
class RackLabelPrintTest extends TestCase
{
    use RefreshDatabase;

    private function printLabels(array $rackIds, string $template = '4x3', int $copies = 1)
    {
        return $this->post(route('master.lokasi-rak.print-labels'), [
            'rack_ids' => $rackIds,
            'template' => $template,
            'copies' => $copies,
        ]);
    }

    /**
     * Cetak label rak kini khusus Owner; Staff hanya boleh memakai POS.
     */
    #[Test]
    public function staff_cannot_open_the_rack_label_form(): void
    {
        Rack::factory()->create(['code' => 'A-01-03']);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('master.lokasi-rak.label-form'))
            ->assertForbidden();
    }

    /**
     * Rak nonaktif tidak ditawarkan: labelnya hanya menambah kertas.
     */
    #[Test]
    public function the_form_only_offers_active_racks(): void
    {
        Rack::factory()->create(['code' => 'A-01-01']);
        Rack::factory()->inactive()->create(['code' => 'Z-99-99']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('master.lokasi-rak.label-form'))
            ->assertSee('RK:A-01-01', escape: false)
            ->assertDontSee('RK:Z-99-99', escape: false);
    }

    /**
     * Tautan dari daftar harus ada, karena halaman ini pintu masuknya.
     */
    #[Test]
    public function the_rack_list_links_to_the_label_form(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee(route('master.lokasi-rak.label-form'), escape: false);
    }

    /**
     * Staff tidak boleh mencetak label rak sama sekali (kebijakan POS-only).
     */
    #[Test]
    public function staff_cannot_print_rack_labels(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $this->actingAs(User::factory()->staff()->create())
            ->printLabels([$rack->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $rack = Rack::factory()->create();

        $this->printLabels([$rack->id])->assertRedirect(route('login'));
    }

    /**
     * Prefix ditambahkan sekali, walaupun kode rak sudah membawanya.
     */
    #[Test]
    public function the_rack_prefix_is_never_doubled(): void
    {
        $this->assertSame('RK:A-01-03', RackCode::printable('A-01-03'));
        $this->assertSame('RK:A-01-03', RackCode::printable('RK:A-01-03'));
    }

    #[Test]
    public function the_large_template_carries_the_put_away_qr(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '4x3')
            ->assertSee('label__qr', escape: false)
            ->assertSee('RK:A-01-03', escape: false);
    }

    /**
     * 3x2 tetap tanpa QR: tidak ada ruang untuk QR dan kode yang sama-sama
     * terbaca. Yang penting kodenya tetap utuh.
     */
    #[Test]
    public function the_small_template_prints_the_code_without_a_qr(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '3x2');

        $response->assertSee('RK:A-01-03', escape: false);
        $response->assertDontSee('label__qr', escape: false);
    }

    /**
     * Beberapa rak untuk satu zona harus keluar dalam SATU halaman, bukan satu
     * sheet per rak. Kalau tidak, operator mengganti kertas untuk tiap label.
     */
    #[Test]
    public function several_racks_are_printed_in_one_sheet(): void
    {
        $racks = Rack::factory()->createMany([
            ['code' => 'A-01-01'],
            ['code' => 'A-01-02'],
            ['code' => 'A-01-03'],
        ]);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->printLabels($racks->pluck('id')->all());

        $response->assertViewHas('total', 3);

        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'label-sheet'));
        $this->assertSame(3, substr_count($html, 'label--rack'));
    }

    /**
     * Urutan cetak mengikuti pilihan operator, bukan urutan id.
     */
    #[Test]
    public function the_labels_follow_the_order_the_operator_picked(): void
    {
        $first = Rack::factory()->create(['code' => 'B-02-02']);
        $second = Rack::factory()->create(['code' => 'A-01-01']);

        // Dipilih terbalik dari urutan id, persis seperti operator yang mulai
        // dari rak paling dekat. Kalau urutan cetak ikut ikutan id, label keluar
        // dari sheet dengan urutan yang tidak diminta.
        $html = (string) $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$second->id, $first->id])
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'RK:B-02-02'),
            strpos($html, 'RK:A-01-01'),
        );
    }

    #[Test]
    public function copies_multiply_the_printed_labels(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], copies: 4);

        $response->assertViewHas('total', 4);
        $this->assertSame(4, substr_count((string) $response->getContent(), 'label--rack'));
    }

    // ------------------------------------------------- kode kepanjangan ---

    /**
     * Kode yang tidak muat harus DITOLAK, bukan dicetak lebih kecil.
     *
     * Diukur dari string yang benar-benar dicetak, yaitu termasuk `RK:`.
     */
    #[Test]
    public function a_rack_code_that_does_not_fit_is_rejected(): void
    {
        $template = LabelTemplate::from('3x2');
        $geometry = LabelGeometry::forRack($template);
        $capacity = $geometry->capacityFor($geometry->rows[0]);

        $rack = Rack::factory()->create([
            'code' => str_repeat('X', $capacity + 1),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '3x2')
            ->assertSessionHasErrors('rack_ids');

        // Pesannya harus menyebut rak mana yang bermasalah: operator sedang
        // berdiri di depan rak, dan "kode rak tidak muat" tanpa kode tidak
        // memberitahu rak mana yang harus diperpendek.
        $this->assertStringContainsString(
            $rack->code,
            (string) session('errors')->first('rack_ids'),
        );
    }

    /**
     * Batasnya "muat", dan yang harus muat adalah string yang keluar dari
     * printer. Panjang kode rak mentah saja tidak cukup: `RK:` menambah tiga
     * karakter, dan menghitung tanpa prefiks membuat batas meleset tepat
     * sebesar prefiks itu.
     */
    #[Test]
    public function a_rack_code_at_the_raw_capacity_is_rejected_because_of_the_prefix(): void
    {
        $template = LabelTemplate::from('3x2');
        $geometry = LabelGeometry::forRack($template);
        $capacity = $geometry->capacityFor($geometry->rows[0]);

        // Panjang mentahnya tepat di kapasitas, jadi masih terlihat "cukup"
        // kalau yang dihitung kode rak tanpa prefiks.
        $rack = Rack::factory()->create(['code' => str_repeat('A', $capacity)]);

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '3x2')
            ->assertSessionHasErrors('rack_ids');
    }

    /**
     * Kode tepat di batas masih boleh: batasnya "muat", bukan "kurang dari".
     */
    #[Test]
    public function a_rack_code_exactly_at_the_limit_is_accepted(): void
    {
        $template = LabelTemplate::from('3x2');
        $geometry = LabelGeometry::forRack($template);
        $capacity = $geometry->capacityFor($geometry->rows[0]);

        // `RK:` ikut dihitung, jadi sisa untuk kode rak tinggal capacity - 3.
        $rack = Rack::factory()->create([
            'code' => str_repeat('A', $capacity - strlen(RackCode::PREFIX)),
        ]);

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '3x2')
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------- validasi ---

    #[Test]
    public function at_least_one_rack_must_be_picked(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([])
            ->assertSessionHasErrors('rack_ids');
    }

    #[Test]
    public function an_unknown_rack_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([999_999])
            ->assertSessionHasErrors('rack_ids.0');
    }

    #[Test]
    public function an_unknown_template_is_rejected(): void
    {
        $rack = Rack::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '10x10')
            ->assertSessionHasErrors('template');
    }

    #[Test]
    public function zero_copies_are_rejected(): void
    {
        $rack = Rack::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], copies: 0)
            ->assertSessionHasErrors('copies');
    }

    /**
     * Label rak QR-only 1,5 cm harus tersedia di form, karena rak sempit
     * kadang tidak punya ruang untuk kode rak cetak. Yang membedakannya dari
     * 4x3: label ini tidak memuat teks kode sama sekali, hanya QR.
     */
    #[Test]
    public function the_qr_only_size_is_offered_for_racks_and_prints_only_a_qr(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('master.lokasi-rak.label-form'))
            ->assertOk()
            ->assertSee('value="1.5x1.5"', escape: false);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id], template: '1.5x1.5');

        $response->assertSee('label--qr-only', escape: false)
            ->assertSee('label__qr', escape: false)
            ->assertDontSee('RK:A-01-03', escape: false);
    }

    #[Test]
    public function printing_rack_labels_leaves_stock_untouched(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03']);
        $lot = StockLot::factory()->create(['rack_id' => $rack->id]);

        $before = $lot->only(['qty_on_hand', 'labels_printed', 'rack_id']);

        $this->actingAs(User::factory()->owner()->create())
            ->printLabels([$rack->id])
            ->assertOk();

        $this->assertSame($before, $lot->fresh()->only(['qty_on_hand', 'labels_printed', 'rack_id']));
    }
}
