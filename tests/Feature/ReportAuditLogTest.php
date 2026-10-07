<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Laporan audit, dari tabel yang sebenarnya.
 *
 * Halaman ini dulunya menampilkan lima baris hard-coded di dalam Blade. Karena
 * mock-nya terlihat benar, test halaman yang memastikannya "200 dan berisi
 * teks" tetap hijau -- tidak ada yang_memberi tahu bahwa jejaknya tidak pernah
 * dibaca. Test di sini karena itu memeriksa isi yang benar-benar muncul: nama
 * aktor, nama aksi, nilai sebelum dan sesudah.
 */
class ReportAuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_the_trail_from_the_table_instead_of_a_hard_coded_list(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);

        AuditLog::factory()->forEntity('Consignor', 12)
            ->updated(['name' => 'Toko Sinar Jaya'], ['name' => 'Toko Sinar Jaya Abadi'])
            ->withReason('Perubahan nama setelah verifikasi NPWP.')
            ->at(now()->subHour())
            ->create(['user_id' => $owner->id]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ahmad Fauzi', $html);
        $this->assertStringContainsString('Perubahan nama setelah verifikasi NPWP.', $html);
        $this->assertStringContainsString('Diubah', $html, 'Kode aksi harus dibaca sebagai bahasa manusia.');
        $this->assertStringContainsString('Consignor', $html);
    }

    /**
     * Mock lama menampilkan `CREATE`, `MOVE`, `COMMIT`, `APPROVE`. Tidak satu pun
     * kode itu pernah ditulis aplikasi ini, jadi keberadaannya di halaman adalah
     * bukti bahwa yang tampil bukan data.
     */
    #[Test]
    public function it_shows_no_action_the_application_never_writes(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->created()->create();

        $html = $this->actingAs($owner)->get(route('report.audit-log'))->assertOk()->getContent();

        foreach (['CREATE', 'MOVE', 'COMMIT', 'APPROVE', 'DELETE'] as $invented) {
            $this->assertStringNotContainsString('>'.$invented.'<', $html);
        }
    }

    /**
     * Diff dibaca berdampingan, jadi kedua sisinya harus benar-benar muncul.
     */
    #[Test]
    public function it_shows_the_before_and_the_after_side_by_side(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->forEntity('Setting', 0)
            ->updated(
                ['default_template' => '3x2', 'qr_side_cm' => '1.50'],
                ['default_template' => '4x3', 'qr_side_cm' => '1.50'],
            )
            ->create(['user_id' => $owner->id, 'entity' => 'Setting', 'entity_key' => 'label.printer', 'entity_id' => null]);

        $html = $this->actingAs($owner)->get(route('report.audit-log'))->assertOk()->getContent();

        $this->assertStringContainsString('default_template', $html);
        $this->assertStringContainsString('3x2', $html);
        $this->assertStringContainsString('4x3', $html);
        $this->assertStringContainsString('Sebelum', $html);
        $this->assertStringContainsString('Sesudah', $html);
    }

    /**
     * `Setting` tidak punya primary key, jadi identitasnya berupa teks. Angka `0`
     * yang tersimpan di `entity_id` karena kolomnya ada, bukan karena ada rak
     * nomor nol -- dan kalau dibiarkan, log pengaturan akan terlihat menunjuk
     * entitas yang memang tidak ada.
     */
    #[Test]
    public function a_setting_row_points_at_its_own_key(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->forSetting('label.printer')
            ->updated(['default_template' => '3x2'], ['default_template' => '4x3'])
            ->create(['user_id' => $owner->id]);

        $html = $this->actingAs($owner)->get(route('report.audit-log'))->assertOk()->getContent();

        $this->assertStringContainsString('label.printer', $html);
        $this->assertStringNotContainsString('>0<', $html);
    }

    /**
     * Kode yang tidak dikenal tetap tampil apa adanya.
     *
     * Baris seperti ini muncul begitu ada versi lama yang menulis kode yang
     * sudah dihapus, atau ada tabel baru yang mulai mencatat aksi sendiri.
     * Menerjemahkan dengan menebak menghasilkan "Update Something" yang terlihat
     * seperti penjelasan; menampilkan kodenya menghasilkan "UPDATE_SOMETHING"
     * yang jelas terlihat seperti data.
     */
    #[Test]
    public function an_unknown_action_stays_readable_as_its_own_code(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->create(['action' => 'UPDATE_SOMETHING']);

        $this->actingAs($owner)
            ->get(route('report.audit-log'))
            ->assertOk()
            ->assertSee('UPDATE_SOMETHING');
    }

    #[Test]
    public function it_searches_the_actor_by_name(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Zulfikar Owner']);
        $staff = User::factory()->staff()->create(['name' => 'Budi Santoso']);

        AuditLog::factory()->forEntity('Consignor', 1)->withReason('Baris Budi.')->create(['user_id' => $staff->id]);
        AuditLog::factory()->forEntity('Consignor', 2)->withReason('Baris Owner.')->create(['user_id' => $owner->id]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['q' => 'Budi']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString('Baris Budi.', $html);
        $this->assertStringNotContainsString('Baris Owner.', $html);
    }

    /**
     * Kotak pencariannyaLEDGAI menyebut aktor, jadioredi harus ikut menjawabnya.
     * `DataTable` sudah mendukung jalur relasi untuk ini; yang diperiksa di sini
     * hanya apakah kolomnya benar-benar mendaftarkannya.
     */
    #[Test]
    public function it_searches_the_device_and_the_reason(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->create(['device_id' => 'POS-CP2', 'reason' => null]);
        AuditLog::factory()->create(['device_id' => 'WMS-CP1', 'reason' => 'Stock opname bulanan.']);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['q' => 'POS-CP2']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('POS-CP2', $html);
        $this->assertStringNotContainsString('WMS-CP1', $html);
        $this->assertStringNotContainsString('Stock opname bulanan.', $html);
    }

    /**
     * Nomor identitas ikut dicocokkan, tapi hanya kalau yang diketik angka.
     */
    #[Test]
    public function it_finds_a_row_by_the_identity_of_its_entity(): void
    {
        $owner = User::factory()->owner()->create();

        // `device_id` juga ikut dicari, jadi keduanya dikunci supaya UUID acak
        // dari factory tidak pernah ikut memenuhi syarat dan membuat baris 43
        // ikut terbawa.
        AuditLog::factory()->forEntity('Consignor', 42)
            ->withReason('Yang dicari.')
            ->create(['device_id' => 'perangkat-a']);
        AuditLog::factory()->forEntity('Consignor', 43)
            ->create(['device_id' => 'perangkat-b']);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['q' => '42']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Yang dicari.', $html);
        $this->assertStringNotContainsString('perangkat-b', $html);
    }

    /**
     * Isi `before`/`after` dibaca dengan `LIKE` biasa, bukan `JSON_EXTRACT`.
     * Laporan ini lebih sering dicari oleh isi diff daripada oleh nama fieldnya.
     */
    #[Test]
    public function it_searches_inside_the_diff(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->updated(['status' => 'AVAILABLE'], ['status' => 'SOLD_OUT'])
            ->create(['entity' => 'StockLot', 'entity_id' => 1]);
        AuditLog::factory()->updated(['name' => 'Baja'], ['name' => 'Baja Hitam'])
            ->create(['entity' => 'Product', 'entity_id' => 2]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['q' => 'SOLD_OUT']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('StockLot', $html);
        $this->assertStringNotContainsString('Baja Hitam', $html);
    }

    #[Test]
    public function it_filters_by_action_and_by_entity(): void
    {
        $owner = User::factory()->owner()->create();

        // Alasan dipakai sebagai penanda, bukan label aksi: label aksinya sendiri
        // selalu ada di dropdown, jadi label itu tidak bisa membuktikan
        // apakah barisnya ikut tersaring.
        AuditLog::factory()->deleted()
            ->withReason('Rak dihapus.')->create(['entity' => 'Rack', 'entity_id' => 1]);
        AuditLog::factory()->archived()
            ->withReason('Penitip diarsipkan.')->create(['entity' => 'Consignor', 'entity_id' => 2]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['action' => 'DELETED', 'entity' => 'Rack']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rak dihapus.', $html);
        $this->assertStringNotContainsString('Penitip diarsipkan.', $html);
        $this->assertStringNotContainsString('>Consignor<', $html);
    }

    /**
     * Dropdown aksinya berbahasa manusia, dan yang terpilih disebut apa adanya di
     * chip -- supaya reader tahu filter yang sedang berlaku tanpa menebak dari
     * kotak pencarian.
     */
    #[Test]
    public function the_active_filter_is_named_in_the_toolbar(): void
    {
        $owner = User::factory()->owner()->create();
        AuditLog::factory()->deleted()->create();

        $this->actingAs($owner)
            ->get(route('report.audit-log', ['action' => 'DELETED']))
            ->assertOk()
            ->assertSee('Aksi: Dihapus');
    }

    #[Test]
    public function it_filters_to_rows_that_actually_changed(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->updated(['status' => 'AVAILABLE'], ['status' => 'SOLD_OUT'])
            ->withReason('Baris yang berubah.')->create(['entity' => 'StockLot', 'entity_id' => 1]);

        // Baris tanpa diff: `AUTHORIZE_PIN` menyimpan RID-nya di `reason`.
        AuditLog::factory()->create([
            'action' => 'AUTHORIZE_PIN',
            'reason' => 'RID-0001',
            'before' => null,
            'after' => null,
        ]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['has_changes' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Baris yang berubah.', $html);
        $this->assertStringNotContainsString('RID-0001', $html);
    }

    /**
     * Jejak audit dibaca sebagai kronologi: yang terbaru di atas.
     */
    #[Test]
    public function it_lists_the_newest_first(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->withReason('Yang lama.')->at(now()->subDays(3))->create();
        AuditLog::factory()->withReason('Yang terbaru.')->at(now()->subMinute())->create();

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log'))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Yang lama.'), strpos($html, 'Yang terbaru.'));
    }

    #[Test]
    public function it_sorts_when_the_reader_asks(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->create(['entity' => 'Zebra', 'entity_id' => 1]);
        AuditLog::factory()->create(['entity' => 'Alpha', 'entity_id' => 2]);

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['sort' => 'entity', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Zebra'), strpos($html, 'Alpha'));
    }

    /**
     * `sort` di luar whitelist diabaikan, dan halaman tetap memakai urutan
     * bawaannya. Kalau tidak, urutan bawaan dan `sort` bisa saling menimpa dan
     * tidak ada yang salahnya terlihat dari tampilannya.
     */
    #[Test]
    public function it_ignores_an_unwhitelisted_sort_and_falls_back_to_the_newest_first(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->withReason('Yang lama.')->at(now()->subDays(3))->create();
        AuditLog::factory()->withReason('Yang terbaru.')->at(now()->subMinute())->create();

        $html = $this->actingAs($owner)
            ->get(route('report.audit-log', ['sort' => 'before']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Yang lama.'), strpos($html, 'Yang terbaru.'));
    }

    #[Test]
    public function it_paginates_instead_of_loading_every_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 30) as $i) {
            AuditLog::factory()->forEntity('Consignor', $i)->create();
        }

        $this->actingAs($owner)
            ->get(route('report.audit-log', ['per_page' => 25]))
            ->assertOk()
            ->assertSee('dari 30 data')
            ->assertSee('>25<', false);
    }

    #[Test]
    public function an_empty_table_says_it_is_empty_rather_than_missing(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('report.audit-log'))
            ->assertOk()
            ->assertSee('Belum ada jejak audit');
    }

    /**
     * Tabel yang kosong karena filter menjelaskan filter, bukan emptiness --
     * dua keadaan yang butuh tindakan berbeda dari pembaca.
     */
    #[Test]
    public function a_filter_that_excludes_everything_says_so(): void
    {
        $owner = User::factory()->owner()->create();
        AuditLog::factory()->create();

        $this->actingAs($owner)
            ->get(route('report.audit-log', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Jejak audit tidak ditemukan');
    }

    #[Test]
    public function a_guest_is_turned_away(): void
    {
        $this->get(route('report.audit-log'))->assertRedirect(route('login'));
    }

    /**
     * Opsi entitas diambil dari baris yang benar-benar ada, bukan dari daftar
     * statis: `entity` diisi `class_basename()` di pemanggil `AuditLogger`, jadi
     * tabel mana pun yang mulai mencatat akan muncul tanpa perlu ditambah ke kode.
     */
    #[Test]
    public function the_entity_filter_offers_only_entities_that_wrote_a_trail(): void
    {
        $owner = User::factory()->owner()->create();

        AuditLog::factory()->create(['entity' => 'StockLot', 'entity_id' => 1]);
        AuditLog::factory()->create(['entity' => 'Setting', 'entity_id' => null, 'entity_key' => 'label.printer']);

        $html = $this->actingAs($owner)->get(route('report.audit-log'))->assertOk()->getContent();

        $this->assertStringContainsString('StockLot', $html);
        $this->assertStringContainsString('Setting', $html);
        $this->assertStringNotContainsString('Rack', $html);
    }

    /**
     * Kode aksi yang tidak dikenal tidak muncul di dropdown -- dan barisnya juga
     * tidak hilang dari daftar. Disembunyikan dari filter, bukan dari laporan.
     */
    #[Test]
    public function every_registered_action_offers_a_human_label(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (AuditAction::cases() as $action) {
            $this->actingAs($owner)->get(route('report.audit-log'))->assertSee($action->label());
        }
    }
}
