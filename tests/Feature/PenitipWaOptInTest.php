<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Persetujuan WhatsApp harus bisa dicatat dari form, dicatat sebagai waktu, dan
 * bisa ditarik kembali.
 *
 * Column `wa_opt_in_at` sudah ada sejak D6, tetapi tidak ada satu pun jalan
 * masuknya: form penitip tidak pernah menawarkannya, jadi satu-satunya cara
 * mengisinya adalah menulis langsung ke database. Persetujuan yang tidak bisa
 * dicatat dari layar bukan persetujuan, dan pencatatannya pun harus menyimpan
 * waktunya, bukan hanya tanda centangnya.
 *
 * Kotak centang yang tidak dicentang berarti mencabut, bukan berarti "kosongkan
 * saja kolom ini". Kalau keduanya sama, mengosongkan form tidak pernah mengubah
 * apa pun, dan pencabutan tidak bisa dibedakan dari records yang belum pernah
 * ditanyakan.
 */
class PenitipWaOptInTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->actingAs($this->staff);
    }

    private function createConsignor(array $overrides = []): Consignor
    {
        return Consignor::create(array_merge([
            'consignor_code' => 'CN01',
            'name' => 'Budi Santoso',
            'status' => 'ACTIVE',
        ], $overrides));
    }

    #[Test]
    public function the_form_offers_the_opt_in(): void
    {
        // Tanpa kotak centang di form, tidak ada yang bisa mengisinya.
        $html = $this->get(route('master.penitip.create'))->getContent();

        $this->assertStringContainsString('name="wa_opt_in"', $html);
    }

    #[Test]
    public function ticking_the_box_records_the_time_it_was_ticked(): void
    {
        $before = now()->subMinute();

        $this->post('/master/penitip', [
            'name' => 'Budi Santoso',
            'wa_number' => '+62 812-3456-7890',
            'wa_opt_in' => '1',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $consignor = Consignor::sole();

        $this->assertNotNull($consignor->wa_opt_in_at, 'Persetujuan yang dicatat harus punya waktunya.');
        $this->assertTrue(
            $consignor->wa_opt_in_at->between($before, now()->addSecond()),
            'Waktu yang disimpan harus waktu centangnya, bukan waktu yang tidak jelas.',
        );
    }

    #[Test]
    public function leaving_the_box_unticked_records_no_consent(): void
    {
        $this->post('/master/penitip', [
            'name' => 'Budi Santoso',
            'wa_number' => '+62 812-3456-7890',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Consignor::sole()->wa_opt_in_at);
    }

    #[Test]
    public function an_opt_in_with_no_number_to_send_to_is_refused(): void
    {
        // Persetujuan tanpa tujuan bukan persetujuan yang bisa dijalankan. Kolom
        // nomor biasanya opsional, jadi tanpa aturan ini centang tanpa nomor akan
        // tersimpan sebagai persetujuan yang tidak bisa dipakai.
        $this->post('/master/penitip', [
            'name' => 'Budi Santoso',
            'wa_opt_in' => '1',
            'status' => 'ACTIVE',
        ])->assertSessionHasErrors('wa_number');

        $this->assertSame(0, Consignor::count());
    }

    #[Test]
    public function unticking_the_box_withdraws_a_consent_that_was_recorded(): void
    {
        $consignor = $this->createConsignor(['wa_number' => '6281234567890', 'wa_opt_in_at' => now()]);

        // Mencabut persetujuan adalah perubahan pada record penitip, jadi lewat
        // jalur edit -- dan jalur edit adalah aksi Owner. Staff masih boleh
        // mencatat persetujuan saat menerima barang, tapi tidak boleh
        // mengubahnya lagi setelah itu.
        $this->actingAs(User::factory()->owner()->create());

        $this->put('/master/penitip/'.$consignor->id, [
            'name' => 'Budi Santoso',
            'wa_number' => '6281234567890',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $this->assertNull(
            $consignor->fresh()->wa_opt_in_at,
            'Kotak yang dikosongkan harus mencabut persetujuan, bukan membiarkannya.',
        );
    }

    #[Test]
    public function staff_may_record_the_consent_while_handling_the_goods(): void
    {
        // Staff yang menerima barang dari penitip adalah orang yang bertemu
        // consenting langsung. Kalau hanya Owner boleh, persetujuan hanya bisa
        // dicatat setelah barang diterima, yaitu setelah pesanannya dikirim.
        $this->post('/master/penitip', [
            'name' => 'Budi Santoso',
            'wa_number' => '+62 812-3456-7890',
            'wa_opt_in' => '1',
            'status' => 'ACTIVE',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Consignor::sole()->wa_opt_in_at);
    }
}
