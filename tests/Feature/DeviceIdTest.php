<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\User;
use App\Support\DeviceId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Identitas perangkat yang diambil sendiri oleh {@see DeviceId}.
 *
 * Sepanjang sejarahnya kolom `device_id` selalu `null`: tidak ada yang mengisi
 * session `device_id` dan tidak ada yang mengirim header `X-Device-Id`. Akibatnya
 * bukan sekadar layar yang menampilkan "Tidak terdeteksi" -- aturan "satu shift
 * terbuka per perangkat" ikut menjadi `WHERE device_id IS NULL`, yang menyatukan
 * seluruh tablet di satu toko menjadi satu perangkat tunggal, sehingga kasir
 * kedua di tablet kedua tidak pernah bisa membuka shift.
 *
 * Karena itu yang diuji di sini bukan "apakah cookie-nya terkirim", melainkan
 * empat hal yang dipakai aplikasi untuk bekerja:
 *
 *  1. Penjualan dan shift yang tercatat membawa identitas perangkat yang benar,
 *     tanpa siapa pun perlu mengetik apa pun.
 *  2. Identitas itu bertahan dari request ke request -- cookie yang dibawa
 *     perangkat dikenali, bukan diberi identitas baru setiap kali.
 *  3. Urutan pembacaan: session, lalu header, lalu otomatis. Sumber yang lebih
 *     eksplisit tidak boleh dikalahkan oleh sumber yang ditebak.
 *  4. Perangkat yang benar-benar tidak bisa dikenali tetap `null`, bukan nilai
 *     kosong yang akan menyatukannya dengan perangkat lain.
 */
class DeviceIdTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chrome di macOS: label yang diharapkan `CHR-MAC`.
     */
    private const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    /**
     * Firefox di Android: label yang diharapkan `FF-AND`. Dipakai untuk
     * memastikan dua perangkat tidak pernah berbagi satu identitas.
     */
    private const FIREFOX_ANDROID = 'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0';

    private const PATTERN = '/^[A-Z]{2,4}-[A-Z]{3}-[0-9a-f]{8}$/';

    private function cashier(): User
    {
        return User::factory()->staff()->create();
    }

    /**
     * Satu baris keranjang, dalam bentuk yang dikirim layar kasir.
     *
     * @return array<string, mixed>
     */
    private function line(StockLot $lot, int $qty): array
    {
        return [
            'lot_id' => $lot->getKey(),
            'qty' => $qty,
            'input_method' => 'SCAN',
        ];
    }

    #[Test]
    public function it_stamps_the_device_on_a_sale_no_one_had_to_name(): void
    {
        $cashier = $this->cashier();
        Shift::factory()->forUser($cashier)->create();
        $lot = StockLot::factory()->qty(3)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)
            ->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->postJson('/pos/transaksi', [
                'client_sale_id' => 'cs-device-from-ua',
                'items' => [$this->line($lot, 1)],
                'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
                'tender' => 50_000,
            ])
            ->assertOk();

        $sale = Sale::sole();

        $this->assertMatchesRegularExpression(self::PATTERN, (string) $sale->device_id);
        $this->assertStringStartsWith('CHR-MAC-', (string) $sale->device_id);
    }

    #[Test]
    public function it_stamps_the_device_on_the_shift_it_opens(): void
    {
        $this->actingAs($this->cashier())
            ->withHeaders(['User-Agent' => self::FIREFOX_ANDROID])
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000])
            ->assertRedirect(route('pos.kasir'));

        $this->assertMatchesRegularExpression(self::PATTERN, (string) Shift::sole()->device_id);
        $this->assertStringStartsWith('FF-AND-', (string) Shift::sole()->device_id);
    }

    #[Test]
    public function it_remembers_a_device_it_has_seen_by_the_cookie_it_gave(): void
    {
        $first = $this->cashier();

        $this->actingAs($first)
            ->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000])
            ->assertRedirect(route('pos.kasir'));

        $remembered = (string) Shift::sole()->device_id;

        // Kasir kedua membawa cookie yang sama. Identitasnya sama, jadi shift
        // keduanya ditolak oleh aturan "satu perangkat satu shift terbuka" --
        // bukan diberi identitas baru yang membuatnya lolos.
        $this->actingAs($this->cashier())
            ->from(route('pos.shift-kasir'))
            ->withCookie(DeviceId::COOKIE, $remembered)
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 50_000])
            ->assertRedirect(route('pos.shift-kasir'));

        $this->assertCount(1, Shift::all());
    }

    #[Test]
    public function it_gives_a_carried_cookie_no_new_identity_of_its_own(): void
    {
        $this->actingAs($this->cashier())
            ->withCookie(DeviceId::COOKIE, 'CHR-AND-3f9a2b71')
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000])
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame('CHR-AND-3f9a2b71', Shift::sole()->device_id);
    }

    #[Test]
    public function it_lets_the_session_speak_first(): void
    {
        $this->actingAs($this->cashier())
            ->withSession([DeviceId::SESSION_KEY => 'POS-TOKO-1'])
            ->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000])
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame('POS-TOKO-1', Shift::sole()->device_id);
    }

    #[Test]
    public function it_lets_an_explicit_header_speak_before_the_automatic_identity(): void
    {
        $this->actingAs($this->cashier())
            ->withCookie(DeviceId::COOKIE, 'CHR-AND-3f9a2b71')
            ->withHeaders([
                'User-Agent' => self::CHROME_MAC,
                'X-Device-Id' => 'POS-TEST-01',
            ])
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000])
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame('POS-TEST-01', Shift::sole()->device_id);
    }

    #[Test]
    public function it_stays_unknown_when_the_browser_says_nothing(): void
    {
        $response = $this->actingAs($this->cashier())
            ->withHeaders(['User-Agent' => ''])
            ->post('/pos/shift-kasir/buka', ['opening_cash' => 100_000]);

        $response->assertRedirect(route('pos.kasir'));

        $this->assertNull(Shift::sole()->device_id, 'Perangkat yang tak bisa dikenali harus tetap null, bukan string kosong.');
        $this->assertNull($response->getCookie(DeviceId::COOKIE), 'Tidak ada identitas yang layak diingat, jadi tidak ada cookie.');
    }

    #[Test]
    public function it_gives_two_different_browsers_two_different_identities(): void
    {
        $cashier = $this->cashier();

        $chrome = $this->actingAs($cashier)
            ->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->get('/pos/shift-kasir')
            ->getCookie(DeviceId::COOKIE);

        $firefox = $this->actingAs($cashier)
            ->withHeaders(['User-Agent' => self::FIREFOX_ANDROID])
            ->get('/pos/shift-kasir')
            ->getCookie(DeviceId::COOKIE);

        $this->assertNotNull($chrome, 'Peramban dengan User-Agent harus langsung diberi identitas.');
        $this->assertNotNull($firefox);
        $this->assertNotSame($chrome->getValue(), $firefox->getValue());
        $this->assertMatchesRegularExpression('/^CHR-MAC-[0-9a-f]{8}$/', (string) $chrome->getValue());
        $this->assertMatchesRegularExpression('/^FF-AND-[0-9a-f]{8}$/', (string) $firefox->getValue());
    }

    #[Test]
    public function it_ignores_a_cookie_that_was_never_ours(): void
    {
        $response = $this->actingAs($this->cashier())
            ->withCookie(DeviceId::COOKIE, 'cookie-milik-aplikasi-lain')
            ->withHeaders(['User-Agent' => self::CHROME_MAC])
            ->get('/pos/shift-kasir');

        $issued = $response->getCookie(DeviceId::COOKIE);

        $this->assertNotNull($issued, 'Cookie yang bukan milik kita harus diganti, bukan diadopsi.');
        $this->assertNotSame('cookie-milik-aplikasi-lain', $issued->getValue());
        $this->assertMatchesRegularExpression(self::PATTERN, (string) $issued->getValue());
    }
}
