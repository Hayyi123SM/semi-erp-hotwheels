<?php

namespace Tests\Unit\Audit;

use App\Support\Audit\AuditDiff;
use App\Support\Audit\AuditFieldChange;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bentuk diff, diuji tanpa database.
 *
 * Yang paling mudah rusak di sini bukan salah hitung, tapi salah baca bentuk:
 * `before` dan `after` tidak pernah berjanji simetris, dan `json` native MySQL
 * menyusun ulang kunci objek sementara SQLite menyimpan teksnya apa adanya.
 * Kalau diff mengembalikan hasilnya apa adanya, urutan key itu ikut menentukan
 * baris mana yang muncul lebih dulu di panel perincian -- jadi pengurutan diuji
 * di sini sebagai keputusan, bukan sebagai kebetulan yang bisa berubah
 * diam-diam saat mesinnya diganti.
 */
class AuditDiffTest extends TestCase
{
    #[Test]
    public function it_pairs_both_sides_field_by_field(): void
    {
        $changes = AuditDiff::between(
            ['default_template' => '3x2', 'qr_side_cm' => '1.50'],
            ['default_template' => '4x3', 'qr_side_cm' => '1.50'],
        );

        $this->assertCount(1, $changes);
        $this->assertSame('default_template', $changes[0]->field);
        $this->assertSame('3x2', $changes[0]->before);
        $this->assertSame('4x3', $changes[0]->after);
    }

    #[Test]
    public function it_drops_fields_that_did_not_move(): void
    {
        $changes = AuditDiff::between(
            ['name' => 'Hot Wheels', 'status' => 'SOLD_OUT', 'price' => 150000],
            ['name' => 'Hot Wheels', 'status' => 'SOLD_OUT', 'price' => 160000],
        );

        // Dua dari tiga field tidak berubah. Menampilkan ketiganya membuat
        // baris `CREATED` yang menyimpan seluruh isi baris baru jadi daftar
        // tujuh belas baris yang menutupi satu-satunya perubahan sebenarnya.
        $this->assertSame(['price'], array_map(fn ($c) => $c->field, $changes));
    }

    #[Test]
    public function it_reads_creation_as_a_change_from_nothing(): void
    {
        $changes = AuditDiff::between(null, ['name' => 'Hot Wheels', 'qty' => 2]);

        $this->assertCount(2, $changes);
        $this->assertNull($changes[0]->before);
        $this->assertSame('Hot Wheels', $changes[0]->after);
    }

    #[Test]
    public function it_reads_deletion_as_a_change_to_nothing(): void
    {
        $changes = AuditDiff::between(['name' => 'Hot Wheels'], null);

        $this->assertCount(1, $changes);
        $this->assertSame('Hot Wheels', $changes[0]->before);
        $this->assertNull($changes[0]->after);
    }

    #[Test]
    public function it_pairs_fields_that_only_exist_on_one_side(): void
    {
        $changes = AuditDiff::between(
            ['qr_side_cm' => '1.50', 'default_template' => '3x2'],
            ['default_template' => '4x3'],
        );

        $this->assertSame(
            ['default_template', 'qr_side_cm'],
            array_map(fn (AuditFieldChange $c) => $c->field, $changes),
        );
    }

    /**
     * Angka dan string angka adalah satu nilai untuk laporan ini.
     *
     * `before`/`after` datang dari JSON, dan mesin yang berbeda bisa
     * mengembalikan `1` di satu sisi dan `"1"` di sisi lain untuk kolom yang
     * sama. Perbandingan ketat akan melaporkan perubahan yang tidak terjadi --
     * dan di halaman laporan audit, perubahan palsu yang dipertanyakan lebih
     * merusak kepercayaan daripada perubahan yang luput sebentar.
     */
    #[Test]
    public function it_does_not_invent_a_change_between_one_and_the_string_one(): void
    {
        $this->assertFalse((new AuditFieldChange('qty', 1, '1'))->changed());
        $this->assertFalse((new AuditFieldChange('qty', '150000', 150000))->changed());
        $this->assertTrue((new AuditFieldChange('qty', 1, 2))->changed());
    }

