<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WhatsappNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Bentuk tersimpan dan bentuk tampilan adalah dua hal berbeda, dan aplikasi ini
 * hanya boleh bisa punya satu nomor per penitip.
 *
 * Pengujian ini sengaja murni tanpa database: `WhatsappNumber` adalah nilai
 * murni, dan mengujinya lewat Eloquent hanya menambah satu cara gagal tanpa
 * menambah kepastian apa pun. Aturan unique-nya diuji terpisah di
 * `PenitipWhatsappTest`, di tempat ia benar-benar ditagih.
 */
class WhatsappNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function normalizeCases(): array
    {
        return [
            'bentuk yang disimpan utuh' => ['6281234567890', '6281234567890'],
            'tanda plus' => ['+6281234567890', '6281234567890'],
            'spasi dan tanda hubung' => ['+62 812-3456-7890', '6281234567890'],
            'titik' => ['+62.812.3456.7890', '6281234567890'],
            'kurung' => ['(+62) 812 3456 7890', '6281234567890'],
            'nol di depan adalah awalan lokal' => ['081234567890', '6281234567890'],
            'nol dan tanda hubung' => ['0812-3456-7890', '6281234567890'],
            'kode negara saja' => ['62 812 3456 7890', '6281234567890'],
            'spasi di sekitarnya' => ['   6281234567890   ', '6281234567890'],
            'string kosong jadi null' => ['', null],
            'hanya spasi jadi null' => ['   ', null],
            'null tetap null' => [null, null],
            'tanpa digit jadi null' => ['+62 (abc)', null],
            'sisa yang terlalu pendek ditolak' => ['+62', null],
        ];
    }

    #[Test]
    #[DataProvider('normalizeCases')]
    public function it_normalizes_every_readable_form_to_the_stored_form(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, WhatsappNumber::normalize($input));
    }

    /**
     * Bentuk yang disimpan harus bisa dibaca `wa.me`, dan ini yang tidak boleh
     * dilewati: mutator menormalkan tanpa memvalidasi, jadi format yang salah
     * akan tersimpan rapi lalu gagal saat tautannya dibuat.
     */
    #[Test]
    public function every_stored_form_is_usable_as_a_chat_link(): void
    {
        foreach (self::normalizeCases() as $label => [$input, $stored]) {
            if ($stored === null) {
                continue;
            }

            $this->assertTrue(
                WhatsappNumber::isValid($stored),
                "Bentuk tersimpan dari kasus '{$label}' tidak bisa jadi tujuan wa.me.",
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function displayCases(): array
    {
        return [
            'nol dan spasi' => ['+62 812-3456-7890'],
            'polos' => ['6281234567890'],
            'nol di depan' => ['081234567890'],
        ];
    }

    #[Test]
    #[DataProvider('displayCases')]
    public function it_displays_a_number_the_way_the_form_always_has(string $input): void
    {
        $this->assertSame('+62 812-3456-7890', WhatsappNumber::display($input));
    }

    /**
     * Tampilan harus bisa dibaca kembali jadi nomor yang sama. Kalau tidak, form
     *_edit_ akan menampilkan satu nomor lalu menyimpan nomor yang berbeda begitu
     * Staff tidak menyentuh apa pun.
     */
    #[Test]
    public function a_displayed_number_normalizes_back_to_the_same_number(): void
    {
        foreach (self::displayCases() as $label => [$input]) {
            $this->assertSame(
                WhatsappNumber::normalize($input),
                WhatsappNumber::normalize(WhatsappNumber::display($input)),
                "Nomor yang ditampilkan dari kasus '{$label}' tidak kembali ke nomor semula.",
            );
        }
    }

    #[Test]
    public function it_refuses_numbers_that_cannot_be_asked_for(): void
    {
        $this->assertFalse(WhatsappNumber::isValid(null));
        $this->assertFalse(WhatsappNumber::isValid(''));
        $this->assertFalse(WhatsappNumber::isValid('bukan nomor'));
        // Tidak ada tebakan kode negara: tanpa `0` atau `62` di depan, nomor ini
        // bisa milik siapa saja dan tidak boleh dijadikan tujuan.
        $this->assertFalse(WhatsappNumber::isValid('8123456789'));
        $this->assertFalse(WhatsappNumber::isValid('+1 202 555 0143'));
    }

    #[Test]
    public function a_number_too_long_for_wa_me_is_refused_rather_than_saved(): void
    {
        $this->assertFalse(WhatsappNumber::isValid('62'.str_repeat('1', 14)));
        $this->assertTrue(WhatsappNumber::isValid('62'.str_repeat('1', 13)));
    }

    #[Test]
    public function the_display_of_a_number_too_short_to_group_is_left_alone(): void
    {
        // Memaksakan pola pada angka yang tidak cukup panjang hanya menipu mata,
        // jadi bentuk polosnya dikembalikan apa adanya.
        $this->assertSame('628123', WhatsappNumber::display('628123'));
    }

    #[Test]
    public function it_builds_a_chat_link_that_carries_the_message(): void
    {
        $link = WhatsappNumber::chatLink('+62 812-3456-7890', 'Halo penitip');

        $this->assertIsString($link);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $link);
        $this->assertStringContainsString(rawurlencode('Halo penitip'), $link);
    }

    #[Test]
    public function it_builds_no_link_for_a_number_that_cannot_be_reached(): void
    {
        // Mengembalikan tautan ke nomor yang salah lebih berbahaya daripada
        // tidak memberi tautan sama sekali, jadi hasilnya `null`.
        $this->assertNull(WhatsappNumber::chatLink(null, 'Halo'));
        $this->assertNull(WhatsappNumber::chatLink('', 'Halo'));
        $this->assertNull(WhatsappNumber::chatLink('bukan nomor', 'Halo'));
    }

    /**
     * Sebagian peramban mobile memotong tautan yang terlalu panjang, dan nota
     * penitip yang terpotong separuh membuat Staff salah mengira angka yang
     * hilang memang tidak ada.
     */
    #[Test]
    public function an_over_long_message_is_cut_at_a_line_boundary(): void
    {
        $line = str_repeat('a', 100);
        $message = implode("\n", array_fill(0, 40, $line));
        $link = WhatsappNumber::chatLink('6281234567890', $message);

        $this->assertIsString($link);

        $decoded = rawurldecode(substr($link, (int) strpos($link, '?text=') + 6));

        $this->assertStringEndsWith('…', $decoded);
        $this->assertLessThanOrEqual(1801, mb_strlen($decoded));

        // Tidak boleh memotong di tengah baris: baris terakhir yang ikut
        // terbawa harus utuh, dengan `…` menempel setelahnya -- bukan memotong
        // di tengah. Memotong separuh baris membuat Staff salah mengira angka
        // yang hilang memang tidak ada di nota.
        $lines = explode("\n", $decoded);
        $lastLine = end($lines);

        $this->assertStringEndsWith('…', $lastLine);
        $this->assertSame(101, mb_strlen($lastLine), 'Baris terakhir harus utuh, ditambah tanda pemotongan.');
        $this->assertSame(str_repeat('a', 100), mb_substr($lastLine, 0, 100));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function searchFragmentCases(): array
    {
        return [
            'awalan lokal' => ['08123', '8123'],
            'kode negara' => ['62812', '812'],
            'sudah polos' => ['8123', '8123'],
            'dengan spasi dan hubung' => ['0812-3456', '8123456'],
            'dengan tanda plus' => ['+6281234567890', '81234567890'],
            'seluruh nomor' => ['6281234567890', '81234567890'],
            'terlalu pendek untuk nomor' => ['08', null],
            'bukan nomor' => ['Budi', null],
            'kode saja tidak sisa' => ['62', null],
            'nol saja tidak sisa' => ['0', null],
        ];
    }

    #[Test]
    #[DataProvider('searchFragmentCases')]
    public function a_search_matches_the_number_whatever_prefix_was_typed(string $search, ?string $expected): void
    {
        $this->assertSame($expected, WhatsappNumber::searchFragment($search));
    }

    /**
     * Potongan dari cara mengetik apa pun harus muncul di dalam nomor yang
     * tersimpan. Inilah yang melindungi pencarian dari mati diam-diam begitu
     * penormalisasi masuk: yang tersimpan `6281234567890`, sementara orang
     * mengetik `08123`, `62812`, atau `+62 812`.
     */
    #[Test]
    public function every_way_of_typing_the_same_number_finds_it_in_the_stored_number(): void
    {
        $stored = WhatsappNumber::normalize('0812-3456-7890');

        $this->assertSame('6281234567890', $stored);

        foreach (['08123', '62812', '812', '0812 3456', '+62 812', '0812-3456-7890'] as $typed) {
            $fragment = WhatsappNumber::searchFragment($typed);

            $this->assertIsString($fragment, "Mengetik '{$typed}' tidak dianggap pencarian nomor.");
            $this->assertStringContainsString(
                $fragment,
                $stored,
                "Mengetik '{$typed}' tidak akan menemukan nomor yang tersimpan.",
            );
        }
    }

    #[Test]
    public function the_short_remainder_of_a_typed_number_is_not_kept_as_a_number(): void
    {
        // `+62 (abc)` hanya menyisakan `62`, dan `62` adalah kode negara, bukan
        // nomor. Disimpan begitu saja akan mengisi kolom dengan sisa karakter
        // yang kebetulan berupa angka.
        $this->assertNull(WhatsappNumber::normalize('+62 (abc)'));
        $this->assertNull(WhatsappNumber::normalize('62'));
        $this->assertNull(WhatsappNumber::normalize('+62'));
    }

    #[Test]
    public function a_number_too_short_to_group_is_still_shown_rather_than_hidden(): void
    {
        // Angka pendek tidak akan mendapati tautan WhatsApp, dan membungkusnya
        // di `null` hanya membuat orang mengira datanya hilang.
        $this->assertSame('628123', WhatsappNumber::display('628123'));
        $this->assertNull(WhatsappNumber::display(null));
    }
}
