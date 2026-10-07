<?php

namespace Tests\Feature;

use App\Enums\OwnerType;
use App\Models\AuditLog;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\ConsignorLedger;
use App\Models\LabelPrintJob;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\RtvLine;
use App\Models\RtvNote;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\Shift;
use App\Models\SkuSequence;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menjamin setiap factory bisa membuat baris yang sah: kolom lengkap,
 * foreign key konsisten, dan unik. Ini pondasan seluruh test domain
 * (inbound, POS, settlement) yang akan dibangun di langkah berikutnya.
 */
class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function models(): array
    {
        return [
            'consignor' => [Consignor::class],
            'product series' => [ProductSeries::class],
            'product' => [Product::class],
            'rack' => [Rack::class],
            'user' => [User::class],
            'consignment' => [Consignment::class],
            'stock lot' => [StockLot::class],
            'sku sequence' => [SkuSequence::class],
            'stock movement' => [StockMovement::class],
            'label print job' => [LabelPrintJob::class],
            'quarantine case' => [QuarantineCase::class],
            'opname' => [Opname::class],
            'opname line' => [OpnameLine::class],
            'rtv note' => [RtvNote::class],
            'rtv line' => [RtvLine::class],
            'shift' => [Shift::class],
            'sale' => [Sale::class],
            'sale item' => [SaleItem::class],
            'sale payment' => [SalePayment::class],
            'settlement' => [Settlement::class],
            'settlement payment' => [SettlementPayment::class],
            'consignor ledger' => [ConsignorLedger::class],
            'setting' => [Setting::class],
            'wa template' => [WaTemplate::class],
            'audit log' => [AuditLog::class],
        ];
    }

    #[Test]
    #[DataProvider('models')]
    public function factory_can_create_a_row(string $model): void
    {
        $instance = $model::factory()->create();

        $this->assertTrue($instance->exists, $model.' tidak tersimpan.');
        $this->assertNotNull($instance->getKey() ?? $instance->getAttribute('owner_code'), $model.' tanpa primary key.');
    }

    #[Test]
    public function every_model_resolves_its_factory(): void
    {
        $models = array_column(self::models(), 0);

        foreach ($models as $model) {
            $factory = $model::factory();

            $this->assertSame(
                $model,
                $factory->modelName(),
                $model.' tidak resolve ke factory yang benar.'
            );
        }
    }

    #[Test]
    public function stock_lot_factory_accepts_personal_stock_without_consignment(): void
    {
        $lot = StockLot::factory()->own()->create();

        $this->assertNull($lot->consignment_id);
        $this->assertNull($lot->consignor_id);
        $this->assertSame(OwnerType::Own, $lot->owner_type);
        $this->assertSame('OW00', $lot->owner_code);
        $this->assertNotNull($lot->cost_price);
    }

    #[Test]
    public function consignor_factory_code_matches_sku_regex(): void
    {
        $consignor = Consignor::factory()->create();

        $this->assertMatchesRegularExpression('/^CN\d{2,3}$/', $consignor->consignor_code);
    }

    #[Test]
    public function quarantine_factory_computes_variance_identity(): void
    {
        // V = S - (C + Q)
        $clean = QuarantineCase::factory()->counted(3, 1, 2)->create();
        $this->assertSame(0, $clean->V);

        $overage = QuarantineCase::factory()->counted(1, 0, 2)->create();
        $this->assertSame(-1, $overage->V, 'Selisih negatif harus direpresentasikan sebagai V < 0.');

        $shortage = QuarantineCase::factory()->counted(5, 1, 2)->create();
        $this->assertSame(2, $shortage->V);
    }

    #[Test]
    public function settlement_factory_default_respects_invariant(): void
    {
        $settlement = Settlement::factory()->create();

        // Invarian SRS: total_hak + total_fee = total_bruto
        $this->assertSame(
            $settlement->total_bruto,
            $settlement->total_hak + $settlement->total_fee
        );

        $this->assertSame(
            $settlement->net_payable,
            $settlement->total_hak - $settlement->refunds
        );
    }

    #[Test]
    public function append_only_tables_are_not_timestamped(): void
    {
        foreach ([StockMovement::class, ConsignorLedger::class, AuditLog::class] as $model) {
            $this->assertFalse(
                (new $model)->usesTimestamps(),
                $model.' seharusnya tidak memakai timestamps.'
            );
        }
    }

    #[Test]
    public function user_factory_pin_is_hashed_and_verifiable(): void
    {
        $user = User::factory()->withPin('918273')->create();

        $this->assertNotSame('918273', $user->pin, 'PIN harus tersimpan sebagai hash.');
        $this->assertTrue(Hash::check('918273', $user->pin));
    }
}
