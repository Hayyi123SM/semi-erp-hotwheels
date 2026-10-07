<?php

namespace Tests\Mysql;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Notification\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Halaman pengaturan pada mesin yang benar-benar dipakai produksi.
 *
 * Bug yang diuji di sini lolos seluruh suite SQLite dan berhenti sebagai 500 di
 * produksi. Jejak audit dulu mengirim kunci teks `Setting` ke `entity_id`, yang
 * bertipe `unsignedBigInteger`. SQLite tidak menegakkan tipe kolom, jadi
 * seluruh test harian hijau -- sementara MySQL dengan `STRICT_TRANS_TABLES`
 * menolak sisipan itu dengan "Incorrect integer value" dan mengembalikan 500
 * ke Owner.
 *
 * Jadi yang diuji di sini bukan "apakah perilakunya benar" -- itu sudah
 * dilakukan suite SQLite -- tapi "apakah MySQL mau menerimanya sama sekali".
 * Yang diuji adalah kesediaan mesin, bukan perilakunya.
 *
 * Dua halaman diuji karena keduanya menulis `Setting`. Memperbaiki satu tanpa
 * yang lain hanya akan memindahkan 500, bukan menyelesaikannya.
 */
class SettingAuditTest extends MySqlTestCase
{
    /**
     * Kalau suite ini diam-diam jatuh ke SQLite, assertion di bawah tetap hijau
     * sambil tidak membuktikan apa pun. Yang diperiksa adalah mesinnya.
     */
    #[Test]
    public function it_is_actually_talking_to_mysql(): void
    {
        $this->assertSame('mysql', config('database.default'), 'Running on '.$this->engine());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    #[Test]
    public function the_label_printer_settings_can_be_saved(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->put(route('setting.perangkat.label.update'), [
                'default_template' => '4x3',
                'qr_side_cm' => '1.60',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('toast');

        $this->assertSame(
            LabelTemplate::FourByThree->value,
            Setting::get(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'UPDATE_PRINTER_SETTINGS',
            'entity' => 'Setting',
            'entity_key' => 'label.printer',
        ]);
    }

    /**
     * Kolom yang dikosongkan berarti kembali ke bawaan, dan jalur `forget()`
     * ikut lewat sini -- bukan hanya jalur `set()`.
     */
    #[Test]
    public function the_label_printer_settings_can_be_reset_to_the_template_default(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->put(route('setting.perangkat.label.update'), [
                'default_template' => '4x3',
                'qr_side_cm' => '1.60',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put(route('setting.perangkat.label.update'), [
                'default_template' => '3x2',
                'qr_side_cm' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::get(LabelPrinterSettings::QR_SIDE_KEY));

        $this->assertSame(
            2,
            AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')->count(),
            'Kedua simpanan harus sama-sama tercatat.',
        );
    }

    #[Test]
    public function the_whatsapp_template_can_be_saved(): void
    {
        $body = str_replace(
            '{qty} pcs',
            '{qty} buah',
            NotificationTemplate::ConsignmentReceipt->defaultBody(),
        );

        $response = $this->actingAs(User::factory()->owner()->create())
            ->put(route('setting.wa-template.update'), [
                'template' => 'consignment_receipt',
                'body' => $body,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('toast');

        $this->assertSame($body, Setting::get(NotificationTemplate::ConsignmentReceipt->settingKey()));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'UPDATE_WA_TEMPLATE',
            'entity' => 'Setting',
            'entity_key' => NotificationTemplate::ConsignmentReceipt->settingKey(),
        ]);
    }

    /**
     * Jejak audit harus bisa dibaca tanpa menebak bentuknya.
     *
     * `before` dan `after` dibaca berdampingan di laporan, jadi keduanya harus
     * punya field yang sama. Bentuk lama menaruh `from`/`to` bersarang di dalam
     * `after`, jadi pembaca harus tahu halaman mana yang sedang dibuka untuk
     * bisa membandingkan.
     */
    #[Test]
    public function the_audit_trail_is_a_flat_diff(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->put(route('setting.perangkat.label.update'), [
                'default_template' => '4x3',
                'qr_side_cm' => '1.60',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'UPDATE_PRINTER_SETTINGS')->sole();

        // Urutan field tidak ikut diuji. MySQL menyimpan `json` sebagai tipe
        // native yang menyusun ulang kunci objek (lebih pendek dulu, lalu
        // alfabetis), sementara SQLite menyimpan teksnya apa adanya -- jadi
        // urutan yang sama sekali tidak bisa dijadikan klaim. Yang dijaga
        // adalah bahwa kedua sisi punya field yang sama, supaya laporan bisa
        // menampilkannya berdampingan.
        $this->assertEqualsCanonicalizing(
            ['default_template', 'qr_side_cm'],
            array_keys($log->after),
        );
        $this->assertEqualsCanonicalizing(
            array_keys($log->after),
            array_keys($log->before),
            'before dan after harus punya field yang sama supaya berdampingan.',
        );

        // Dan `before` harus berisi keadaan yang benar-benar berlaku, bukan nilai
        // yang baru saja dikirimkan form.
        $this->assertSame(LabelTemplate::ThreeByTwo->value, $log->before['default_template']);
        $this->assertSame(LabelTemplate::FourByThree->value, $log->after['default_template']);
    }

    /**
     * Penolakan otorisasi tidak boleh menyisakan apa pun.
     *
     * Staff tidak boleh menyentuh pengaturan printer. Karena simpanan dan
     * jejaknya satu transaksi, penolakan di tengah jalan tidak boleh
     * meninggalkan baris audit tanpa setelan -- jejak audit yang menunjuk ke
     * perubahan yang tidak pernah terjadi adalah informasi yang menyesatkan.
     */
    #[Test]
    public function a_staff_save_is_refused_and_leaves_no_trace(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->put(route('setting.perangkat.label.update'), [
                'default_template' => '4x3',
                'qr_side_cm' => '1.60',
            ])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::count());
        $this->assertNull(Setting::get(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY));
    }
}
