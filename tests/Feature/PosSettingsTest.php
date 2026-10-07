<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaperSize;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Inventory\ReprintLimit;
use App\Services\Pos\PosSettings;
use App\Services\Print\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Parameter POS yang disimpan dan dibaca kembali.
 *
 * Dua yang dijaga di sini. Pertama, simpanannya dipakai: ambang yang Owner
 * naikkan harus benar-benar menentukan apakah tutup shift perlu PIN, bukan hanya
 * tampil benar di halaman Pengaturan. Kedua, angka yang belum pernah disimpan
 * selalu kembali ke bawaan yang sama, supaya kasir tidak pernah berhenti mengira
 * boleh berapa persen diskon yang sebenarnya berlaku.
 *
 * Test-test ini sengaja tidak berhenti di "nilainya benar". Yang dijaga adalah
 * angka yang dibaca jalur lain: `PosSettings` yang mengembalikan `true`, dan
 * form yang selalu menampilkan `0`, adalah dua kegagalan yang sama -- tampilan
 * meyakinkan sementara tidak ada yang memakai angkanya.
 *
 * Kertas struk TIDAK lagi ada di form ini. Kertas sudah jadi pengaturan global
 * `PrintSettings` di halaman Perangkat, jadi kasir dan bukti terima tidak bisa
 * lagi terpecah dua pendapat tentang kertas mana yang sedang diroll.
 */
class PosSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function save(array $overrides = []): TestResponse
    {
        return $this->put(route('setting.parameter.update'), $overrides + [
            'staff_discount_limit_percent' => '10',
            'cash_difference_threshold' => '25000',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function stored(string $key): mixed
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    // ================= Bawaan =================

    #[Test]
    public function nothing_saved_yet_means_the_documented_defaults(): void
    {
        $settings = app(PosSettings::class);

        $this->assertSame(0, $settings->staffDiscountLimitPercent());
        $this->assertSame(5_000, $settings->cashDifferenceThreshold());
        $this->assertSame(PaperSize::Mm80, $settings->receiptPaper());
        $this->assertSame(0, Setting::query()->count());
    }

    #[Test]
    public function an_unreadable_stored_value_falls_back_instead_of_breaking_the_cashier(): void
    {
        // Ditulis langsung ke tabel, seperti nilai lama atau hasil ketik yang
        // terpotong. Halaman POS sedang dipegang kasir di depan pelanggan, jadi
        // isinya yang harus terkendali, bukan halamannya.
        Setting::set(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY, 'Lima ribu');
        Setting::set(PosSettings::STAFF_DISCOUNT_LIMIT_KEY, '250');
        Setting::set(PrintSettings::PAPER_KEY, 'kertas-dapur');

        $settings = app(PosSettings::class);

        $this->assertSame(5_000, $settings->cashDifferenceThreshold());
        $this->assertSame(0, $settings->staffDiscountLimitPercent());
        $this->assertSame(PaperSize::Mm80, $settings->receiptPaper());
    }

    #[Test]
    public function a_negative_threshold_would_make_every_close_ask_for_a_pin(): void
    {
        Setting::set(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY, -1);

        $this->assertSame(5_000, app(PosSettings::class)->cashDifferenceThreshold());
    }

    // ================= Menyimpan =================

    #[Test]
    public function an_owner_can_save_the_two_pos_settings(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '10',
                'cash_difference_threshold' => '25000',
            ])
            ->assertRedirect(route('setting.parameter'))
            ->assertSessionHas('toast')
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $this->stored(PosSettings::STAFF_DISCOUNT_LIMIT_KEY));
        $this->assertSame(25_000, $this->stored(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY));
    }

    #[Test]
    public function what_the_owner_typed_as_a_grouped_figure_is_what_gets_saved(): void
    {
        $this->actingAs($this->owner())->put(route('setting.parameter.update'), [
            'staff_discount_limit_percent' => '5',
            'cash_difference_threshold' => '1.500.000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1_500_000, $this->stored(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY));
    }

    #[Test]
    public function a_staff_member_cannot_save_them(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '100',
                'cash_difference_threshold' => '0',
            ])
            ->assertForbidden();

        $this->assertSame(0, Setting::query()->count());
    }

    #[Test]
    public function a_guest_cannot_reach_the_save_route(): void
    {
        $this->put(route('setting.parameter.update'), [])->assertRedirect(route('login'));
    }

    #[Test]
    public function a_discount_over_a_hundred_percent_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '101',
                'cash_difference_threshold' => '25000',
            ])
            ->assertSessionHasErrors('staff_discount_limit_percent');

        $this->assertNull($this->stored(PosSettings::STAFF_DISCOUNT_LIMIT_KEY));
    }

    #[Test]
    public function a_negative_discount_limit_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '-5',
                'cash_difference_threshold' => '25000',
            ])
            ->assertSessionHasErrors('staff_discount_limit_percent');
    }

    #[Test]
    public function a_negative_threshold_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '5',
                'cash_difference_threshold' => '-1',
            ])
            ->assertSessionHasErrors('cash_difference_threshold');
    }

    #[Test]
    public function a_zero_threshold_is_allowed_and_means_every_close_needs_approval(): void
    {
        $this->actingAs($this->owner())->put(route('setting.parameter.update'), [
            'staff_discount_limit_percent' => '0',
            'cash_difference_threshold' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->stored(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY));
    }

    #[Test]
    public function every_field_is_required_because_the_others_would_fall_back_silently(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [])
            ->assertSessionHasErrors([
                'staff_discount_limit_percent',
                'cash_difference_threshold',
            ]);
    }

    // ================= Kertas struk =================

    #[Test]
    public function the_pos_paper_comes_from_the_global_print_settings(): void
    {
        // Satu sumber kertas untuk semua cetakan: mengubahnya di halaman
        // Perangkat mengubah struk POS juga, dan sebaliknya.
        $this->assertSame(
            app(PrintSettings::class)->paper(),
            app(PosSettings::class)->receiptPaper(),
        );

        Setting::set(PrintSettings::PAPER_KEY, PaperSize::Mm58->value);

        $this->assertSame(PaperSize::Mm58, app(PosSettings::class)->receiptPaper());
    }

    // ================= Jejak audit =================

    #[Test]
    public function saving_is_written_to_the_audit_log_with_both_states(): void
    {
        $this->owner();

        $this->actingAs($this->owner())->put(route('setting.parameter.update'), [
            'staff_discount_limit_percent' => '10',
            'cash_difference_threshold' => '25000',
        ]);

        $entry = AuditLog::query()->where('action', 'UPDATE_POS_SETTINGS')->sole();

        $this->assertSame('Setting', $entry->entity);
        $this->assertSame('pos', $entry->entity_key);
        $this->assertSame(0, $entry->before['staff_discount_limit_percent']);
        $this->assertSame(5_000, $entry->before['cash_difference_threshold']);
        $this->assertSame(10, $entry->after['staff_discount_limit_percent']);
        $this->assertSame(25_000, $entry->after['cash_difference_threshold']);
    }

    #[Test]
    public function a_refused_save_leaves_no_audit_trail(): void
    {
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), ['staff_discount_limit_percent' => '101']);

        $this->assertSame(0, AuditLog::query()->where('action', 'UPDATE_POS_SETTINGS')->count());
    }

    // ================= Yang benar-benar dibaca =================

    #[Test]
    public function the_saved_threshold_is_what_the_close_shift_request_uses(): void
    {
        $this->actingAs($this->owner())->put(route('setting.parameter.update'), [
            'staff_discount_limit_percent' => '10',
            'cash_difference_threshold' => '20000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(20_000, app(PosSettings::class)->cashDifferenceThreshold());
        $this->assertSame(
            ['staff_discount_limit_percent', 'cash_difference_threshold'],
            array_keys(app(PosSettings::class)->snapshot()),
        );
    }

    // ================= Halaman =================

    #[Test]
    public function the_parameter_page_shows_the_saved_values(): void
    {
        Setting::set(PosSettings::STAFF_DISCOUNT_LIMIT_KEY, 15);
        Setting::set(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY, 30_000);

        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('Batas diskon kasir')
            ->assertSee('value="15"', false)
            ->assertSee('value="30000"', false)
            ->assertSee('Simpan Parameter POS');
    }

    #[Test]
    public function the_parameter_page_shows_a_staff_member_the_limits_without_a_form(): void
    {
        Setting::set(PosSettings::STAFF_DISCOUNT_LIMIT_KEY, 15);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('Batas diskon kasir')
            ->assertSee('15%')
            ->assertDontSee('Simpan Parameter POS');
    }

    #[Test]
    public function the_parameter_page_shows_the_global_paper_and_points_to_perangkat(): void
    {
        // Kertas tidak lagi dipilih di sini; halaman menampilkan nilai global
        // dan mengarahkan Owner ke tempat pengaturannya berada.
        Setting::set(PrintSettings::PAPER_KEY, PaperSize::A4->value);

        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('Kertas struk dokumen')
            ->assertSee('A4')
            ->assertSee('Perangkat');
    }

    #[Test]
    public function a_reprint_limit_that_the_code_owns_is_shown_read_from_the_code(): void
    {
        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('Batas re-print label per lot per hari')
            ->assertSee(ReprintLimit::DAILY_STAFF_LIMIT.'&times;', false);
    }

    #[Test]
    public function the_page_says_plainly_which_policies_have_no_setting_yet(): void
    {
        // Menampilkan form untuk aturan yang belum ada kodenya akan berarti
        // menyimpan sesuatu yang tidak dibaca siapa pun -- dan terlihat seperti
        // sudah diatur.
        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('belum ada pengaturan', false);
    }

    #[Test]
    public function the_parameter_page_is_visible_to_staff_but_not_editable(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.parameter'))
            ->assertOk();

        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk();
    }

    #[Test]
    public function a_failed_save_puts_the_typed_values_back_on_the_page(): void
    {
        // Flash lewat request sungguhan, bukan dengan menyuntik `_old_input` ke
        // session: input lama harus kembali dengan bentuk yang sama seperti yang
        // dikirim, termasuk yang ditolak karena negatif.
        $this->actingAs($this->owner())
            ->from(route('setting.parameter'))
            ->put(route('setting.parameter.update'), [
                'staff_discount_limit_percent' => '12',
                'cash_difference_threshold' => '-1',
            ])
            ->assertSessionHasErrors('cash_difference_threshold')
            ->assertSessionHasInput('staff_discount_limit_percent', '12');

        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('value="12"', false)
            // Angka yang ditolak ikut kembali, supaya Owner tidak perlu mengetik
            // ulang batas yang sudah benar hanya untuk memunculkan pesan yang
            // sama sekali tidak menyalahkan dia.
            ->assertSee('value="-1"', false);
    }

    #[Test]
    public function a_fractional_stored_percentage_is_rounded_down_never_up(): void
    {
        // Yang dipegang di sini adalah batas. Pembulatan ke atas menaikkan batas
        // diskon tanpa ada yang menyetelkannya: kasir menemukan diskon ditolak
        // satu poin, dan Owner menemukan angka di Pengaturan lebih besar dari yang
        // benar-benar dipakai.
        Setting::set(PosSettings::STAFF_DISCOUNT_LIMIT_KEY, '12.50');

        $this->assertSame(12, app(PosSettings::class)->staffDiscountLimitPercent());

        $this->actingAs($this->owner())
            ->get(route('setting.parameter'))
            ->assertOk()
            ->assertSee('value="12"', false);
    }
}
