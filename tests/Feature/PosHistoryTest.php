<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman Riwayat Transaksi, dari tabel `sales` yang sebenarnya.
 *
 * Halaman ini dulunya menampilkan baris hard-coded: empat nota fiktif dengan
 * total yang tidak pernah berubah, dan empat kartu angka yang juga tidak
 * berubah. Semuanya terlihat benar sampai kebetulan salah, dan yang paling
 * berbahaya dari baris hard-coded adalah nota fiktif yang tak seorang pun bisa
 * temukan -- jadi halaman ini terlihat utuh padahal tidak ada yang tercatat.
 *
 * Yang dijaga di sini:
 *
 * 1. Angka dan barisnya datang dari database. Nota yang dibuat di test harus
 *    muncul; nota yang tidak dibuat tidak boleh muncul sebagai placeholder.
 * 2. Setiap filter benar-benar mempersempit hasil, bukan hanya menampilkan
 *    chip di toolbar. `DataTable` menyimpan salinan builder-nya sendiri, jadi
 *    menyaring setelah tabelnya dibuat terlihat benar dan tidak mengubah apa pun.
 * 3. Kasir hanya melihat nota shiftnya sendiri. Ini batas yang sama seperti di
 *    halaman Shift Kasir, dan diuji di sini karena batas yang salah di sini
 *    membocorkan penjualan milik orang lain ke layar kasir.
 */
class PosHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    /**
     * Nota milik satu shift, dengan satu pembayaran.
     */
    private function sale(
        User $cashier,
        PaymentMethod $method = PaymentMethod::Cash,
        string $receipt = 'POS-2026-0001',
        int $total = 90_000,
        int $qty = 1,
    ): Sale {
        $sale = Sale::factory()->create([
            'receipt_no' => $receipt,
            'shift_id' => Shift::factory()->create(['user_id' => $cashier->id])->id,
            'user_id' => $cashier->id,
            'total' => $total,
            'subtotal' => $total,
        ]);

        SaleItem::factory()->create(['sale_id' => $sale->id, 'qty' => $qty]);

        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'method' => $method,
            'amount' => $total,
        ]);

        return $sale;
    }

    #[Test]
    public function it_lists_notes_from_the_database(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001');
        $this->sale($cashier, receipt: 'POS-2026-0002');

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('POS-2026-0001')
            ->assertSee('POS-2026-0002');
    }

    #[Test]
    public function it_shows_the_empty_state_when_nothing_was_sold(): void
    {
        $this->actingAs($this->staff())
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('Belum ada transaksi');
    }

    #[Test]
    public function it_searches_by_receipt_number(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001');
        $this->sale($cashier, receipt: 'POS-2026-0002');

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi?q=0002')
            ->assertOk()
            ->assertSee('POS-2026-0002')
            ->assertDontSee('POS-2026-0001');
    }

    #[Test]
    public function it_searches_by_cashier_name(): void
    {
        $owner = $this->owner();
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001');
        $this->sale($owner, receipt: 'POS-2026-0002');

        $this->actingAs($owner)
            ->get('/pos/riwayat-transaksi?q='.urlencode($cashier->name))
            ->assertOk()
            ->assertSee('POS-2026-0001')
            ->assertDontSee('POS-2026-0002');
    }

    #[Test]
    public function it_filters_by_payment_method(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, PaymentMethod::Cash, receipt: 'POS-2026-0001');
        $this->sale($cashier, PaymentMethod::Qris, receipt: 'POS-2026-0002');

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi?method='.PaymentMethod::Qris->value)
            ->assertOk()
            ->assertSee('POS-2026-0002')
            ->assertDontSee('POS-2026-0001');
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001');
        $this->sale($cashier, receipt: 'POS-2026-0002')->update([
            'status' => SaleStatus::Voided,
            'voided_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi?status='.SaleStatus::Voided->value)
            ->assertOk()
            ->assertSee('POS-2026-0002')
            ->assertDontSee('POS-2026-0001');
    }

    /**
     * Nota dari perangkat offline tetap punya status `PAID`, jadi satu-satunya
     * pembeda dari nota biasa adalah `synced_at` yang kosong. Tanpa filter ini,
     * kasir melihat daftar yang tampak utuh padahal uang dari perangkat itu belum
     * masuk ke rekap shift.
     */
    #[Test]
    public function it_filters_notes_that_have_not_synced(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001');

        $offline = $this->sale($cashier, receipt: 'POS-2026-0002');
        $offline->update(['synced_at' => null, 'client_sale_id' => 'CS-abc']);

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi?pending_sync=1')
            ->assertOk()
            ->assertSee('POS-2026-0002')
            ->assertDontSee('POS-2026-0001');
    }

    #[Test]
    public function a_cashier_only_sees_their_own_notes(): void
    {
        $owner = $this->owner();
        $cashier = $this->staff();
        $other = $this->staff();

        $this->sale($cashier, receipt: 'POS-2026-0001');
        $this->sale($other, receipt: 'POS-2026-0002');

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('POS-2026-0001')
            ->assertDontSee('POS-2026-0002');
    }

    #[Test]
    public function the_owner_sees_every_note(): void
    {
        $owner = $this->owner();
        $this->sale($this->staff(), receipt: 'POS-2026-0001');
        $this->sale($this->staff(), receipt: 'POS-2026-0002');

        $this->actingAs($owner)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('POS-2026-0001')
            ->assertSee('POS-2026-0002');
    }

    /**
     * Filter shift harus membatasi pilihan dengan batas yang sama dengan tabelnya.
     *
     * Selector yang menawarkan semua shift ke kasir adalah filter yang selalu
     * mengembalikan kosong, dan yang kosong di sini terbaca sebagai "tidak ada
     * transaksi" -- bukan sebagai "kamu tidak berhak melihat shift itu".
     */
    #[Test]
    public function the_shift_filter_only_offers_shifts_the_reader_may_see(): void
    {
        $owner = $this->owner();
        $cashier = $this->staff();
        $other = $this->staff();

        $mine = Shift::factory()->create(['user_id' => $cashier->id]);
        $theirs = Shift::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($cashier)->get('/pos/riwayat-transaksi')->assertOk();

        $response->assertSee('Shift '.$mine->id);
        $response->assertDontSee('Shift '.$theirs->id);
    }

    #[Test]
    public function it_sorts_by_newest_first_without_an_explicit_choice(): void
    {
        $cashier = $this->staff();

        $older = $this->sale($cashier, receipt: 'POS-2026-0001');
        $older->update(['sold_at' => now()->subDay()]);

        $newer = $this->sale($cashier, receipt: 'POS-2026-0002');
        $newer->update(['sold_at' => now()]);

        $content = $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->getContent();

        // `assertLessThan($expected, $actual)` membaca "actual < expected", jadi
        // posisi nota yang lebih baru harus jadi argumen kedua.
        $this->assertLessThan(
            strpos((string) $content, 'POS-2026-0001'),
            strpos((string) $content, 'POS-2026-0002'),
            'Nota terbaru harus tampil sebelum yang lebih lama.',
        );
    }

    #[Test]
    public function it_shows_the_item_count_for_each_note(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, receipt: 'POS-2026-0001', qty: 3);

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('3');
    }

    #[Test]
    public function it_names_the_payment_method_as_the_cashier_reads_it(): void
    {
        $cashier = $this->staff();
        $this->sale($cashier, PaymentMethod::Qris, receipt: 'POS-2026-0001');

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('QRIS')
            ->assertDontSee('Qris');
    }

    #[Test]
    public function it_shows_why_a_note_is_not_settled(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier, receipt: 'POS-2026-0001');
        $sale->update(['status' => SaleStatus::SyncConflict]);

        $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertSee('Bentrok Sinkron');
    }
}
