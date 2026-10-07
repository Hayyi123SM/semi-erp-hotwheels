<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat aritmatika skema komisi.
 *
 * Tiga aturan dokumen yang ditegakkan di sini, dan tidak di tempat lain:
 *
 *  - BR-04: `STORE_BEARS` (default) menghitung hak penitip dari `L`, sehingga
 *    diskon mengurangi bagian toko; `SHARED` menghitungnya dari `P`, sehingga
 *    diskon ditanggung berdua.
 *  - BR-05: `hak_penitip + fee_toko = P` selalu, apa pun kebijakannya.
 *  - BR-06: fee toko negatif ditandai, bukan disimpan diam-diam.
 *
 * Pembulatan hanya berlaku pada fee skema `PERCENTAGE`, dan hanya sekali, di
 * level per unit -- bukan setelah dikalikan qty. `round(P x rate) x qty` dan
 * `round(P x rate x qty)` bisa berbeda beberapa rupiah pada qty yang bukan satu,
 * dan selisih itu berakhir dikreditkan ke penitip.
 *
 * Angka-angka di sini adalah satu-satunya yang dipakai server. `terms-calculator.js`
 * mengulang aritmetika yang sama untuk pratinjau di layar, dan kedua sisi diuji
 * dengan vektor angka yang identik.
 */
final class TermsCalculator
{
    /**
     * @param  int  $listPrice  harga list di sistem
     * @param  int  $discount  diskon yang dialokasikan ke item ini
     *
     * @throws ValidationException
     */
    public function breakdown(
        int $listPrice,
        SchemeType $scheme,
        ?float $rate,
        ?int $amount,
        DiscountPolicy $policy,
        int $discount = 0,
    ): TermsBreakdown {
        if ($listPrice < 0) {
            throw ValidationException::withMessages([
                'list_price' => 'Harga list tidak boleh negatif.',
            ]);
        }

        if ($discount < 0 || $discount > $listPrice) {
            throw ValidationException::withMessages([
                'discount' => 'Diskon harus antara 0 dan harga list.',
            ]);
        }

        $sellPrice = $listPrice - $discount;
        $schemeFee = $this->schemeFee($sellPrice, $scheme, $rate, $amount);

        // BR-04. Yang membedakan kedua kebijakan hanya satu hal: siapa yang
        // menanggung diskon. Fee skema-nya sendiri sudah dihitung dari `P` pada
        // keduanya.
        $storeIncome = $policy === DiscountPolicy::StoreBears
            ? $schemeFee - $discount
            : $schemeFee;

        // Hak penitip selalu sisanya dari `P`, bukan dari `L`. Deng begitu,
        // invarian BR-05 tidak bisa dilanggar: di `STORE_BEARS`
        // diskon memotong bagian toko, di `SHARED` diskon memotong hasil penjualan
        // yang dibagi berdua, dan di kedua kasus `P` tetap terbagi habis.
        $consignorRight = $sellPrice - $storeIncome;

        return new TermsBreakdown(
            listPrice: $listPrice,
            discount: $discount,
            sellPrice: $sellPrice,
            storeFee: $storeIncome,
            consignorRight: $consignorRight,
            negativeMargin: $storeIncome < 0,
        );
    }

    /**
     * Fee skema sebelum kebijakan diskon ikut memotongnya.
     *
     * Hanya menolak input yang tidak mungkin terjadi: parameter yang tidak ada,
     * atau yang tidak positif. Rentang yang membuat margin negatif -- `nett` di
     * atas harga jual, `flat` melampaui harga -- bukan pengecualian di sini: itu
     * persis kasus BR-06, yang harus dihitung lalu ditandai lewat
     * `negative_margin_flag`, bukan dibuang sebelum sempat dihitung.
     *
     * @throws ValidationException
     */
    private function schemeFee(
        int $sellPrice,
        SchemeType $scheme,
        ?float $rate,
        ?int $amount,
    ): int {
        return match ($scheme) {
            // `ROUND_HALF_UP`, sesuai BR-05. Pembulatan setengah ke atas, bukan
            // pembulatan bankir, supaya 0,5 rupiah tidak berayun ke bawah ketika
            // qty-nya dijumlahkan.
            SchemeType::Percentage => $this->percentageFee($sellPrice, $rate),
            // Hak penitip sudah ditentukan oleh harga nett; fee adalah
            // selisihnya terhadap harga jual.
            SchemeType::Nett => $sellPrice - $this->positiveAmount($amount, 'Harga nett'),
            SchemeType::Flat => $this->flatFee($sellPrice, $amount),
        };
    }

    /**
     * @throws ValidationException
     */
    private function percentageFee(int $sellPrice, ?float $rate): int
    {
        if ($rate === null || $rate <= 0 || $rate > 100) {
            throw ValidationException::withMessages([
                'scheme_rate' => 'Persentase skema harus di atas 0 sampai 100.',
            ]);
        }

        return (int) round($sellPrice * ($rate / 100), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @throws ValidationException
     */
    private function flatFee(int $sellPrice, ?int $amount): int
    {
        return $this->positiveAmount($amount, 'Fee flat');
    }

    /**
     * Parameter skema yang harusnya ada dan positif.
     *
     *Ketidaksavuaraan parameter adalah kesalahan pemanggil -- tidak ada Owner
     * PIN yang mengizinkan lot yang skema parameternya tidak lengkap -- jadi ini
     * ditolak di sini, bukan ditandai.
     *
     * @throws ValidationException
     */
    private function positiveAmount(?int $amount, string $label): int
    {
        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages([
                'scheme_amount' => "{$label} harus lebih besar dari 0.",
            ]);
        }

        return $amount;
    }
}
