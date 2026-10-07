<?php

namespace App\Services\Inventory;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\OwnerType;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Permintaan penerimaan barang baru ke dalam satu lot stok.
 *
 * Invariant kepemilikan ditegakkan di sini, bukan di controller, supaya tidak
 * ada jalur yang bisa membuat lot dengan kepemilikan setengah-setengah:
 *
 *  - CONSIGN wajib punya consignment DAN consignor, ownerCode harus sama dengan
 *    consignor_code, dan syarat skema ikut disalin dari kontrak penitip.
 *  - OWN tidak boleh punya consignment maupun consignor, ownerCode dipaksa
 *    OW00, dan costPrice wajib diisi karena stok toko tidak punya HPP titipan.
 *
 * Syarat skema dan diskon sengaja disalin ke lot (SNAPSHOT), bukan dibaca
 * ulang saat penjualan. Kalau penitip mengubah skema di kemudian hari,
 * penjualan lama tidak boleh ikut berubah (SRS: terms_version).
 */
final class ReceiveStock
{
    /**
     * @param  int  $qty  Jumlah unit fisik yang diterima. Wajib lebih dari nol.
     * @param  int|null  $costPrice  HPP. Wajib untuk OWN, opsional untuk CONSIGN.
     * @param  int  $termsVersion  Versi snapshot syarat yang tersimpan di lot.
     * @param  bool  $queueLabel  Apakah pembuatan label diantrekan otomatis.
     */
    private function __construct(
        public readonly OwnerType $ownerType,
        public readonly string $ownerCode,
        public readonly Product $product,
        public readonly int $qty,
        public readonly ?Consignment $consignment = null,
        public readonly ?Consignor $consignor = null,
        public readonly ?Rack $rack = null,
        public readonly ?int $costPrice = null,
        public readonly ?int $listPrice = null,
        public readonly ?SchemeType $schemeType = null,
        public readonly ?float $schemeRate = null,
        public readonly ?int $schemeAmount = null,
        public readonly ?DiscountPolicy $discountPolicy = null,
        public readonly ?CardCondition $cardCondition = null,
        public readonly ?BlisterCondition $blisterCondition = null,
        public readonly int $termsVersion = 1,
        public readonly bool $negativeMarginFlag = false,
        public readonly bool $queueLabel = true,
        public readonly ?User $actor = null,
        public readonly ?string $deviceId = null,
    ) {}

