<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Notification\ConsignmentReceipt;
use App\Services\Notification\NotificationTemplate;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E-receipt dibaca penitip sebagai bukti, jadi dua hal yang diuji di sini
 * adalah kebenarannya dan isinya.
 *
 * Kebenaran: angkanya harus sama dengan yang ada di dokumen dan di lot yang
 * sudah commit. Kalau tidak, pesan ini justru menciptakan perselisihan yang
 * seharusnya tidak ada: dua pihak membaca dua dokumen yang berbeda.
 *
 * Isi: yang boleh keluar hanya yang perlu. Rekening dan bank tidak masuk,
 * karena memberitahu barang sudah diterima tidak butuh data rekening, dan
 * begitu data rekening terkirim, data itu keluar dari kendali toko.
 */
class ConsignmentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Consignment $consignment;

    private Consignor $consignor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consignor = Consignor::factory()->create([
            'consignor_code' => 'CN42',
            'name' => 'Koleksi Andre',
            'wa_number' => '6281234567890',
            'wa_opt_in_at' => now()->subMonth(),
            'bank_name' => 'BCA',
            'bank_account' => '1234567890',
            'bank_holder' => 'Andre Setiawan',
        ]);

        $this->consignment = Consignment::factory()->completed()->create([
            'doc_no' => 'CI-20260928-0007',
            'consignor_id' => $this->consignor->id,
            'consignment_date' => '2026-09-28',
            'qty_claimed' => 3,
            'qty_received' => 3,
            'created_by' => User::factory()->staff()->create(),
        ]);

        $this->series = ProductSeries::factory()->create(['code' => 'HW']);
    }

    private ProductSeries $series;

    private function lot(array $attributes = []): StockLot
    {
        return StockLot::factory()->create(array_merge([
            'consignment_id' => $this->consignment->id,
            'consignor_id' => $this->consignor->id,
            'product_id' => Product::factory()->create(['series_id' => $this->series->id])->id,
            'rack_id' => Rack::factory()->create(),
            'sku' => 'CN42-HW-001',
            'list_price' => 45_000,
            'qty_received' => 2,
        ], $attributes));
    }

    #[Test]
    public function body_contains_document_identity_from_the_committed_data(): void
    {
        $this->lot();

        $body = (new ConsignmentReceipt($this->consignment->load('consignor.consignments')))->body();

        $this->assertStringContainsString('CI-20260928-0007', $body);
        $this->assertStringContainsString('Koleksi Andre', $body);
        $this->assertStringContainsString('CN42', $body);
        $this->assertStringContainsString('28 Sep 2026', $body);
    }

    /**
     * "item" dan "pcs" adalah dua angka yang berbeda.
     *
     * Dua lot dengan isi 2 dan 3 pcs berarti 2 item / 5 pcs, bukan 5 item
     * atau 5 pcs. Menyamakan keduanya membuat angka yang salah sampai ke
     * penitip, dan yang salah di e-receipt selalu dibaca sebagai selisih.
     */
    #[Test]
    public function item_count_is_lots_and_qty_is_pieces(): void
    {
        $this->lot(['sku' => 'CN42-HW-001', 'qty_received' => 2]);
        $this->lot(['sku' => 'CN42-HW-002', 'qty_received' => 3]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('2 item', $body);
        $this->assertStringContainsString('5 pcs', $body);
        $this->assertStringNotContainsString('5 item', $body);
    }

    #[Test]
    public function detail_lists_every_lot_with_price_and_scheme(): void
    {
        $this->lot([
            'sku' => 'CN42-HW-001',
            'list_price' => 45_000,
            'qty_received' => 2,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 20,
        ]);
        $this->lot([
            'sku' => 'CN42-HW-002',
            'list_price' => 150_000,
            'qty_received' => 1,
            'scheme_type' => SchemeType::Nett,
            'scheme_rate' => null,
            'scheme_amount' => 38_000,
        ]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('CN42-HW-001', $body);
        $this->assertStringContainsString(Format::rupiah(45_000), $body);
        $this->assertStringContainsString('skema 20%', $body);
        $this->assertStringContainsString('CN42-HW-002', $body);
        $this->assertStringContainsString(Format::rupiah(150_000), $body);
        $this->assertStringContainsString('skema '.Format::rupiah(38_000).'/unit', $body);
    }

    /**
     * `scheme_rate` bertipe decimal, jadi `20.00` harus tampil `20`.
     *
     * Kalau tidak, pesan berbunyi "skema 20.00%" sementara dokumen di layar
     * Staff berbunyi "20%". Penitip yang menghitung sendiri komisinya akan
     * melihat dua angka berbeda untuk hal yang sama.
     */
    #[Test]
    public function decimal_scheme_rate_is_not_shown_with_trailing_zeroes(): void
    {
        $this->lot(['scheme_type' => SchemeType::Percentage, 'scheme_rate' => 20.00]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('skema 20%', $body);
        $this->assertStringNotContainsString('20.00%', $body);
        $this->assertStringNotContainsString('20,00%', $body);
    }

    /**
     * Tidak ada data pembayaran yang boleh ikut.
     *
     * Data rekening ada di profil penitip dan tidak ada di e-receipt. Kalau
     * bocor, ini bukan hanya soal privasi: satu pesan konsinyasi bisa dibaca
     * lebih dari satu orang, dan orang ketiga itu tidak pernah ada di transaksi.
     */
    #[Test]
    public function bank_details_never_reach_the_message(): void
    {
        $this->lot();

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        foreach (['1234567890', 'BCA', 'Andre Setiawan'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "'{$secret}' seharusnya tidak ada di e-receipt.",
            );
        }
    }

    #[Test]
    public function variance_note_from_staff_is_kept_verbatim(): void
    {
        $this->consignment->update([
            'qty_claimed' => 5,
            'qty_received' => 3,
            'variance_note' => '2 pcs patah cardboard, disepakati di depan penitip.',
        ]);
        $this->lot(['qty_received' => 3]);

        $body = (new ConsignmentReceipt($this->consignment->fresh()->load('consignor')))->body();

        $this->assertStringContainsString('2 pcs patah cardboard, disepakati di depan penitip.', $body);
    }

    /**
     * Tanpa catatan, selisih tetap harus terlihat.
     *
     * Staff kadang tidak mengisi `variance_note`; angka klaim dan terima
     * sudah ada di dokumen. Kalau cuma andalkan catatan, selisih yang tidak
     * dijelaskan hilang dari bukti yang justru dikirim untuk menutup
     * perselisihan.
     */
    #[Test]
    public function unexplained_variance_is_still_reported(): void
    {
        $this->consignment->update(['qty_claimed' => 5, 'qty_received' => 3, 'variance_note' => null]);
        $this->lot(['qty_received' => 3]);

        $body = (new ConsignmentReceipt($this->consignment->fresh()->load('consignor')))->body();

        $this->assertStringContainsString('Penitip mengklaim 5', $body);
        $this->assertStringContainsString('diterima 3', $body);
    }

    #[Test]
    public function no_variance_means_no_variance_note(): void
    {
        $this->consignment->update(['qty_claimed' => 2, 'qty_received' => 2, 'variance_note' => null]);
        $this->lot(['qty_received' => 2]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringNotContainsString('Catatan:', $body);
    }

    /**
     * Nama toko ada di judul, karena itu isi `{{1}}` di SRS.
     */
    #[Test]
    public function store_name_from_settings_replaces_the_title_placeholder(): void
    {
        Setting::set('store.name', 'Hot Wheels Kemang');
        $this->lot();

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('Hot Wheels Kemang', $body);
    }

    #[Test]
    public function missing_store_name_falls_back_instead_of_leaving_the_placeholder(): void
    {
        $this->lot();

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringNotContainsString('{store}', $body);
        $this->assertStringNotContainsString('{', $body);
    }

    /**
     * Tidak boleh ada placeholder yang tertinggal setelah body jadi.
     *
     * Kalau ada, pemanggil melihat pesan yang utuh dan mengira semua variabel
     * terisi -- padahal ada yang hilang tepat di tempat yang tidak dibaca.
     */
    #[Test]
    public function rendered_body_has_no_leftover_placeholders(): void
    {
        $this->lot();
        $this->lot(['sku' => 'CN42-HW-002']);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        preg_match_all('/\{[a-z_]+\}/', $body, $matches);

        $this->assertSame([], $matches[0] ?? [], 'Ada variabel yang tidak terisi: '.implode(', ', $matches[0] ?? []));
    }

    /**
     * Template bawaan harus tetap dipakai kalau Staff belum mengubahnya.
     */
    #[Test]
    public function default_template_is_used_when_nothing_is_saved(): void
    {
        $this->lot();

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('Bukti Terima Titipan', $body);
        $this->assertStringContainsString('Simpan pesan ini sebagai bukti', $body);
    }

    /**
     * Template yang disimpan Staff harus benar-benar dipakai.
     *
     * Kalau pengaturannya diabaikan, semua kerjaan di halaman Pengaturan
     * tidak punya efek dan tidak ada yang memberitahu -- karena pesannya tetap
     * terkirim dengan benar.
     */
    #[Test]
    public function saved_template_overrides_the_default(): void
    {
        $this->lot();
        Setting::set(
            NotificationTemplate::ConsignmentReceipt->settingKey(),
            'TERIMA KIRIMAN {doc_no} dari {store}. Bukan {not_a_variable}.',
        );

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('TERIMA KIRIMAN CI-20260928-0007 dari ', $body);
        $this->assertStringNotContainsString('Bukti Terima Titipan', $body);
    }

    /**
     * Namanya yang sedang diganti tidak boleh saling menimpa.
     *
     * `str_replace(['{doc_no}', '{doc}'], ...)` akan merusak `{doc_no}` kalau
     * template punya `{doc}`. `strtr()` tidak, karena ia mengganti berdasarkan
     * panjang kunci. Ini yang menjaga template Staff tetap aman setelah ada
     * variabel baru yang namanya berawalan sama.
     */
    #[Test]
    public function variable_names_are_not_mangled_by_each_other(): void
    {
        $this->lot();
        $this->lot(['sku' => 'CN42-HW-002']);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body('{doc_no}|{consignor_code}');

        $this->assertSame('CI-20260928-0007|CN42', $body);
    }

    /**
     * Lot yang skemanya belum diisi tidak boleh merusak baris detailnya.
     */
    #[Test]
    public function lot_without_scheme_still_lists_sku_qty_and_price(): void
    {
        $this->lot([
            'sku' => 'CN42-HW-001',
            'scheme_type' => null,
            'scheme_rate' => null,
            'scheme_amount' => null,
        ]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('CN42-HW-001', $body);
        $this->assertStringContainsString(Format::rupiah(45_000), $body);
        $this->assertStringNotContainsString('skema 20%', $body);
        $this->assertStringNotContainsString('/unit', $body);
    }

    /**
     * Dokumen tanpa lot harus tetap bisa dikirim.
     *
     * Ini kondisi yang nyata: konsinyasi_void atau draft yang belum pernah
     * punya barang. Pesan yang kosong membuat `{detail}` hilang, dan pesan
     * yang hilang lebih buruk daripada pesan yang jujur kosong.
     */
    #[Test]
    public function document_without_lots_still_renders(): void
    {
        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('CI-20260928-0007', $body);
        $this->assertStringContainsString('0 item', $body);
        $this->assertStringNotContainsString('{detail}', $body);
    }

    /**
     * Baris item dokumen bukan sumber angka untuk pesan.
     *
     * Kalau `ConsignmentItem` ikut dipakai, dan barisnya berbeda dari lot
     * (mis. karena lot di-return sebagian), angka "item" bisa ikut berubah
     * sementara isi lot tidak -- dan sekarang ada dua sumber kebenaran untuk
     * angka yang sama.
     */
    #[Test]
    public function item_count_comes_from_lots_not_from_document_lines(): void
    {
        $this->lot(['sku' => 'CN42-HW-001', 'qty_received' => 4]);

        ConsignmentItem::create([
            'consignment_id' => $this->consignment->id,
            'product_id' => Product::factory()->create(['series_id' => $this->series->id])->id,
            'line_no' => 1,
            'qty' => 9,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => 45_000,
        ]);

        $body = (new ConsignmentReceipt($this->consignment->load('consignor')))->body();

        $this->assertStringContainsString('1 item', $body);
        $this->assertStringContainsString('4 pcs', $body);
        $this->assertStringNotContainsString('9 item', $body);
    }
}
