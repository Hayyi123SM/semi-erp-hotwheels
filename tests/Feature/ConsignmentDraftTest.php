<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AssertsReceiptRedirect;
use Tests\TestCase;

/**
 * Draft consignment dan commit yang idempoten (FR-IB-13, FR-IB-14).
 *
 * Yang paling dijaga di sini adalah pengulangan commit. Kegagalan yang paling
 * mahal bukan "gagal commit", tapi "commit dua kali": SKU berganda, movement
 * berlipat, dan angka di layar tidak pernah cocok dengan isi gudang. Jadi setiap
 * jalur retry diuji dari sudut barang fisik -- `stock_lots` dan
 * `stock_movements` -- bukan dari redirect atau toast yang sama.
 */
class ConsignmentDraftTest extends TestCase
{
    use AssertsReceiptRedirect;
    use RefreshDatabase;

    private User $staff;

    private Consignor $consignor;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->consignor = Consignor::factory()->percentage(20)->create();
        $this->product = Product::factory()->create(['default_list_price' => 40_000]);
    }

    #[Test]
    public function the_draft_status_indicator_lives_inside_the_form(): void
    {
        $html = $this->actingAs($this->staff)
            ->get('/inbound/consignment-in')
            ->assertOk()
            ->getContent();

        $formStart = strpos($html, 'x-data="inboundGrid(');
        $formEnd = strrpos($html, '</form>');

        $this->assertNotFalse($formStart, 'Form Consignment In harus punya x-data inboundGrid.');
        $this->assertNotFalse($formEnd);

        // `saveState` cuma ada di scope komponen `inboundGrid`. Di luar form,
        // Alpine tidak mengenalinya dan melempar error di setiap render --
        // errornya muncul di konsol, jadi halaman tetap "terlihat jalan"
        // padahal indikator status draft-nya tidak pernah berfungsi.
        foreach (["saveState !== 'idle'", 'saveState === \'saving\''] as $marker) {
            $position = strpos($html, $marker);

            $this->assertNotFalse($position, "Indikator \"{$marker}\" tidak ada di halaman.");
            $this->assertGreaterThan(
                $formStart,
                $position,
                "Indikator \"{$marker}\" berada di luar x-data inboundGrid.",
            );
            $this->assertLessThan(
                $formEnd,
                $position,
                "Indikator \"{$marker}\" berada di luar x-data inboundGrid.",
            );
        }
    }

    #[Test]
    public function the_form_does_not_nest_another_form(): void
    {
        $html = $this->actingAs($this->staff)
            ->get('/inbound/consignment-in')
            ->assertOk()
            ->getContent();

        // Form untuk membuang draft punya form sendiri, jadi tidak boleh
        // diletakkan di dalam form utama: browser akan memindahkan form anak
        // ke luar form induk dan token CSRF-nya ikut hilang.
        $formStart = strpos($html, 'x-data="inboundGrid(');
        $formEnd = strrpos($html, '</form>');

        $this->assertNotFalse($formStart);
        $this->assertNotFalse($formEnd);
        $this->assertStringNotContainsString(
            '<form',
            substr($html, $formStart, $formEnd - $formStart),
            'Ada form bersarang di dalam form Consignment In.',
        );
    }

    #[Test]
    public function a_draft_is_saved_with_its_lines_and_can_be_resumed(): void
    {
        $rack = Rack::factory()->create();

        $draft = $this->startDraft($this->line(quantity: 3, rackId: $rack->id, schemeRate: '25'));

        $this->assertSame(ConsignmentStatus::Draft, $draft->status);
        $this->assertNotNull($draft->draft_id);
        $this->assertNotNull($draft->saved_at);

        // `doc_no` draft bukan nomor dokumen. Kalau ia ikut pola `CI-`, nomor
        // yang sebenarnya akan melompati urutan dan dokumen committed berikutnya
        // akan bernomor lebih besar dari jumlah dokumen yang benar-benar ada.
        $this->assertStringStartsWith('DRFT-', $draft->doc_no);
        $this->assertStringNotContainsString('CI-', $draft->doc_no);

        $line = $draft->items()->sole();
        $this->assertSame(3, $line->qty);
        $this->assertSame($rack->id, $line->rack_id);
        $this->assertEqualsWithDelta(25.0, (float) $line->scheme_rate, 0.001);

        // Draft yang disimpan tidak boleh pernah menyentuh stok.
        $this->assertSame(0, StockLot::count());
        $this->assertSame(0, StockMovement::count());
    }

    #[Test]
    public function a_resumed_draft_is_visible_to_the_staff_who_made_it(): void
    {
        $draft = $this->startDraft($this->line(quantity: 2, schemeRate: '12.5'));

        $resumed = $this->actingAs($this->staff)
            ->getJson("/inbound/consignment-in/drafts/{$draft->draft_id}");

        $resumed->assertOk();
        $resumed->assertJsonPath('draft.draft_id', $draft->draft_id);
        $resumed->assertJsonPath('draft.items.0.qty', 2);
        $resumed->assertJsonPath('draft.items.0.scheme_type', SchemeType::Percentage->value);
        // Rate desimal harus kembali sebagai angka desimal, bukan "12" atau
        // "12.50" yang nanti bikin kolom rate gagal di-isi ulang.
        $resumed->assertJsonPath('draft.items.0.scheme_rate', 12.5);
    }

    #[Test]
    public function a_draft_belongs_to_the_staff_who_made_it(): void
    {
        $draft = $this->startDraft($this->line(quantity: 2));

        $other = User::factory()->staff()->create();

        // Draft memuat harga dan skema penitip, jadi draft orang lain bukan
        // sekadar tidak berguna -- ia membocorkan harga konsinyasi orang tersebut.
        $this->actingAs($other)
            ->getJson("/inbound/consignment-in/drafts/{$draft->draft_id}")
            ->assertNotFound();
    }

    #[Test]
    public function autosave_replaces_the_lines_instead_of_stacking_them(): void
    {
        $draft = $this->startDraft($this->line(quantity: 3));

        $this->saveDraft($draft->draft_id, [$this->line(quantity: 1), $this->line(quantity: 2)]);

        $items = $draft->items()->get();

        $this->assertCount(2, $items);
        $this->assertSame([1, 2], $items->pluck('qty')->all());
        $this->assertSame([1, 2], $items->pluck('line_no')->all());
    }

    #[Test]
    public function a_committed_draft_is_locked_against_autosave(): void
    {
        $draft = $this->startDraft($this->line(quantity: 1));

        $this->commitViaDraft($draft->draft_id, $this->inheritingLine(quantity: 1));

        $this->saveDraft($draft->draft_id, [$this->line(quantity: 99)])
            ->assertStatus(409);
    }

    #[Test]
    public function committing_a_draft_reuses_the_same_document(): void
    {
        $draft = $this->startDraft($this->line(quantity: 2));

        $response = $this->commitViaDraft($draft->draft_id, $this->inheritingLine(quantity: 2));

        $consignment = Consignment::sole();

        $this->assertSame(1, Consignment::count());
        $this->assertSame($draft->id, $consignment->id);
        $this->assertSame($draft->draft_id, $consignment->draft_id);

        // Baris draft sudah menjadi `stock_lots`; membiarkannya hanya
        // menyisakan dua sumber kebenaran untuk baris yang sama.
        $this->assertSame(0, ConsignmentItem::count());
        $this->assertStringStartsWith('CI-', $consignment->doc_no);
        $this->assertSame(ConsignmentStatus::Committed, $consignment->status);
        $this->assertSame($this->staff->id, $consignment->committed_by);
        $this->assertNotNull($consignment->committed_at);

        $this->assertRedirectedToReceipt($response);
    }

    #[Test]
    public function resubmitting_a_committed_draft_does_not_stock_the_goods_twice(): void
    {
        $draft = $this->startDraft($this->line(quantity: 2));

        $this->commitViaDraft($draft->draft_id, $this->inheritingLine(quantity: 2));
        $this->commitViaDraft($draft->draft_id, $this->inheritingLine(quantity: 2));

        // Dua kali commit, satu lot. Ini yang diproteksi: bukan "toast-nya sama"
        // tapi barang di gudang yang tidak berlipat.
        $this->assertSame(1, StockLot::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(2, StockLot::sole()->qty_received);
    }

    #[Test]
    public function an_idempotency_key_survives_a_retry_that_never_had_a_draft(): void
    {
        $payload = [
            'consignor_id' => $this->consignor->id,
            'consignment_date' => now()->toDateString(),
            'verified' => '1',
            'items' => [$this->inheritingLine(quantity: 4)],
        ];

        // Skenario nyata: koneksi putus, Staff menekan commit lagi, dan form
        // di-submit ulang tanpa draft karena tab-nya sudah dimuat ulang.
        $first = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', 'retry-abc-123')
            ->post('/inbound/consignment-in', $payload);

        $retry = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', 'retry-abc-123')
            ->post('/inbound/consignment-in', $payload);

        // Status wajib ikut dijaga. Tanpa ini test ini tetap hijau ketika
        // constraint UNIQUE yang menolak penyisipan kedua, sementara Staff-nya
        // melihat halaman error 500 untuk commit yang sebenarnya sudah berhasil.
        $this->assertRedirectedToReceipt($first);
        $this->assertRedirectedToReceipt($retry);
        $retry->assertSessionHasNoErrors();

        $this->assertSame(1, Consignment::count());
        $this->assertSame(1, StockLot::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(4, StockLot::sole()->qty_received);
    }

    #[Test]
    public function different_idempotency_keys_are_treated_as_different_commits(): void
    {
        $payload = [
            'consignor_id' => $this->consignor->id,
            'consignment_date' => now()->toDateString(),
            'verified' => '1',
            'items' => [$this->inheritingLine(quantity: 1)],
        ];

        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', 'satu')->post('/inbound/consignment-in', $payload);

        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', 'dua')->post('/inbound/consignment-in', $payload);

        // Dua penitip mengantar barang dua kali memang dua dokumen -- dan dua
        // kali key yang sama adalah retry, bukan dua permintaan berbeda.
        $this->assertSame(2, Consignment::count());
        $this->assertSame(2, StockLot::count());
    }

    #[Test]
    public function a_discarded_draft_leaves_nothing_behind(): void
    {
        $draft = $this->startDraft($this->line(quantity: 5));

        $this->actingAs($this->staff)
            ->deleteJson("/inbound/consignment-in/drafts/{$draft->draft_id}")
            ->assertOk();

        $this->assertSame(0, Consignment::count());
        $this->assertSame(0, ConsignmentItem::count());
    }

    #[Test]
    public function a_draft_can_be_discarded_but_not_a_committed_document(): void
    {
        $draft = $this->startDraft($this->line(quantity: 1));
        $this->commitViaDraft($draft->draft_id, $this->inheritingLine(quantity: 1));

        $this->actingAs($this->staff)
            ->deleteJson("/inbound/consignment-in/drafts/{$draft->draft_id}")
            ->assertNotFound();
    }

    #[Test]
    public function the_claimed_quantity_is_kept_apart_from_the_received_one(): void
    {
        $this->actingAs($this->staff)->post('/inbound/consignment-in', [
            'consignor_id' => $this->consignor->id,
            'consignment_date' => now()->toDateString(),
            'verified' => '1',
            'qty_claimed' => 10,
            'variance_note' => 'Penitip klaim 10, terhitung 8.',
            'items' => [$this->inheritingLine(quantity: 8)],
        ])->assertSessionHasNoErrors();

        $consignment = Consignment::sole();

        // `qty_received` adalah sumber kebenaran, jadi angka yang dikoreksi Staff
        // tidak boleh menimpa klaim penitip: tanpa dua kolom terpisah, selisih
        // hilang begitu saja dan tidak bisa dicantumkan di e-receipt.
        $this->assertSame(8, $consignment->qty_received);
        $this->assertSame(10, $consignment->qty_claimed);
        $this->assertSame('Penitip klaim 10, terhitung 8.', $consignment->variance_note);
    }

    #[Test]
    public function an_omitted_claim_defaults_to_what_was_counted(): void
    {
        $this->actingAs($this->staff)->post('/inbound/consignment-in', [
            'consignor_id' => $this->consignor->id,
            'consignment_date' => now()->toDateString(),
            'verified' => '1',
            'items' => [$this->inheritingLine(quantity: 6)],
        ]);

        $consignment = Consignment::sole();

        $this->assertSame(6, $consignment->qty_claimed);
        $this->assertSame(6, $consignment->qty_received);
        $this->assertNull($consignment->variance_note);
    }

    /**
     * Draft dibuat lewat endpoint, lalu dibaca balik dari database.
     *
     * Objeknya diambil dari DB, bukan dari balasan JSON, supaya test ini yang
     * memastikan barisnya benar-benar ada -- kalau payload JSON-nya diparse
     * ulang ke model, test bisa hijau untuk request yang tidak menyimpan apa pun.
     */
    private function startDraft(array $line): Consignment
    {
        $this->actingAs($this->staff)
            ->postJson('/inbound/consignment-in/drafts', [
                'consignment_date' => now()->toDateString(),
                'consignor_id' => $this->consignor->id,
                'items' => [$line],
            ])
            ->assertCreated();

        return Consignment::sole();
    }

    private function saveDraft(string $draftId, array $lines)
    {
        return $this->actingAs($this->staff)
            ->patchJson("/inbound/consignment-in/drafts/{$draftId}", [
                'consignment_date' => now()->toDateString(),
                'consignor_id' => $this->consignor->id,
                'items' => $lines,
            ]);
    }

    private function commitViaDraft(string $draftId, array $line)
    {
        return $this->actingAs($this->staff)
            ->from('/inbound/consignment-in')
            ->post('/inbound/consignment-in', [
                'draft_id' => $draftId,
                'consignor_id' => $this->consignor->id,
                'consignment_date' => now()->toDateString(),
                'verified' => '1',
                'items' => [$line],
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Baris yang tidak menyebut skema sama sekali, jadi mewarisi kontrak
     * penitip. Dipakai di test yangvertebr scrutinized bukan tentang skema --
     * menjaga supaya test ini tidak ikut gagal karena `required_if` skema.
     *
     * @return array<string, mixed>
     */
    private function inheritingLine(int $quantity): array
    {
        $line = $this->line($quantity);
        unset($line['scheme_type'], $line['scheme_rate']);

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        int $quantity,
        ?int $rackId = null,
        ?string $schemeRate = null,
    ): array {
        return [
            'product_id' => $this->product->id,
            'qty' => (string) $quantity,
            'card_condition' => CardCondition::Mint->value,
            'blister_condition' => BlisterCondition::Clear->value,
            'rack_id' => $rackId,
            'scheme_type' => SchemeType::Percentage->value,
            'scheme_rate' => $schemeRate,
        ];
    }
}
