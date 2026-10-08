<?php

namespace App\Services\Report;

use App\Enums\LedgerType;
use App\Enums\SettlementStatus;
use App\Models\ConsignorLedger;
use App\Models\Settlement;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Sisi baca dari settlement tanpa pernah menyentuh alurnya.
 *
 * Pembuatan, persetujuan, dan pembayaran settlement adalah alur yang mengubah
 * data (Phase B). Laporan ini hanya membaca: saldo hak penitip yang dihitung
 * ulang dari `consignor_ledger`, statement yang sudah pernah dikunci, dan umur
 * utang yang belum dibayar. Semangatnya sama dengan audit log -- angka yang
 * tampil harus bisa dijelaskan dari baris-baris tabel, bukan dari tebakan.
 */
class SettlementReportService
{
    /**
     * Saldo hak penitip saat ini, dari setiap entri ledger.
     *
     * Entri `SETTLEMENT_PAYMENT` selalu negatif (uang keluar), sisanya positif
     * (uang masuk ke hak penitip). Jumlah keduanya adalah saldo yang sebenarnya
     * harus dibayar toko ke penitip itu sekarang juga.
     *
     * @return array<int, array{consignorId: int, code: string, name: string, accrual: int, paid: int, balance: int}>
     */
    public function balances(): array
    {
        return ConsignorLedger::query()
            ->join('consignors', 'consignors.id', '=', 'consignor_ledger.consignor_id')
            ->selectRaw(
                'consignors.id as consignor_id,
                 consignors.consignor_code,
                 consignors.name,
                 coalesce(sum(case when consignor_ledger.type <> ? then consignor_ledger.amount else 0 end), 0) as accrual,
                 coalesce(sum(case when consignor_ledger.type = ? then consignor_ledger.amount else 0 end), 0) as paid',
                [LedgerType::SettlementPayment->value, LedgerType::SettlementPayment->value],
            )
            ->groupBy('consignors.id', 'consignors.consignor_code', 'consignors.name')
            ->orderByDesc('accrual')
            ->get()
            ->map(fn ($row): array => [
                'consignorId' => (int) $row->consignor_id,
                'code' => (string) $row->consignor_code,
                'name' => (string) $row->name,
                'accrual' => (int) $row->accrual,
                'paid' => (int) $row->paid,
                'balance' => (int) $row->accrual + (int) $row->paid,
                'balanced' => (int) $row->accrual + (int) $row->paid === 0,
            ])
            ->all();
    }

    /**
     * Empat angka pembuka halaman settlement.
     *
     * @return array{draft: int, waiting: int, paid30d: int, carryOver: int}
     */
    public function summary(): array
    {
        $draft = Settlement::query()
            ->where('status', SettlementStatus::Draft->value)
            ->count();

        $waiting = Settlement::query()
            ->whereIn('status', [
                SettlementStatus::Approved->value,
                SettlementStatus::Sent->value,
                SettlementStatus::PartiallyPaid->value,
            ])
            ->withSum('payments as paid', 'amount')
            ->get()
            ->sum(fn (Settlement $s): int => max(0, $s->net_payable - (int) $s->paid));

        $paid30d = Settlement::query()
            ->whereHas('payments', fn ($q) => $q->where('paid_at', '>=', now()->subDays(30)))
            ->get()
            ->sum(fn (Settlement $s): int => $s->payments->where('paid_at', '>=', now()->subDays(30))->sum('amount'));

        $carryOver = Settlement::query()->sum('carry_over');

        return [
            'draft' => $draft,
            'waiting' => (int) $waiting,
            'paid30d' => (int) $paid30d,
            'carryOver' => (int) $carryOver,
        ];
    }

    /**
     * Daftar settlement terbaru, urut dari yang terbaru.
     *
     * @return array<int, array{
     *     id: int,
     *     no: string,
     *     consignor: string,
     *     period: string,
     *     status: string,
     *     statusLabel: string,
     *     totalHak: int,
     *     netPayable: int,
     *     paid: int,
     *     remaining: int,
     *     createdAt: string,
     * }>
     */
    public function settlements(): array
    {
        return Settlement::query()
            ->with('consignor:id,name,consignor_code')
            ->withSum('payments as paid', 'amount')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(function (Settlement $s): array {
                $status = (string) $s->status?->value;
                $paid = (int) $s->paid;

                return [
                    'id' => $s->id,
                    'no' => $s->settlement_no,
                    'consignor' => $s->consignor?->name ?? '—',
                    'period' => $s->period_start?->format('d M Y').' – '.$s->period_end?->format('d M Y'),
                    'status' => $status,
                    'statusLabel' => Format::statusLabel($status),
                    'statusTone' => $this->statusTone($s->status),
                    'totalHak' => $s->total_hak,
                    'netPayable' => $s->net_payable,
                    'paid' => $paid,
                    'remaining' => max(0, $s->net_payable - $paid),
                    'createdAt' => $s->created_at?->format('Y-m-d H:i') ?? '—',
                ];
            })
            ->all();
    }

