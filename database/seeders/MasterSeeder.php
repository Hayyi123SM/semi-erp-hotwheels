<?php

namespace Database\Seeders;

use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LossLiability;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Enums\RackType;
use App\Enums\SchemeType;
use App\Enums\SettlementCycle;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\SkuSequence;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Seeder;

class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('username', 'owner')->firstOrFail();

        $consignors = $this->seedConsignors();
        $series = $this->seedSeries();
        $racks = $this->seedRacks();
        $products = $this->seedProducts($series);

        $consignments = collect();

        $own = $this->makeConsignment($owner, 'C-2026-0001', OwnerType::Own, null, 4, ConsignmentStatus::Committed);
        $ownLots = [
            ['key' => '97 Mazda RX-7', 'code' => 'OW00', 'seq' => 1, 'price' => 145000, 'rack' => 'A-01-03'],
            ['key' => 'Porsche 911 GT3 RS', 'code' => 'OW00', 'seq' => 2, 'price' => 165000, 'rack' => 'A-01-03'],
            ['key' => 'Honda Civic Type R', 'code' => 'OW00', 'seq' => 3, 'price' => 95000, 'rack' => 'A-02-01'],
            ['key' => 'Mazda 787B', 'code' => 'OW00', 'seq' => 4, 'price' => 135000, 'rack' => 'A-02-01'],
        ];
        foreach ($ownLots as $lot) {
            $this->makeLot($own, $lot['key'], $lot['code'], $lot['seq'], $lot['price'], $lot['rack'], $products, $racks);
        }
        $consignments->push($own);
        $this->seedSkuSeq('OW00', 'HW', 4);

        $cn1 = $this->makeConsignment($owner, 'C-2026-0002', OwnerType::Consign, $consignors['CN01'], 2, ConsignmentStatus::Committed);
        $cn1Lots = [
            ['key' => 'Nissan Skyline GT-R R34', 'code' => 'CN01', 'seq' => 1, 'price' => 220000, 'rack' => 'A-01-03'],
            ['key' => 'Toyota Supra MK4', 'code' => 'CN01', 'seq' => 2, 'price' => 185000, 'rack' => 'B-01-05'],
        ];
        foreach ($cn1Lots as $lot) {
            $this->makeLot($cn1, $lot['key'], $lot['code'], $lot['seq'], $lot['price'], $lot['rack'], $products, $racks);
        }
        $consignments->push($cn1);
        $this->seedSkuSeq('CN01', 'HW', 2);

        $cn2 = $this->makeConsignment($owner, 'C-2026-0003', OwnerType::Consign, $consignors['CN02'], 2, ConsignmentStatus::Committed);
        $cn2Lots = [
            ['key' => 'Lamborghini Huracan', 'code' => 'CN02', 'seq' => 1, 'price' => 175000, 'rack' => 'B-01-05'],
            ['key' => 'Ford Mustang GT', 'code' => 'CN02', 'seq' => 2, 'price' => 120000, 'rack' => 'Q-00-01'],
        ];
        foreach ($cn2Lots as $lot) {
            $this->makeLot($cn2, $lot['key'], $lot['code'], $lot['seq'], $lot['price'], $lot['rack'], $products, $racks);
        }
        $consignments->push($cn2);
        $this->seedSkuSeq('CN02', 'HW', 2);

        foreach ($consignments as $i => $c) {
            $c->qty_received = $c->qty_claimed;
            $c->committed_at = now()->copy()->subDays(30 - $i);
            $c->save();
        }
    }

    private function seedConsignors(): array
    {
        return [
            'CN01' => Consignor::firstOrCreate(
                ['consignor_code' => 'CN01'],
                [
                    'name' => 'Budi Santoso',
                    'wa_number' => '+6281234567890',
                    'scheme_type' => SchemeType::Percentage,
                    'scheme_rate' => 20,
                    'discount_policy' => DiscountPolicy::StoreBears,
                    'loss_liability' => LossLiability::Store,
                    'settlement_cycle' => SettlementCycle::Monthly,
                    'min_payout' => 100000,
                    'status' => ConsignorStatus::Active,
                ]
            ),
            'CN02' => Consignor::firstOrCreate(
                ['consignor_code' => 'CN02'],
                [
                    'name' => 'Rina Wijaya',
                    'wa_number' => '+6281399887711',
                    'scheme_type' => SchemeType::Nett,
                    'scheme_amount' => 65000,
                    'discount_policy' => DiscountPolicy::StoreBears,
                    'loss_liability' => LossLiability::Consignor,
                    'settlement_cycle' => SettlementCycle::Biweekly,
                    'min_payout' => 50000,
                    'status' => ConsignorStatus::Active,
                ]
            ),
            'CN03' => Consignor::firstOrCreate(
                ['consignor_code' => 'CN03'],
                [
                    'name' => 'Agus Kurniawan',
                    'wa_number' => '+6285711223344',
                    'scheme_type' => SchemeType::Flat,
                    'scheme_amount' => 8000,
                    'discount_policy' => DiscountPolicy::Shared,
                    'loss_liability' => LossLiability::Store,
                    'settlement_cycle' => SettlementCycle::Monthly,
                    'status' => ConsignorStatus::Archived,
                ]
            ),
        ];
    }

    private function seedSeries(): array
    {
        $names = [
            'Car Culture' => 'CUL',
            'Porsche' => 'POR',
            'Honda' => 'HON',
            'Fast & Furious' => 'F&F',
            'American Scene' => 'AMS',
        ];

        $result = [];
        foreach ($names as $name => $code) {
            $result[$name] = ProductSeries::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        return $result;
    }

    private function seedRacks(): array
    {
        $racks = [
            ['code' => 'A-01-03', 'zone' => 'A', 'type' => RackType::Display, 'capacity' => 24, 'is_active' => true],
            ['code' => 'A-02-01', 'zone' => 'A', 'type' => RackType::Display, 'capacity' => 24, 'is_active' => true],
            ['code' => 'B-01-05', 'zone' => 'B', 'type' => RackType::Storage, 'capacity' => 48, 'is_active' => true],
            ['code' => 'Q-00-01', 'zone' => 'Q', 'type' => RackType::Quarantine, 'capacity' => 12, 'is_active' => true],
            ['code' => 'R-00-02', 'zone' => 'R', 'type' => RackType::RtvStaging, 'capacity' => 10, 'is_active' => true],
            ['code' => 'C-03-08', 'zone' => 'C', 'type' => RackType::Storage, 'capacity' => 60, 'is_active' => false],
        ];

        $result = [];
        foreach ($racks as $rack) {
            $result[$rack['code']] = Rack::firstOrCreate(['code' => $rack['code']], $rack);
        }

        return $result;
    }

    private function seedProducts(array $series): array
    {
        $catalog = [
            ['model' => '97 Mazda RX-7', 'series' => 'Car Culture', 'year' => 2024, 'color' => 'Silver', 'condition' => 'Mint', 'price' => 145000],
            ['model' => 'Porsche 911 GT3 RS', 'series' => 'Porsche', 'year' => 2024, 'color' => 'GT Silver', 'condition' => 'Mint', 'price' => 165000],
            ['model' => 'Honda Civic Type R', 'series' => 'Honda', 'year' => 2023, 'color' => 'Championship White', 'condition' => 'Clear', 'price' => 95000],
            ['model' => 'Nissan Skyline GT-R R34', 'series' => 'Fast & Furious', 'year' => 2024, 'color' => 'Bayside Blue', 'condition' => 'Mint', 'price' => 220000],
            ['model' => 'Toyota Supra MK4', 'series' => 'Fast & Furious', 'year' => 2024, 'color' => 'Pearl Orange', 'condition' => 'Mint', 'price' => 185000],
            ['model' => 'Lamborghini Huracan', 'series' => 'Car Culture', 'year' => 2023, 'color' => 'Verde Mantis', 'condition' => 'Clear', 'price' => 175000],
            ['model' => 'Ford Mustang GT', 'series' => 'American Scene', 'year' => 2022, 'color' => 'Oxford White', 'condition' => 'Damaged', 'price' => 120000],
            ['model' => 'Mazda 787B', 'series' => 'Car Culture', 'year' => 2022, 'color' => 'Renown Orange', 'condition' => 'Mint', 'price' => 135000],
        ];

        $result = [];
        foreach ($catalog as $row) {
            $product = Product::firstOrCreate(
                ['name' => $row['model']],
                [
                    'series_id' => $series[$row['series']]->id,
                    'year' => $row['year'],
                    'color' => $row['color'],
                    'packaging_type' => 'CARDED',
                    'card_condition' => $row['condition'] === 'Mint' ? CardCondition::Mint : ($row['condition'] === 'Clear' ? CardCondition::NearMint : CardCondition::Damaged),
                    'blister_condition' => $row['condition'] === 'Damaged' ? 'DENTED' : 'CLEAR',
                    'default_list_price' => $row['price'],
                ]
            );
            $result[$product->name] = $product;
        }

        return $result;
    }

    private function makeConsignment(User $owner, string $docNo, OwnerType $ownerType, ?Consignor $consignor, int $qty, ConsignmentStatus $status)
    {
        return Consignment::create([
            'doc_no' => $docNo,
            'owner_type' => $ownerType,
            'consignor_id' => $consignor?->id,
            'consignment_date' => now()->subDays(30),
            'notes' => 'Seeder '.$docNo,
            'qty_claimed' => $qty,
            'qty_received' => $qty,
            'status' => $status,
            'created_by' => $owner->id,
            'committed_at' => now()->subDays(29),
            'committed_by' => $owner->id,
        ]);
    }

    private function makeLot($consignment, string $productKey, string $ownerCode, int $seq, int $price, string $rackCode, array $products, array $racks): void
    {
        $sku = sprintf('%s-HW-%03d', $ownerCode, $seq);
        $ownerType = $ownerCode === 'OW00' ? OwnerType::Own : OwnerType::Consign;
        $productId = $products[$productKey]->id;
        $cardCondition = $productKey === 'Ford Mustang GT' ? CardCondition::Damaged : ($productKey === 'Honda Civic Type R' || $productKey === 'Lamborghini Huracan' ? CardCondition::NearMint : CardCondition::Mint);
        $isConsign = $ownerType === OwnerType::Consign;

        if (StockLot::where('sku', $sku)->exists()) {
            return;
        }

        StockLot::create([
            'consignment_id' => $consignment->id,
            'sku' => $sku,
            'sequence' => $seq,
            'category_code' => 'HW',
            'owner_type' => $ownerType,
            'owner_code' => $ownerCode,
            'consignor_id' => $consignorId = $isConsign ? $consignment->consignor_id : null,
            'product_id' => $productId,
            'card_condition' => $cardCondition,
            'blister_condition' => $cardCondition === CardCondition::Damaged ? 'DENTED' : 'CLEAR',
            'list_price' => $price,
            'cost_price' => $isConsign ? $this->consignCost($consignment, $price) : $price,
            'scheme_type' => $consignment->consignor?->scheme_type,
            'scheme_rate' => $consignment->consignor?->scheme_rate,
            'scheme_amount' => $consignment->consignor?->scheme_amount,
            'discount_policy' => $consignment->consignor?->discount_policy ?? DiscountPolicy::StoreBears,
            'terms_version' => 1,
            'qty_received' => 1,
            'qty_on_hand' => 1,
            'labels_printed' => 1,
            'rack_id' => $racks[$rackCode]->id,
            'status' => LotStatus::Available,
        ]);
    }

    private function consignCost($consignment, int $price): int
    {
        $consignor = $consignment->consignor;

        return match ($consignor->scheme_type) {
            SchemeType::Nett => (int) $consignor->scheme_amount,
            SchemeType::Flat => max(0, $price - (int) $consignor->scheme_amount),
            default => (int) round($price * ($consignor->scheme_rate ?? 0) / 100),
        };
    }

    private function seedSkuSeq(string $ownerCode, string $category, int $lastSeq): void
    {
        SkuSequence::updateOrCreate(
            ['owner_code' => $ownerCode, 'category_code' => $category],
            ['last_seq' => $lastSeq]
        );
    }
}
