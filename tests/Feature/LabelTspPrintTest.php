<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\LabelPaperMode;
use App\Enums\LabelStatus;
use App\Models\LabelPrintJob;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\StickerSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jalur cetak label langsung ke printer TSPL.
 *
 * Kontraknya: pecahkan `text` per baris, dan perintah yang muncul harus
 * persis yang diminta operator -- jumlah label, urutannya, harga sesuai
 * toggle, dan koordinat grid yang nyata. Jalur ini tidak boleh "hanya
 * 200": kalau satu label hilang, di rak terjadi.
 */
class LabelTspPrintTest extends TestCase
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

    private function job(?StockLot $lot = null, int $copies = 1, bool $showPrice = true): LabelPrintJob
    {
        return LabelPrintJob::factory()->create([
            'lot_id' => ($lot ?? $this->lot())->id,
            'copies' => $copies,
            'show_price' => $showPrice,
            'status' => LabelStatus::Queued->value,
        ]);
    }

    private function lines(array $response): array
    {
        return explode("\n", trim($response['text']));
    }

    private function commands(array $lines, string $prefix): array
    {
        return array_values(array_filter(
            $lines,
            fn (string $line) => str_starts_with($line, $prefix),
        ));
    }

    #[Test]
    public function an_operator_can_fetch_tspl_commands_for_the_selected_jobs(): void
    {
        $job = $this->job(copies: 2);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk()
            ->json();

        $this->assertTrue($response['ok']);
        $this->assertSame(2, $response['total']);
        // `assertStringContainsString`, bukan `assertContains`: 'SIZE ' adalah
        // awalan baris 'SIZE 30 mm,20 mm', bukan satu elemen array.
        $this->assertStringContainsString('SIZE ', $response['text']);
        $this->assertSame(2, count($this->commands($this->lines($response), 'PRINT')));
    }

    #[Test]
    public function tspl_keeps_the_operator_selected_order_and_copies(): void
    {
        $first = $this->job($this->lot(sku: 'CN01-HW-001-U01'), copies: 1);
        $second = $this->job($this->lot(sku: 'CN01-HW-002-U02'), copies: 1);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$second->id, $first->id]])
            ->assertOk()
            ->json();

        // SKU muncul dua kali dalam urutan yang dipilih operator, bukan urutan id.
        $sku = $this->commands($this->lines($response), 'QRCODE');

        $this->assertStringContainsString('CN01-HW-002-U02', $sku[0]);
        $this->assertStringContainsString('CN01-HW-001-U01', $sku[1]);
    }

    #[Test]
    public function tspl_generation_does_not_change_the_job_status(): void
    {
        $job = $this->job();

        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk();

        $this->assertSame(LabelStatus::Queued, $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->lot->labels_printed);
    }

    #[Test]
    public function tspl_snapshots_content_like_the_html_path(): void
    {
        $lot = $this->lot(price: 50_000);
        $job = $this->job($lot);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk();

        $job->refresh();

        $this->assertSame(50_000, $job->payload['list_price']);
        $this->assertSame('CN01-HW-001-U03', $job->payload['sku']);
        $this->assertNotNull($job->rendered_at);
    }

    #[Test]
    public function show_price_false_removes_the_price_row_from_tspl_text(): void
    {
        $job = $this->job($this->lot(price: 87_000), showPrice: false);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk()
            ->json();

        $text = $response['text'];

        $this->assertStringNotContainsString('Rp87.000', $text);
        $this->assertStringContainsString('CN01-HW-001-U03', $text);
    }

    #[Test]
    public function settings_paper_mode_sheet_reports_grid_and_pages(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Sheet->value);
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, StickerSheet::BpTd110BtA6->value);
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, LabelTemplate::QrOnly->value);

        $job = $this->job();

        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk()
            ->json();

        $this->assertSame('sheet', $response['paper']);
        $this->assertSame(6, $response['columns']);
        $this->assertSame(8, $response['rows']);
        $this->assertSame(1, $response['sheets']);
        $this->assertContains('SIZE 100 mm,150 mm', $this->lines($response));
        $this->assertContains('GAP 2 mm,0 mm', $this->lines($response));
    }

    #[Test]
    public function settings_paper_mode_roll_uses_label_sized_sheets(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Roll->value);
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, LabelTemplate::QrOnly->value);

        $job = $this->job(copies: 2);

        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [$job->id]])
            ->assertOk()
            ->json();

        $this->assertSame('roll', $response['paper']);
        $this->assertNull($response['columns']);
        $this->assertSame(2, $response['sheets']);
        // Per label: SIZE dibuka sendiri dan PRINT satu-satu.
        $lines = $this->lines($response);
        $this->assertSame(2, count($this->commands($lines, 'SIZE 15 mm,15 mm')));
        $this->assertSame(2, count($this->commands($lines, 'PRINT')));
    }

    #[Test]
    public function requests_with_nonexistent_ids_fail_validation(): void
    {
        // RenderLabelsRequest menolak id yang tidak ada sebelum endpoint
        // dipanggil -- jalur html menerima perlindungan yang sama.
        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => [9_999_999]])
            ->assertStatus(422);
    }

    #[Test]
    public function more_labels_than_a_page_is_rejected(): void
    {
        // 200 job (batas ids) x 4 salinan = 800 > 600 label per halaman.
        $lot = $this->lot();
        $jobs = collect(range(1, 200))->map(
            fn () => LabelPrintJob::factory()->create([
                'lot_id' => $lot->id,
                'copies' => 4,
                'status' => LabelStatus::Queued->value,
            ]),
        );

        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.tsp'), ['ids' => $jobs->pluck('id')->all()])
            ->assertStatus(422);
    }
}