    /**
     * Satu statement utuh: baris penjualan, penyesuaian, pembayaran, dan total.
     *
     * @return array{
     *     settlement: Settlement,
     *     lines: array<int, array{receiptNo: string, soldAt: string, sku: string, product: string, jual: int, fee: int, hak: int}>,
     *     adjustments: Collection<int, ConsignorLedger>,
     *     payments: array<int, array{method: string, amount: int, paidAt: string, reference: string}>,
     *     totalJual: int,
     *     totalFee: int,
     *     totalHak: int,
     * }
     */
    public function statement(Settlement $settlement): array
    {
        $rows = $settlement->ledger()
            ->with(['saleItem.sale'])
            ->get();

        $lines = $rows
            ->filter(fn (ConsignorLedger $row): bool => $row->sale_item_id !== null)
            ->map(function (ConsignorLedger $row): array {
                $item = $row->saleItem;
                $sale = $item?->sale;

                return [
                    'receiptNo' => $sale?->receipt_no ?? '—',
                    'soldAt' => $sale?->sold_at?->format('d M Y H:i') ?? '—',
                    'sku' => $item?->sku ?? '—',
                    'product' => $item?->lot?->product?->name ?? '',
                    'jual' => $item === null ? 0 : (int) $item->sell_price * (int) $item->qty,
                    'fee' => $item?->fee_toko !== null ? (int) $item->fee_toko : 0,
                    'hak' => (int) $row->amount,
                ];
            })
            ->values()
            ->all();

        $adjustments = $rows->filter(fn (ConsignorLedger $row): bool => $row->sale_item_id === null);

        $payments = $settlement->payments()
            ->orderBy('paid_at')
            ->get()
            ->map(fn ($payment): array => [
                'method' => Format::enum($payment->method),
                'amount' => (int) $payment->amount,
                'paidAt' => $payment->paid_at?->format('d M Y') ?? '—',
                'reference' => (string) ($payment->reference ?? '—'),
            ])
            ->all();

        return [
            'settlement' => $settlement,
            'lines' => $lines,
            'adjustments' => $adjustments,
            'payments' => $payments,
            'totalJual' => array_sum(array_map(fn (array $line): int => $line['jual'], $lines)),
            'totalFee' => array_sum(array_map(fn (array $line): int => $line['fee'], $lines)),
            'totalHak' => array_sum(array_map(fn (array $line): int => $line['hak'], $lines)),
        ];
    }

    /**
     * Umur hak penitip yang belum pernah dikunci ke settlement mana pun.
     *
     * Umur dihitung dari entri akrual tertua yang masih belum ter-settle.
     *
     * @return array<int, array{
     *     name: string,
     *     amount: int,
     *     oldest: string,
     *     bucket: string,
     * }>
     */
    public function aging(): array
    {
        $rows = ConsignorLedger::query()
            ->join('consignors', 'consignors.id', '=', 'consignor_ledger.consignor_id')
            ->where('consignor_ledger.type', LedgerType::SaleAccrual->value)
            ->whereNull('consignor_ledger.settlement_id')
            ->selectRaw(
                'consignors.name,
                 coalesce(sum(consignor_ledger.amount), 0) as amount,
                 min(consignor_ledger.created_at) as oldest_at'
            )
            ->groupBy('consignors.name')
            ->orderByDesc('amount')
            ->get();

        return $rows->map(function ($row): array {
            $oldestAt = $row->oldest_at;
            if (! $oldestAt instanceof Carbon && is_string($oldestAt)) {
                $oldestAt = Carbon::parse($oldestAt);
            }

            $days = $oldestAt === null
                ? 0
                : max(0, (int) now()->diffInDays($oldestAt));

            return [
                'name' => (string) $row->name,
                'amount' => (int) $row->amount,
                'oldest' => $oldestAt?->format('d M Y') ?? '—',
                'bucket' => $this->ageBucket($days),
            ];
        })->all();
    }

    private function ageBucket(int $days): string
    {
        return match (true) {
            $days <= 30 => '0–30 hari',
            $days <= 60 => '31–60 hari',
            $days <= 90 => '61–90 hari',
            default => '> 90 hari',
        };
    }

    /**
     * Warna badge yang sama untuk status yang sama, di mana pun muncul.
     */
    private function statusTone(?SettlementStatus $status): string
    {
        return match ($status) {
            SettlementStatus::Paid, SettlementStatus::Closed => 'success',
            SettlementStatus::Approved, SettlementStatus::Sent, SettlementStatus::PartiallyPaid => 'warning',
            SettlementStatus::Cancelled => 'neutral',
            default => 'info',
        };
    }
}
