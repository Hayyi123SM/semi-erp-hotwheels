<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Services\Inventory\TermsCalculator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sisi server dari vektor bersama.
 *
 * Keduanya ada karena hanya satu yang bisa dipercaya: server yang menghitung
 * ulang, dan browser yang menampilkan pratinjau sebelum commit. Kalau salah satu
 * bergeser, kasir melihat angka yang berbeda dari yang tersimpan -- dan yang
 * tersimpan itulah yang jadi tagihan.
 *
 * Angkanya tidak ditulis di test ini tapi di `tests/fixtures/terms-vectors.json`,
 * yang juga dibaca `terms-calculator.test.js`. Dulu vektor yang sama ditulis dua
 * kali, dan literal yang berbeda di kedua file itu tetap hijau di kedua test --
 * yang membuat "sudah diuji sama" tidak berarti apa-apa.
 */
class TermsCalculatorTest extends TestCase
{
    private TermsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new TermsCalculator;
    }

    /**
     * Vektor dari fixture, dibungkus satu per satu.
     *
     * Pembungkus itu wajib: PHPUnit mengirim isi data set ke parameter test
     * berurutan, bukan berdasarkan nama kuncinya, jadi associative array akan
     * dibaca sebagai daftar argumen dan setiap vektor gagal dengan "Unknown named
     * parameter".
     *
     * @return list<array{0: array<string, mixed>}>
     */
    public static function sharedVectors(): array
    {
        $vectors = json_decode(
            (string) file_get_contents(__DIR__.'/../../fixtures/terms-vectors.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        )['vectors'];

        return array_map(fn (array $vector): array => [$vector], $vectors);
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[Test]
    #[DataProvider('sharedVectors')]
    public function the_shared_vector_holds(array $vector): void
    {
        $result = $this->calculator->breakdown(
            listPrice: $vector['listPrice'],
            scheme: SchemeType::from($vector['schemeType']),
            rate: $vector['schemeRate'] ?? null,
            amount: $vector['schemeAmount'] ?? null,
            policy: DiscountPolicy::from($vector['discountPolicy']),
            discount: $vector['discount'],
        );

        $this->assertSame($vector['storeFee'], $result->storeFee, $vector['name']);
        $this->assertSame($vector['consignorRight'], $result->consignorRight, $vector['name']);
        $this->assertSame($vector['negativeMargin'], $result->negativeMargin, $vector['name']);
    }

    /**
     * @param  array<string, mixed>  $vector
     */
    #[Test]
    #[DataProvider('sharedVectors')]
    public function the_shared_vector_never_loses_a_rupiah(array $vector): void
    {
        $result = $this->calculator->breakdown(
            listPrice: $vector['listPrice'],
            scheme: SchemeType::from($vector['schemeType']),
            rate: $vector['schemeRate'] ?? null,
            amount: $vector['schemeAmount'] ?? null,
            policy: DiscountPolicy::from($vector['discountPolicy']),
            discount: $vector['discount'],
        );

        $this->assertSame(
            $result->sellPrice,
            $result->storeFee + $result->consignorRight,
            "BR-05 gagal pada vektor: {$vector['name']}",
        );
    }

    #[Test]
    public function a_percentage_scheme_matches_the_documented_example(): void
    {
        // Dokumen 1.4.3: harga jual Rp50.000, 20% → toko Rp10.000, penitip Rp40.000.
        $result = $this->calculator->breakdown(50_000, SchemeType::Percentage, 20.0, null, DiscountPolicy::StoreBears);

        $this->assertSame(10_000, $result->storeFee);
        $this->assertSame(40_000, $result->consignorRight);
    }

    #[Test]
    public function a_nett_scheme_matches_the_documented_example(): void
    {
        // Dokumen 1.4.3: nett Rp38.000 → toko Rp12.000, penitip Rp38.000.
        $result = $this->calculator->breakdown(50_000, SchemeType::Nett, null, 38_000, DiscountPolicy::StoreBears);

        $this->assertSame(12_000, $result->storeFee);
        $this->assertSame(38_000, $result->consignorRight);
    }

    #[Test]
    public function a_flat_scheme_matches_the_documented_example(): void
    {
        // Dokumen 1.4.3: flat Rp8.000 → toko Rp8.000, penitip Rp42.000.
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 8_000, DiscountPolicy::StoreBears);

        $this->assertSame(8_000, $result->storeFee);
        $this->assertSame(42_000, $result->consignorRight);
    }

    /**
     * @param  list<array{0: int, 1: SchemeType, 2: float|null, 3: int|null, 4: DiscountPolicy, 5: int}>  $cases
     */
    #[Test]
    #[DataProvider('schemes')]
    public function the_split_always_adds_up_to_the_selling_price(
        int $listPrice,
        SchemeType $scheme,
        ?float $rate,
        ?int $amount,
        DiscountPolicy $policy,
        int $discount,
    ): void {
        $result = $this->calculator->breakdown($listPrice, $scheme, $rate, $amount, $policy, $discount);

        $this->assertSame(
            $result->sellPrice,
            $result->storeFee + $result->consignorRight,
            'BR-05: hak penitip + fee toko harus sama dengan P.',
        );
    }

    #[Test]
    public function store_bears_keeps_the_discount_entirely_on_the_store_side(): void
    {
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 10_000, DiscountPolicy::StoreBears, 5_000);

        // Fee flat Rp10.000 dari diskon Rp5.000 → toko terima Rp5.000, dan
        // penitip tetap dapat selisih harga jual terhadap fee yang sudah dipotong.
        $this->assertSame(5_000, $result->storeFee);
        $this->assertSame(40_000, $result->consignorRight);
    }

    #[Test]
    public function shared_splits_the_discount_between_both_sides(): void
    {
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 10_000, DiscountPolicy::Shared, 5_000);

        // Toko tetap dapat fee penuh, dan diskon menutup bagian penitip.
        $this->assertSame(10_000, $result->storeFee);
        $this->assertSame(35_000, $result->consignorRight);
    }

    #[Test]
    public function a_percentage_fee_is_rounded_half_up_before_it_is_multiplied_by_qty(): void
    {
        // Rp999 x 15% = Rp149,85. Dibulatkan per unit jadi Rp150, lalu dikali 3.
        // Kalau dibulatkan belakangan, 299,55 jadi Rp300 -- dan selisih Rp150 itu
        // muncul sebagai tagihan yang tidak ada dasarnya.
        $perUnit = $this->calculator->breakdown(999, SchemeType::Percentage, 15.0, null, DiscountPolicy::StoreBears);

        $this->assertSame(150, $perUnit->storeFee);
        $this->assertSame(450, $perUnit->storeFeeFor(3));
        $this->assertSame(2_547, $perUnit->consignorRightFor(3));
    }

    #[Test]
    public function half_a_rupiah_rounds_up_rather_than_to_even(): void
    {
        // 2,5 rupiah harus menjadi 3. Pembulatan bankir akan mengubahnya menjadi 2, dan
        // itulah yang membuat selisih yang tidak bisa dijelaskan muncul di satu
        // skema dan tidak di skema lain.
        $result = $this->calculator->breakdown(10, SchemeType::Percentage, 25.0, null, DiscountPolicy::StoreBears);

        $this->assertSame(3, $result->storeFee);
    }

    #[Test]
    public function a_negative_store_fee_is_flagged_rather_than_stored_quietly(): void
    {
        // Diskon lebih besar dari fee flat. P sudah habis oleh diskon, jadi fee
        // toko menjadi negatif: toko rugi pada setiap unit yang terjual.
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 8_000, DiscountPolicy::StoreBears, 45_000);

        $this->assertTrue($result->negativeMargin);
        $this->assertSame(-37_000, $result->storeFee);
    }

    #[Test]
    public function a_healthy_split_is_not_flagged(): void
    {
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 8_000, DiscountPolicy::StoreBears, 5_000);

        $this->assertFalse($result->negativeMargin);
    }

    #[Test]
    public function a_nett_price_above_the_discounted_price_is_flagged_as_negative_margin(): void
    {
        // Nett Rp40.000 dengan diskon Rp15.000 → P = Rp35.000, jadi penitip
        // menerima Rp40.000 dari penjualan Rp35.000. Toko menanggung selisihnya
        // dan diskon sekaligus: fee netto -5.000, dikurangi diskon 15.000.
        $result = $this->calculator->breakdown(50_000, SchemeType::Nett, null, 40_000, DiscountPolicy::StoreBears, 15_000);

        $this->assertTrue($result->negativeMargin);
        $this->assertSame(-20_000, $result->storeFee);
        $this->assertSame(55_000, $result->consignorRight);
    }

    #[Test]
    public function a_flat_fee_stays_a_fixed_amount_however_large_the_discount_is(): void
    {
        // Flat Rp45.000 dengan diskon Rp10.000: toko tetap terima Rp35.000, dan
        // ini bukan margin negatif. Yang membuat margin negatif adalah diskon
        // yang melampaui fee-nya, bukan fee yang besar -- jadi rentang
        // `0 < flat < harga jual` adalah aturan validasi di request, bukan
        // tugas kalkulator.
        $result = $this->calculator->breakdown(50_000, SchemeType::Flat, null, 45_000, DiscountPolicy::StoreBears, 10_000);

        $this->assertFalse($result->negativeMargin);
        $this->assertSame(35_000, $result->storeFee);
        $this->assertSame(5_000, $result->consignorRight);
    }

    #[Test]
    public function a_nett_price_equal_to_the_list_price_leaves_the_store_with_nothing(): void
    {
        // Tanpa diskon `P = L`, jadi nett sama dengan harga list berarti fee nol.
        // Bukan margin negatif -- nol bukan negatif -- tapi ini batas dari
        // `0 < nett < harga jual` di dokumen, jadi aturan request yang
        // menolaknya, dengan PIN Owner sebagai jalan keluarnya.
        $result = $this->calculator->breakdown(50_000, SchemeType::Nett, null, 50_000, DiscountPolicy::StoreBears);

        $this->assertFalse($result->negativeMargin);
        $this->assertSame(0, $result->storeFee);
        $this->assertSame(50_000, $result->consignorRight);
    }

    #[Test]
    public function a_percentage_outside_zero_to_hundred_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->breakdown(50_000, SchemeType::Percentage, 100.5, null, DiscountPolicy::StoreBears);
    }

    #[Test]
    public function a_scheme_missing_its_parameter_is_refused(): void
    {
        // Tidak ada Owner PIN yang mengizinkan lot yang skema parameternya kosong,
        // jadi ini salah pemanggil, bukan kasus BR-06.
        $this->expectException(ValidationException::class);

        $this->calculator->breakdown(50_000, SchemeType::Nett, null, null, DiscountPolicy::StoreBears);
    }

    #[Test]
    public function a_flat_fee_of_zero_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->breakdown(50_000, SchemeType::Flat, null, 0, DiscountPolicy::StoreBears);
    }

    #[Test]
    public function a_discount_above_the_list_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator->breakdown(50_000, SchemeType::Flat, null, 8_000, DiscountPolicy::StoreBears, 50_001);
    }

    /**
     * @return array<string, array{0: int, 1: SchemeType, 2: float|null, 3: int|null, 4: DiscountPolicy, 5: int}>
     */
    public static function schemes(): array
    {
        $policies = [DiscountPolicy::StoreBears, DiscountPolicy::Shared];

        $cases = [];

        foreach ($policies as $policy) {
            foreach ([0, 1_000, 5_000, 12_345] as $discount) {
                $cases["percentage / {$policy->value} / diskon {$discount}"] = [50_000, SchemeType::Percentage, 20.0, null, $policy, $discount];
                $cases["nett / {$policy->value} / diskon {$discount}"] = [50_000, SchemeType::Nett, null, 38_000, $policy, $discount];
                $cases["flat / {$policy->value} / diskon {$discount}"] = [50_000, SchemeType::Flat, null, 8_000, $policy, $discount];
            }
        }

        return $cases;
    }
}
