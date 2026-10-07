<?php

declare(strict_types=1);

namespace Tests\Unit\Consignment;

use App\Enums\PrintMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cara dokumen dikirim ke printer.
 *
 * Nilai `case` adalah format yang tersimpan di database, sama seperti
 * `PaperSize`. Yang dijaga di sini dua hal: label yang dibaca orang di
 * halaman Pengaturan, dan perilaku fallback untuk nilai lama/tidak dikenal.
 */
class PrintMethodTest extends TestCase
{
    #[Test]
    public function it_publishes_its_value_and_label(): void
    {
        self::assertSame('browser', PrintMethod::Browser->value);
        self::assertSame('thermal', PrintMethod::Thermal->value);
        self::assertSame('Cetak lewat browser (dialog cetak)', PrintMethod::Browser->label());
        self::assertSame('Cetak thermal (langsung ke printer)', PrintMethod::Thermal->label());
    }

    #[Test]
    public function an_unknown_stored_value_falls_back_instead_of_throwing(): void
    {
        self::assertNull(PrintMethod::fromSetting('kirim-saja'));
        self::assertNull(PrintMethod::fromSetting(''));
        self::assertNull(PrintMethod::fromSetting(null));

        self::assertSame(PrintMethod::Thermal, PrintMethod::fromSetting('thermal'));
    }

    #[Test]
    public function its_options_cover_every_case_so_the_form_cannot_offer_an_unknown_method(): void
    {
        $options = PrintMethod::options();

        self::assertCount(count(PrintMethod::cases()), $options);
        self::assertSame(
            array_map(static fn (PrintMethod $method): string => $method->value, PrintMethod::cases()),
            array_column($options, 'value'),
        );
    }
}
