<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Label\LabelContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LabelContentTest extends TestCase
{
    private function lot(string $productName = 'Ferrari F40', int $price = 50_000): StockLot
    {
        $lot = new StockLot([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'cn01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => $price,
            'qty_received' => 12,
        ]);

        $lot->setRelation('product', new Product(['name' => $productName]));
        $lot->setRelation('consignment', new Consignment);
        $lot->setRelation('consignor', new Consignor(['name' => 'Budi Santoso']));

        return $lot;
    }

    /**
     * `payload` adalah satu-satunya cara job lama bisa dicetak ulang dengan isi
     * yang sama. Kalau bolong, re-print karena `PRICE_CHANGE` akan diam-diam
     * menghasilkan label bertanda "Harga usang" -- persis yang seharusnya
     * dicegah.
     */
    #[Test]
    public function a_content_survives_a_round_trip_through_the_payload(): void
    {
        $original = LabelContent::fromLot($this->lot());

        $restored = LabelContent::fromPayload($original->toPayload());

        $this->assertEquals($original, $restored);
    }

    /**
     * Nama produk berasal dari katalog, yang boleh sangat panjang. Label 3x2 cm
     * tidak muat, jadi harus dipangkas. Dipangkas di `LabelContent`, bukan di
     * template, supaya `payload` menyimpan bentuk final dan pemangkasan tidak
     * berubah antara dua kali cetak.
     */
    #[Test]
    public function a_long_product_name_is_shortened_to_something_a_label_can_hold(): void
    {
        $content = LabelContent::fromLot($this->lot('Ferrari F40 GT Heritage Edition 1:18 Diecast'));

        $this->assertLessThanOrEqual(24, mb_strlen($content->productName));
        $this->assertStringEndsWith('…', $content->productName);
    }

    #[Test]
    public function a_name_that_already_fits_is_left_alone(): void
    {
        $content = LabelContent::fromLot($this->lot('F40'));

        $this->assertSame('F40', $content->productName);
    }

    /**
     * Lot tanpa produk itu mungkin terjadi pada produk cepat-tambah yang
     * belum ditinjau. Label tetap harus tercetak, supaya barangnya tidak
     * berakhir tanpa label.
     */
    #[Test]
    public function a_lot_without_a_product_still_produces_a_printable_name(): void
    {
        $lot = new StockLot([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'CN01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::NA,
            'list_price' => 10_000,
            'qty_received' => 1,
        ]);

        // Relasi diisi null secara eksplisit supaya tidak memicu lazy load.
        $lot->setRelation('product', null);

        $content = LabelContent::fromLot($lot);

        $this->assertSame('Tanpa Nama', $content->productName);
    }

    /**
     * Nama penitip tidak boleh masuk ke `payload`, bukan cuma tidak boleh
     * tercetak. Kalau bocor ke sini, `payload` akan ikut menyimpannya di
     * database setiap kali label dicetak.
     */
    #[Test]
    public function the_payload_never_holds_the_consignor_name(): void
    {
        $payload = LabelContent::fromLot($this->lot())->toPayload();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Budi Santoso', $encoded);
        $this->assertSame(['sku', 'product_name', 'owner_code', 'owner_type', 'card_condition', 'blister_condition', 'list_price', 'quantity'], array_keys($payload));
    }

    #[Test]
    public function the_owner_code_is_printed_in_upper_case(): void
    {
        // Disimpan sebagai `cn01` di satu jalur dan `CN01` di jalur lain;
        // label harus sama saja.
        $content = LabelContent::fromLot($this->lot());

        $this->assertSame('CN01', $content->ownerLabel());
    }

    #[Test]
    public function both_conditions_are_printed_together(): void
    {
        $content = LabelContent::fromLot($this->lot());

        // NM/CL, bukan hanya salah satu: kondisi blister bisa berbeda dari card
        // pada SKU yang sama, jadi keduanya harus terbaca.
        $this->assertSame('NM/CL', $content->conditionLabel());
    }

    /**
     * @return array<string, array{0: BlisterCondition, 1: string}>
     */
    public static function blisterCases(): array
    {
        return [
            'NA tidak dilabeli N/A' => [BlisterCondition::NA, 'NM/-'],
            'retak' => [BlisterCondition::Cracked, 'NM/CK'],
        ];
    }

    #[Test]
    #[DataProvider('blisterCases')]
    public function every_blister_condition_has_a_short_form(BlisterCondition $condition, string $expected): void
    {
        $lot = new StockLot([
            'sku' => 'S',
            'owner_code' => 'PRIBADI',
            'owner_type' => OwnerType::Own,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => $condition,
            'list_price' => 1_000,
            'qty_received' => 1,
        ]);

        $lot->setRelation('product', null);

        $this->assertSame($expected, LabelContent::fromLot($lot)->conditionLabel());
    }

    /**
     * Nilai enum yang tidak dikenal harus berhenti di sini dengan pesan yang
     * jelas. Kalau diteruskan, kondisinya tercetak kosong dan scanner di gudang
     * membaca label yang salah tanpa ada yang mengeluh.
     */
    #[Test]
    public function an_unknown_condition_is_rejected_loudly(): void
    {
        $lot = $this->lot();

        $lot->setRawAttributes(array_merge($lot->getAttributes(), [
            'card_condition' => 'MELAR',
        ]));

        $this->expectException(\ValueError::class);

        LabelContent::fromLot($lot);
    }

    #[Test]
    public function the_price_is_formatted_the_way_the_rest_of_the_app_formats_money(): void
    {
        $this->assertSame('Rp50.000', LabelContent::fromLot($this->lot(price: 50_000))->priceLabel());
        $this->assertSame('Rp1.250.000', LabelContent::fromLot($this->lot(price: 1_250_000))->priceLabel());
    }

    /**
     * Uji cetak (FR-IB-25) hanya berguna kalau isinya benar-benar kasus
     * terburuk. Kalau `sample()` memakai SKU pendek atau harga murah, uji
     * cetak tetap hijau di printer yang baris hunannya meluber -- persis
     * masalah yang seharusnya ia temukan.
     *
     * Setiap angka di sini diambil dari batas validasi yang sebenarnya, bukan
     * dari tebakan: SKU 15 karakter, `items.*.list_price` maksimum
     * 100.000.000, `qty_received` maksimum 999.
     */
    #[Test]
    public function the_test_print_sample_is_the_worst_case_we_actually_allow(): void
    {
        $sample = LabelContent::sample();

        $this->assertSame(15, mb_strlen($sample->sku));
        $this->assertSame(100_000_000, $sample->listPrice);
        $this->assertSame(999, $sample->quantity);

        // Bentuk terpanjang yang masih sah: `CN` + 3 digit, sesuai regex SKU
        // `^(OW00|CN\d{2,3})-...`. Kalau contoh memakai bentuk yang tidak ada
        // di master, uji cetaknya mengukur sesuatu yang tidak pernah terjadi.
        $this->assertSame('CN999', $sample->ownerLabel());
        $this->assertSame(OwnerType::Consign, $sample->ownerType);

        // Pasangan singkatan terpanjang di enum kondisi.
        $this->assertSame('NM/CL', $sample->conditionLabel());

        // Nama produk sudah dipangkas ke batas yang benar-benar dicetak.
        $this->assertSame(24, mb_strlen($sample->productName));
        $this->assertNotSame('', trim($sample->productName));
    }
}