    /**
     * Barang titipan penitip. Syarat skema disalin dari kontrak penitip.
     *
     * @throws ValidationException
     */
    public static function forConsignment(
        Consignment $consignment,
        Consignor $consignor,
        Product $product,
        int $qty,
        ?Rack $rack = null,
        ?CardCondition $cardCondition = null,
        ?BlisterCondition $blisterCondition = null,
        ?int $listPrice = null,
        ?SchemeType $schemeType = null,
        ?float $schemeRate = null,
        ?int $schemeAmount = null,
        ?DiscountPolicy $discountPolicy = null,
        int $termsVersion = 1,
        bool $queueLabel = true,
        ?User $actor = null,
        ?string $deviceId = null,
    ): self {
        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Jumlah unit diterima minimal 1.',
            ]);
        }

        if ($consignment->consignor_id !== null && $consignment->consignor_id !== $consignor->id) {
            throw ValidationException::withMessages([
                'consignor_id' => 'Penitip pada consignment tidak cocok dengan penitip yang dipilih.',
            ]);
        }

        // Setiap yang tidak diteruskan berarti "pakai kontrak penitip", jadi
        // pemanggil boleh mengirim hanya yang memang berubah.
        $resolvedType = $schemeType ?? $consignor->scheme_type;

        // Parameter diambil lewat field milik skema yang benar-benar berlaku, bukan
        // tiap field diisi berdiri sendiri. Kalau tidak, menukar PERCENTAGE jadi
        // FLAT pada baris yang sama akan membawa rate persen lama ikut terbawa dan
        // menyimpannya di lot -- supaya biaya baris ini tidak bisa dihitung dari
        // field yang tercatat, dan tidak bisa ditagih.
        $parameterField = $resolvedType?->parameterField();
        $submittedParameter = $parameterField === 'scheme_rate' ? $schemeRate : $schemeAmount;
        $defaultParameter = $parameterField === null ? null : $consignor->{$parameterField};

        $resolvedRate = $parameterField === 'scheme_rate'
            ? ($submittedParameter ?? ($defaultParameter !== null ? (float) $defaultParameter : null))
            : null;
        $resolvedAmount = $parameterField === 'scheme_amount'
            ? ($submittedParameter !== null ? (int) $submittedParameter : ($defaultParameter !== null ? (int) $defaultParameter : null))
            : null;

        // Skema setengah jadi ditolak di sini dengan alasan yang menyebut field-nya,
        // bukan disimpan lalu menggagalkan penagihan di hari yang berbeda.
        self::guardSchemeIsComplete($resolvedType, $resolvedRate, $resolvedAmount);

        $listPrice ??= $product->default_list_price;

        $terms = app(TermsCalculator::class)->breakdown(
            listPrice: $listPrice,
            scheme: $resolvedType,
            rate: $resolvedRate,
            amount: $resolvedAmount,
            policy: $discountPolicy ?? $consignor->discount_policy,
        );

        return new self(
            ownerType: OwnerType::Consign,
            ownerCode: $consignor->consignor_code,
            product: $product,
            qty: $qty,
            consignment: $consignment,
            consignor: $consignor,
            rack: $rack,
            costPrice: null,
            listPrice: $listPrice,
            schemeType: $resolvedType,
            schemeRate: $resolvedRate,
            schemeAmount: $resolvedAmount,
            discountPolicy: $discountPolicy ?? $consignor->discount_policy,
            termsVersion: $termsVersion,
            negativeMarginFlag: $terms->negativeMargin,
            cardCondition: $cardCondition,
            blisterCondition: $blisterCondition,
            queueLabel: $queueLabel,
            actor: $actor,
            deviceId: $deviceId,
        );
    }

    /**
     * Skema tanpa parameter tidak bisa dihitung biayanya.
     *
     * Ditolak di sini, sebelum lot dibuat, karena lot yang tersimpan dengan
     * skema setengah jadi tidak bisa ditagih: tidak ada yang bisa bilang berapa
     * hak penitipnya.
     *
     * @throws ValidationException
     */
    private static function guardSchemeIsComplete(
        ?SchemeType $type,
        ?float $rate,
        ?int $amount,
    ): void {
        if ($type === null) {
            throw ValidationException::withMessages([
                'items.*.scheme_type' => 'Penitip ini belum punya skema komisi. Isi skema di Pengaturan · Penitip, atau pilih skema di baris ini.',
            ]);
        }

        $missing = match ($type) {
            SchemeType::Percentage => $rate === null,
            SchemeType::Nett, SchemeType::Flat => $amount === null,
        };

        if ($missing) {
            throw ValidationException::withMessages([
                'items.*.scheme_rate' => 'Parameter skema wajib diisi untuk skema yang dipilih.',
            ]);
        }
    }

    /**
     * Barang milik toko sendiri (Stock In Pribadi).
     *
     * @throws ValidationException
     */
    public static function forOwnStock(
        Product $product,
        int $qty,
        int $costPrice,
        ?Rack $rack = null,
        ?CardCondition $cardCondition = null,
        ?BlisterCondition $blisterCondition = null,
        bool $queueLabel = true,
        ?User $actor = null,
        ?string $deviceId = null,
    ): self {
        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Jumlah unit diterima minimal 1.',
            ]);
        }

        if ($costPrice < 0) {
            throw ValidationException::withMessages([
                'cost_price' => 'HPP tidak boleh negatif.',
            ]);
        }

        return new self(
            ownerType: OwnerType::Own,
            ownerCode: SkuService::OWN_CODE,
            product: $product,
            qty: $qty,
            consignment: null,
            consignor: null,
            rack: $rack,
            costPrice: $costPrice,
            listPrice: $product->default_list_price,
            schemeType: null,
            schemeRate: null,
            schemeAmount: null,
            discountPolicy: null,
            cardCondition: $cardCondition,
            blisterCondition: $blisterCondition,
            queueLabel: $queueLabel,
            actor: $actor,
            deviceId: $deviceId,
        );
    }

    /**
     * Kategori SKU diturunkan dari kode seri produk, jatuh ke HW bila kosong.
     */
    public function categoryCode(): string
    {
        $code = $this->product->series?->code;

        if ($code === null || trim($code) === '') {
            return SkuService::DEFAULT_CATEGORY;
        }

        return strtoupper(trim($code));
    }
}