    /**
     * `null`, string kosong, dan array kosong berarti hal yang sama di sini.
     *
     * `before` kosong tidak pernah berarti "nilainya kosong", melainkan
     * "tidak ada sebelum-nya": `AuditLogger` menulis `null` untuk array kosong,
     * dan pengimpor JSON yang berbeda bisa menuliskan `""`. Kalau keduanya
     * dibaca sebagai nilai, setiap baris laporan akan dipenuhi "ubah dari
     * kosong ke kosong".
     */
    #[Test]
    public function it_treats_empty_shapes_as_absence(): void
    {
        $this->assertFalse((new AuditFieldChange('qty', null, ''))->changed());
        $this->assertFalse((new AuditFieldChange('tags', [], null))->changed());
        $this->assertTrue((new AuditFieldChange('qty', null, 0))->changed());
    }

    /**
     * Nilai di dalam struktur dibandingkan persis, bukan longgar.
     *
     * Longgar di dalam array berarti harus menebak field mana yang angka dan
     * mana yang teks, dan tebakan itu salah begitu nama fieldnya berubah.
     * Membandingkan bentuknya apa adanya lebih jujur: kalau `4` dan `"4"`
     * muncul sebagai perubahan, itu memang yang tercatat di `before` dan
     * `after`, dan pembaca boleh memutuskan sendiri artinya.
     */
    #[Test]
    public function it_compares_structures_by_their_content(): void
    {
        $this->assertFalse(
            (new AuditFieldChange('meta', ['lot_id' => 4], ['lot_id' => 4]))->changed(),
        );
        $this->assertTrue(
            (new AuditFieldChange('meta', ['lot_id' => 4], ['lot_id' => 5]))->changed(),
        );
        $this->assertTrue(
            (new AuditFieldChange('meta', ['lot_id' => 4], ['lot_id' => '4']))->changed(),
        );
    }

    /**
     * Urutan fieldnya diputuskan di sini, bukan diserahkan ke kunci JSON.
     *
     * MySQL menyimpan `json` sebagai tipe native yang menyusun ulang kunci
     * objek, jadi urutan yang bergantung pada database akan berbeda antara
     * mesin dan dapat berubah tanpa ada yang mengubah kodenya.
     */
    #[Test]
    public function it_orders_fields_alphabetically(): void
    {
        $changes = AuditDiff::between(
            ['zeta' => 1, 'alpha' => 1, 'mid' => 1],
            ['zeta' => 2, 'alpha' => 2, 'mid' => 2],
        );

        $this->assertSame(
            ['alpha', 'mid', 'zeta'],
            array_map(fn (AuditFieldChange $c) => $c->field, $changes),
        );
    }

    #[Test]
    public function it_says_so_when_there_is_nothing_to_show(): void
    {
        $this->assertSame([], AuditDiff::between(null, null));
        $this->assertSame([], AuditDiff::between(['name' => 'Sama'], ['name' => 'Sama']));
        $this->assertSame('Tidak ada perubahan field', AuditDiff::summary([]));
    }

    #[Test]
    public function the_summary_leads_with_the_first_field_and_counts_the_rest(): void
    {
        $summary = AuditDiff::summary(AuditDiff::between(
            ['alpha' => 1, 'mid' => 1],
            ['alpha' => 2, 'mid' => 2],
        ));

        $this->assertSame('alpha: 1 -> 2 (+1 perubahan)', $summary);
    }

    #[Test]
    public function the_summary_of_one_change_carries_no_extra_count(): void
    {
        $summary = AuditDiff::summary(AuditDiff::between(['alpha' => 1], ['alpha' => 2]));

        $this->assertSame('alpha: 1 -> 2', $summary);
    }

    /**
     * Nilai kosong tampil sebagai "tidak ada", bukan sebagai sel kosong.
     *
     * Panel perincian menaruh sebelum dan sesudah berdampingan. Sel kosong di
     * sisi "Sebelum" berarti "tidak ada nilai sebelumnya", dan itu informasi;
     * sel kosong tanpa tanda berarti sesuatu belum selesai diisi.
     */
    #[Test]
    public function it_renders_missing_values_as_the_empty_mark(): void
    {
        $change = new AuditFieldChange('qty', null, 2);

        $this->assertSame('—', $change->display($change->before));
        $this->assertSame('2', $change->display($change->after));
        $this->assertSame('Ya', $change->display(true));
        $this->assertSame('Tidak', $change->display(false));
        $this->assertSame('—', $change->display([]));
    }

    #[Test]
    public function it_renders_structures_as_readable_json(): void
    {
        $change = new AuditFieldChange('meta', null, ['lot_id' => 4]);

        $this->assertSame('{"lot_id":4}', $change->display(['lot_id' => 4]));
        $this->assertStringNotContainsString('\/', $change->display(['path' => 'a/b']));
        $this->assertStringContainsString('a/b', $change->display(['path' => 'a/b']));
    }
}
