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
 * Halaman detail satu nota, `GET /pos/nota/{sale}`, dan jalan masuknya dari
 * tabel riwayat.
 *
 * Dua hal yang dijaga di sini:
 *
 * 1. **Kepemilikannya sama dengan tabel riwayatnya.** Kasir membuka nota dari
 *    shiftnya sendiri, Owner membuka semua. Kalau keduanya berbeda, daftar
 *    menampilkan nota yang halamannya menolak -- dan balasannya harus 404, bukan
 *    403: 403 memberi tahu kasir bahwa nota orang lain memang ada.
 *
 * 2. **Halaman ini hanya membaca.** Tidak ada tombol cetak, tidak ada tombol
 *    void. Keduanya menulis nota, dan keduanya layak dibicarakan terpisah;
 *    yang paling berbahaya adalah tombol yang ada tapi tidak bekerja, karena
 *    kasir akan menekannya berulang sambil menunggu kertas yang tidak akan
 *    keluar.
 */
class PosSaleDetailTest extends TestCase
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
     * Satu nota berisi satu baris, milik shift kasirnya sendiri.
     */
    private function sale(User $cashier, string $receipt = 'HW-20261006-0001'): Sale
    {
        $sale = Sale::factory()->create([
            'receipt_no' => $receipt,
            'shift_id' => Shift::factory()->forUser($cashier)->create()->id,
            'user_id' => $cashier->id,
        ]);

        SaleItem::factory()->create(['sale_id' => $sale->id]);

        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'method' => PaymentMethod::Cash,
            'amount' => $sale->total,
        ]);

        return $sale;
    }

    #[Test]
    public function a_cashier_may_read_their_own_note(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);
        $item = $sale->items()->sole();

        $this->actingAs($cashier)
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->assertSee($sale->receipt_no)
            ->assertSee('Barang (1 baris)')
            ->assertSee($item->sku)
            ->assertSee('Pembayaran')
            ->assertSee('Subtotal');
    }

    /**
     * Nota orang lain 404, bukan 403.
     *
     * Balasan 403 memberi tahu pengunjung bahwa nota itu ada dan dia tidak
     * berhak -- informasi yang tidak perlu diberikan pada kasir yang sedang
     * mengintip nomor nota milik kasir lain.
     */
    #[Test]
    public function another_cashiers_note_is_not_there_at_all(): void
    {
        $cashier = $this->staff();
        $other = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($other)
            ->get('/pos/nota/'.$sale->id)
            ->assertNotFound();
    }

    #[Test]
    public function the_owner_may_read_any_note(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($this->owner())
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->assertSee($sale->receipt_no);
    }

    /**
     * Dua jalan ke tempat yang sama: tombol "Detail", dan baris yang bisa diklik.
     *
     * Nomor notanya sendiri tidak boleh jadi tautan -- kasir perlu menyalinnya,
     * dan tautan di dalam sel menyalin alih-alih teksnya.
     */
    #[Test]
    public function the_history_offers_a_detail_action_and_a_clickable_row(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);
        $url = route('pos.nota', $sale);

        $html = $this->actingAs($cashier)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.$url.'"', $html, 'Kolom aksi harus menawarkan "Detail".');
        $this->assertStringContainsString('data-row-url="'.$url.'"', $html, 'Baris harus bisa diklik ke halaman notanya.');

        $this->assertDoesNotMatchRegularExpression(
            '/<a\b[^>]*>\s*'.preg_quote($sale->receipt_no, '/').'\s*<\/a>/',
            $html,
            'Nomor nota harus tetap teks yang bisa disalin, bukan tautan.',
        );
    }

    /**
     * Nota yang dibatalkan tetap terbuka, dengan alasannya terbaca.
     *
     * Menghapusnya akan membuat riwayat tidak bisa menjelaskan mengapa uang di
     * laci berbeda dari jumlah penjualannya.
     */
    #[Test]
    public function a_voided_note_stays_readable_with_its_reason(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);
        $sale->update([
            'status' => SaleStatus::Voided,
            'voided_at' => now(),
            'void_reason' => 'Salah pindai dua kali',
        ]);

        $this->actingAs($cashier)
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->assertSee('Nota dibatalkan')
            ->assertSee('Salah pindai dua kali')
            ->assertSee('Dibatalkan');
    }

    /**
     * Status yang belum bisa dipercaya menjelaskan dirinya sendiri.
     *
     * Tanpa kalimatnya, kasir melihat badge kuning dan tidak tahu harus
     * menghubungi siapa -- uang dan barangnya sudah benar, yang belum pasti
     * hanya apakah angka di nota itu masih berlaku.
     */
    #[Test]
    public function a_note_that_is_not_settled_says_why(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);
        $sale->update(['status' => SaleStatus::SyncConflict]);

        $this->actingAs($cashier)
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->assertSee('Perlu ditinjau')
            ->assertSee($sale->fresh()->flagSummary());
    }

    /**
     * Barang milik toko sendiri tidak ditampilkan seolah titipan.
     *
     * Skemanya memang tidak ada, dan menuliskan "-" akan membuat pembaca
     * mengira skemanya belum diisi -- padahal tidak ada yang perlu diisi karena
     * memang tidak ada yang dibagi.
     */
    #[Test]
    public function own_stock_is_labelled_as_the_stores_own(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);
        $sale->items()->sole()->update([
            'scheme_type' => null,
            'scheme_rate' => null,
            'scheme_amount' => null,
            'hak_penitip' => null,
            'fee_toko' => 90_000,
        ]);

        $this->actingAs($cashier)
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->assertSee('Milik Toko');
    }

    /**
     * Halaman ini hanya membaca, dan satu-satunya pintu keluar yang menulis
     * dibawanya adalah halaman struk.
     *
     * "Cetak Struk" membuka halaman struk (`pos.struk`) -- halaman yang tidak
     * menulis apa pun ke nota. Tidak ada dan tidak boleh ada tombol void di
     * sini: void menulis nota, dan layak dibicarakan terpisah dengan
     * pengawalnya sendiri.
     */
    #[Test]
    public function the_page_offers_the_struk_and_nothing_that_writes(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $html = $this->actingAs($cashier)
            ->get('/pos/nota/'.$sale->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cetak Struk', $html);
        $this->assertStringContainsString('href="'.route('pos.struk', $sale).'"', $html);

        // Bentuk konfirmasi bersama yang dipakai semua aksi merusak di daftar --
        // void akan lewat sini kalau sudah ada. Halamannya tidak punya tabel,
        // jadi tidak seharusnya ada bentuk itu sama sekali.
        $this->assertStringNotContainsString('x-data="rowConfirm"', $html);
    }
}
