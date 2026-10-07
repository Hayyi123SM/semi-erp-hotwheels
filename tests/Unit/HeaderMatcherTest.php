<?php

namespace Tests\Unit;

use App\Services\Import\HeaderMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HeaderMatcherTest extends TestCase
{
    private function suggest(string $module, array $header): array
    {
        return app(HeaderMatcher::class)->suggest($module, $header);
    }

    #[Test]
    public function it_maps_the_columns_of_our_own_template_by_name(): void
    {
        $suggested = $this->suggest('katalog', [
            'Nama Produk — Kolom 1',
            'Nama Produk — Kolom 2 (opsional)',
            'Seri',
            'Kode Casting',
            'Tahun',
            'Warna',
            'Kemasan (CARDED/BOXED/LOOSE)',
            'Harga Jual (Rp)',
            'Status (ACTIVE/INACTIVE)',
        ]);

        $this->assertSame([
            'name:p1' => 'A',
            'name:p2' => 'B',
            'series_id' => 'C',
            'casting_code' => 'D',
            'year' => 'E',
            'color' => 'F',
            'packaging_type' => 'G',
            'default_list_price' => 'H',
            'status' => 'I',
        ], $suggested);
    }

    #[Test]
    public function it_recognises_plain_headings_that_differ_only_by_parenthetical_notes(): void
    {
        // Keterangan dalam kurung dihapus sebelum pencocokan, jadi berkas
        // yang heading-nya lebih pendek tetap dikenali oleh label template.
        $suggested = $this->suggest('katalog', ['Nama Produk', 'Warna', 'Harga Jual']);

        $this->assertSame([
            'name:p1' => 'A',
            'color' => 'B',
            'default_list_price' => 'C',
        ], $suggested);
    }

    #[Test]
    public function it_never_claims_the_same_column_twice(): void
    {
        $suggested = $this->suggest('rak', ['Kode Rak', 'Kode Rak']);

        $this->assertSame(['code' => 'A'], $suggested);
        $this->assertCount(1, array_unique(array_values($suggested)));
    }

    #[Test]
    public function it_does_not_fill_the_second_name_column_from_the_first_one(): void
    {
        // Dua item nama produk menuliskan satu atribut yang sama. Kalau kolom
        // judulnya identik, mengisi keduanya berarti nama yang sama ditulis
        // dua kali -- lebih baik kolom kedua dibiarkan kosong untuk diisi manual.
        $suggested = $this->suggest('katalog', ['Nama Produk', 'Nama Produk']);

        $this->assertSame(['name:p1' => 'A'], $suggested);
    }

    #[Test]
    public function it_says_nothing_about_columns_that_belong_to_nothing(): void
    {
        // Menebak asal-usul "Stok Gudang" ke field mana pun hanya menambah
        // tebakan yang harus dibatalkan orang satu per satu.
        $this->assertSame([], $this->suggest('katalog', ['Stok Gudang', 'Catatan Internal', 'Kolom Kosong']));
    }

    #[Test]
    public function it_refuses_to_guess_from_abbreviations(): void
    {
        // "hrg" dan "thn" memang Kemungkinan besar harga dan tahun, tapi
        // tebakan yang salah di sini memindahkan angka ke kolom yang salah.
        $this->assertSame([], $this->suggest('katalog', ['hrg', 'thn', 'qty']));
    }

    #[Test]
    public function it_ignores_blank_and_missing_headers(): void
    {
        $this->assertSame([], $this->suggest('rak', ['', null, '   ']));
    }

    #[Test]
    public function it_does_not_match_numbers_alone_as_a_year(): void
    {
        // Skema rak memang punya kolom "Tahun" opsional, tapi angka 3 di
        // kolom tanpa judul tidak boleh dianggap tahun.
        $suggested = $this->suggest('rak', ['2024']);

        $this->assertSame([], $suggested);
    }
}
