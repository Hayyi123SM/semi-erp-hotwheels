<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConsignmentStatus;
use App\Enums\LabelStatus;
use App\Enums\MovementType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AssertsReceiptRedirect;
use Tests\TestCase;

class InboundTest extends TestCase
{
    use AssertsReceiptRedirect;
    use RefreshDatabase;

    #[Test]
    public function guest_is_redirected_to_login_for_inbound(): void
    {
        $this->get('/inbound/consignment-in')->assertRedirect(route('login'));
        $this->get('/inbound/stock-in-pribadi')->assertRedirect(route('login'));
        $this->post('/inbound/consignment-in')->assertRedirect(route('login'));
        $this->post('/inbound/stock-in-pribadi')->assertRedirect(route('login'));
    }

    #[Test]
    public function owner_commits_consignment_creating_sequential_skus_labels_and_movements(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $productA = Product::factory()->create(['series_id' => $series->id]);
        $productB = Product::factory()->create(['series_id' => $series->id]);
        $rack = Rack::factory()->create();

        $commit = $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'source' => 'Drop-off Expo',
            'verified' => '1',
            'items' => [
                ['product_id' => $productA->id, 'qty' => '2', 'rack_id' => $rack->id, 'card_condition' => 'MINT', 'blister_condition' => 'CLEAR'],
                ['product_id' => $productB->id, 'qty' => '1'],
            ],
        ]);

        $this->assertRedirectedToReceipt($commit);

        $consignment = Consignment::sole();
        $this->assertMatchesRegularExpression('/^CI-\d{8}-\d{4}$/', $consignment->doc_no);
        $this->assertSame(ConsignmentStatus::Committed, $consignment->status);
        $this->assertSame(3, $consignment->qty_received);
        $this->assertSame($consignor->id, $consignment->consignor_id);
        $this->assertSame('Drop-off Expo', $consignment->source);
        $this->assertSame($owner->id, $consignment->created_by);

        $prefix = substr($consignor->consignor_code, 0, 4).'-HW-';
        $lots = StockLot::query()->orderBy('id')->get();
        $this->assertCount(2, $lots);
        $this->assertSame([$prefix.'001', $prefix.'002'], $lots->pluck('sku')->all());
        $this->assertSame([2, 1], $lots->pluck('qty_on_hand')->all());
        $this->assertSame([$consignment->id, $consignment->id], $lots->pluck('consignment_id')->all());
        $this->assertSame($rack->id, $lots[0]->rack_id);

        $this->assertSame(
            [MovementType::InConsign, MovementType::InConsign],
            StockMovement::query()->orderBy('id')->pluck('type')->all()
        );

        $this->assertDatabaseCount('label_print_jobs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Consignment',
            'action' => 'RECEIVE_CONSIGN',
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function second_consignment_commit_continues_sequence_without_gaps(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $post = fn (int $qty) => $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => (string) $qty]],
        ]);

        $post(4);
        $second = $post(2);

        $this->assertRedirectedToReceipt($second);

        $prefix = substr($consignor->consignor_code, 0, 4).'-HW-';
        $this->assertSame(
            [$prefix.'001', $prefix.'002'],
            StockLot::query()->orderBy('id')->pluck('sku')->all()
        );

        $this->assertDatabaseCount('consignments', 2);
        $this->assertSame(ConsignmentStatus::Committed, Consignment::query()->latest('id')->firstOrFail()->status);
    }

    #[Test]
    public function consignment_commit_requires_verification_and_items(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'items' => [['product_id' => $product->id, 'qty' => '1']],
        ])->assertSessionHasErrors('verified');

        $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('consignments', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function consignment_qty_is_normalized_as_integers(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $commit = $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '0.750']],
        ]);

        $this->assertRedirectedToReceipt($commit);

        $this->assertSame(750, StockLot::sole()->qty_received);
    }

    #[Test]
    public function owner_commits_own_stock_with_cost_price_under_ow00(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $this->actingAs($owner)->post('/inbound/stock-in-pribadi', [
            'source' => 'INV-2026-0917',
            'verified' => '1',
            'items' => [
                ['product_id' => $product->id, 'qty' => '3', 'cost_price' => '45.000'],
            ],
        ])->assertRedirect(route('inbound.stock-in-pribadi'));

        $lot = StockLot::sole();
        $this->assertSame('OW00-HW-001', $lot->sku);
        $this->assertSame(45000, $lot->cost_price);
        $this->assertSame(3, $lot->qty_on_hand);
        $this->assertNull($lot->consignment_id);
        $this->assertSame(MovementType::InOwn, StockMovement::sole()->type);

        $this->assertDatabaseCount('label_print_jobs', 1);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'StockLot', 'action' => 'RECEIVE_OWN']);
    }

    #[Test]
    public function staff_cannot_commit_own_stock_but_may_commit_consignment(): void
    {
        $staff = User::factory()->staff()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $this->actingAs($staff)->post('/inbound/stock-in-pribadi', [
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '1', 'cost_price' => '10000']],
        ])->assertForbidden();

        $this->assertDatabaseCount('stock_lots', 0);

        $consignor = Consignor::factory()->create();
        $commit = $this->actingAs($staff)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '1']],
        ]);

        $this->assertRedirectedToReceipt($commit);

        $this->assertDatabaseCount('stock_lots', 1);
    }

    #[Test]
    public function own_stock_requires_non_negative_cost_price(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create();

        $this->actingAs($owner)->post('/inbound/stock-in-pribadi', [
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '1', 'cost_price' => '-1']],
        ])->assertSessionHasErrors('items.*.cost_price');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function pages_render_with_catalog_data(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id]);

        $this->actingAs($owner)->get('/inbound/consignment-in')->assertOk()->assertSee($consignor->name);
        $this->actingAs($owner)->get('/inbound/stock-in-pribadi')->assertOk();
    }

    #[Test]
    public function invalid_consignor_id_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => 999999,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => 1, 'qty' => '1']],
        ])->assertSessionHasErrors('consignor_id');

        $this->assertDatabaseCount('consignments', 0);
    }

    #[Test]
    public function cetak_label_lists_pending_jobs_and_marks_them_printed(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);
        $rack = Rack::factory()->create();

        $commit = $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '1', 'rack_id' => $rack->id]],
        ]);

        $this->assertRedirectedToReceipt($commit);

        $job = LabelPrintJob::sole();
        $this->assertSame(LabelStatus::Queued, $job->status);
        $lot = $job->lot;
        $this->assertSame(0, $lot->labels_printed);

        $this->actingAs($owner)
            ->get('/inbound/cetak-label')
            ->assertOk()
            ->assertSee($lot->sku);

        $this->actingAs($owner)->post('/inbound/cetak-label', [
            'ids' => [$job->id],
        ])->assertRedirect(route('inbound.cetak-label'));

        $job->refresh();
        $this->assertSame(LabelStatus::Sent, $job->status);
        $this->assertNotNull($job->printed_at);
        // Dikirim ke printer belum berarti label jadi, jadi belum dihitung.
        $this->assertSame(0, $job->lot->labels_printed);

        $this->actingAs($owner)->post('/inbound/cetak-label/konfirmasi', [
            'ids' => [$job->id],
        ])->assertRedirect(route('inbound.cetak-label'))->assertSessionHasNoErrors();

        // `refresh()`, bukan `fresh()`: `fresh()` mengembalikan model BARU dan
        // tidak menyentuh `$job`, sehingga relasi `lot` yang sudah ter-cache
        // masih menyimpan angka lama dan assertion di bawah membaca
        // `labels_printed` sebelum konfirmasi.
        $job->refresh();
        $this->assertSame(LabelStatus::Confirmed, $job->status);
        $this->assertSame(1, $job->lot->labels_printed);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'LabelPrintJob',
            'action' => 'PRINT_LABELS',
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function cetak_label_ignores_jobs_already_sent(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '1']],
        ]);

        $job = LabelPrintJob::sole();
        $lot = $job->lot;
        $this->actingAs($owner)->post('/inbound/cetak-label', ['ids' => [$job->id]])->assertRedirect();

        $lot->refresh();
        $this->assertSame(0, $lot->labels_printed);

        // Job yang sudah SENT tidak boleh bisa ditandai "sudah dicetak" lagi.
        $this->actingAs($owner)->post('/inbound/cetak-label', ['ids' => [$job->id]])
            ->assertSessionHasErrors('ids.0');

        $job->refresh();
        $lot->refresh();
        $this->assertSame(LabelStatus::Sent, $job->status);
        $this->assertSame(0, $lot->labels_printed);
    }

    #[Test]
    public function cetak_label_requires_ids(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/inbound/cetak-label', [])->assertSessionHasErrors('ids');
        $this->actingAs($owner)->post('/inbound/cetak-label', ['ids' => []]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'PRINT_LABELS']);
    }

    #[Test]
    public function consignment_history_and_detail_render_with_committed_consignment(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id, 'name' => 'Ford GT40']);

        $commit = $this->actingAs($owner)->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-28',
            'verified' => '1',
            'items' => [['product_id' => $product->id, 'qty' => '2']],
        ]);

        $this->assertRedirectedToReceipt($commit);

        $consignment = Consignment::sole();

        $this->actingAs($owner)
            ->get('/inbound/consignment-in/riwayat')
            ->assertOk()
            ->assertSee($consignment->doc_no)
            ->assertSee($consignor->name);

        $this->actingAs($owner)
            ->get('/inbound/consignment-in/'.$consignment->id)
            ->assertOk()
            ->assertSee($consignment->doc_no)
            ->assertSee('Ford GT40')
            ->assertSee($consignor->name)
            ->assertSee(StockLot::sole()->sku);
    }
}
