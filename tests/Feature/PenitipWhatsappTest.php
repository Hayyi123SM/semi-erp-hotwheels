<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\User;
use App\Services\Master\ConsignorWhatsappNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Satu nomor WhatsApp milik satu penitip, apa pun bentuk yang diketik.
 *
 * Aturan `unique` bawaan tidak bisa dipakai begitu saja di sini. Ia membandingkan
 * nilai yang diketik dengan nilai yang tersimpan apa adanya, sementara mutator
 * menyimpan bentuk polos -- jadi `0812-3456-7890` lolos pemeriksaan, dinormalkan
 * menjadi `6281234567890` oleh mutator, baris itu menabrak unique index di
 * database, dan Staff mendapat galat 500 untuk kesalahan yang sebenarnya cuma
 * nomor yang sama diketik dengan format lain.
 *
 * Pengujian di bawah memakai `DB::table()` untuk baris yang sengaja dituliskannya
 * langsung ke database, karena itulah kondisi yang harus aman: data lama yang sudah
 * ada di produksi tidak bisa diandalkan sudah polos.
 */
class PenitipWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private const string NOMOR = '6281234567890';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Form dan aksi penitip (konsinyor) hanya boleh diakses Owner.
        $this->staff = User::factory()->owner()->create();
        $this->actingAs($this->staff);
    }

    /**
     * Edit penitip adalah aksi Owner, jadi jalur edit diuji sebagai Owner.
     *
     * Yang diperiksa kelas ini adalah mutator nomor, dan mutator tidak peduli
     * siapa yang mengetiknya. Yang berubah hanya siapa yang boleh sampai ke
     * form edit sama sekali.
     */
    private function asOwner(): void
    {
        $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * Tuliskan nomor dalam bentuk mentah, melewati mutator.
     *
     * Mewiru data lama yang sudah tersimpan sebelum normalisasi ada.
     */
    private function legacyConsignor(string $code, string $rawNumber, bool $optedIn = false): Consignor
    {
        $id = DB::table('consignors')->insertGetId([
            'consignor_code' => $code,
            'name' => 'Penitip '.$code,
            'wa_number' => $rawNumber,
            'wa_opt_in_at' => $optedIn ? now() : null,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Consignor::findOrFail($id);
    }

    #[Test]
    public function a_number_is_stored_plain_whatever_form_it_was_typed_in(): void
    {
        $this->post('/master/penitip', [
            'consignor_code' => 'CN01',
            'name' => 'Budi',
            'wa_number' => '+62 812-3456-7890',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::NOMOR, Consignor::sole()->wa_number);
    }

    /**
     * Jalur edit harus menormalkan juga, bukan hanya saat membuat.
     *
     * `fill()` adalah jalur yang berbeda dari `create()`: ia hanya menyentuh
     * atribut yang ada di payload, sehingga mutator bisa dilewati tanpa terlihat.
     */
    #[Test]
    public function a_number_is_stored_plain_when_a_consignor_is_edited(): void
    {
        $consignor = $this->legacyConsignor('CN01', '081234567890');
        $this->asOwner();

        $this->put('/master/penitip/'.$consignor->id, [
            'consignor_code' => 'CN01',
            'name' => 'Budi',
            'wa_number' => '+62 812-3456-7890',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::NOMOR, $consignor->fresh()->wa_number);
    }

    /**
     * Dua bentuk nomor yang sama harus ditolak sebagai pesan validasi, bukan
     * lewat aturan unique lalu ditabrak index di database.
     */
    #[Test]
    public function the_same_number_typed_two_ways_is_refused_as_validation_not_a_crash(): void
    {
        $this->legacyConsignor('CN01', '+62 812-3456-7890');

        $response = $this->post('/master/penitip', [
            'consignor_code' => 'CN02',
            'name' => 'Sari',
            'wa_number' => '0812-3456-7890',
            'status' => 'ACTIVE',
        ]);

        $response->assertSessionHasErrors('wa_number');
        $this->assertSame(1, Consignor::count(), 'Penitip kedua tidak boleh tersimpan.');
    }

    #[Test]
    public function the_reverse_direction_is_refused_too(): void
    {
        $this->legacyConsignor('CN01', '081234567890');

        $this->post('/master/penitip', [
            'consignor_code' => 'CN02',
            'name' => 'Sari',
            'wa_number' => '+6281234567890',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('wa_number');
    }

    /**
     * Edit tidak boleh menabrak diri sendiri: penitip yang tidak mengubah
     * nomornya harus tetap bisa disimpan.
     */
    #[Test]
    public function a_consignor_may_save_their_own_number_unchanged(): void
    {
        $consignor = $this->legacyConsignor('CN01', '081234567890');
        $this->asOwner();

        $this->put('/master/penitip/'.$consignor->id, [
            'consignor_code' => 'CN01',
            'name' => 'Budi',
            'wa_number' => '0812-3456-7890',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function editing_to_a_number_someone_else_already_owns_is_refused(): void
    {
        $first = $this->legacyConsignor('CN01', '081234567890');
        $second = $this->legacyConsignor('CN02', '6289998887776');
        $this->asOwner();

        $this->put('/master/penitip/'.$second->id, [
            'consignor_code' => 'CN02',
            'name' => 'Sari',
            'wa_number' => '0812-3456-7890',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('wa_number');

        // Nomor yang sudah dipakai tidak boleh berubah, dan penitip yang
        // dicoba diubah pun tetap pada nomor lamanya.
        $this->assertSame('081234567890', $first->fresh()->wa_number);
        $this->assertSame('6289998887776', $second->fresh()->wa_number);
    }

    /**
     * Nomor yang tidak bisa jadi tujuan `wa.me` ditolak dengan format yang
     * disebutkan, bukan diterima lalu gagal diam-diam.
     */
    #[Test]
    public function a_number_that_cannot_be_reached_is_refused_with_a_message(): void
    {
        $this->post('/master/penitip', [
            'consignor_code' => 'CN01',
            'name' => 'Budi',
            'wa_number' => 'bukan nomor',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('wa_number');

        $this->post('/master/penitip', [
            'consignor_code' => 'CN02',
            'name' => 'Sari',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();
    }

    /**
     * Migration backfill harus membuat dua bentuk legacy yang sama menyatu,
     * dengan yang punya opt-in yang dipertahankan.
     */
    #[Test]
    public function the_backfill_merges_two_legacy_forms_and_keeps_the_opted_in_one(): void
    {
        $optedIn = $this->legacyConsignor('CN01', '081234567890', optedIn: true);
        $other = $this->legacyConsignor('CN02', '+62 812-3456-7890');

        $result = app(ConsignorWhatsappNormalizer::class)->run();

        $this->assertSame(1, $result->emptiedCount());
        $this->assertSame(self::NOMOR, $optedIn->fresh()->wa_number);
        $this->assertNull($other->fresh()->wa_number, 'Nomor yang kalah tabrakan harus dikosongkan, bukan dihapus.');

        // Datanya sendiri harus utuh supaya Owner bisa mengisinya lagi lewat form.
        $this->assertSame('Penitip CN02', $other->fresh()->name);
    }

    #[Test]
    public function the_backfill_keeps_going_when_a_merged_row_is_left_empty(): void
    {
        $this->legacyConsignor('CN01', '081234567890', optedIn: true);
        $loser = $this->legacyConsignor('CN02', '+6281234567890');

        $result = app(ConsignorWhatsappNormalizer::class)->run();

        $this->assertSame(1, $result->emptiedCount());
        $this->assertNull($loser->fresh()->wa_number);
    }
}
