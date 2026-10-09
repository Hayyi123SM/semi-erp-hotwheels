<?php

namespace App\Services\Report;

use App\Enums\LabelStatus;
use App\Enums\QuarantineStatus;
use App\Enums\SaleStatus;
use App\Models\LabelPrintJob;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Angka-angka beranda, satu-satunya halaman yang dibaca kasir sambil bekerja.
 *
 * Kartu keuangan (margin hari ini, saldo titipan) hanya boleh tampil untuk
 * Owner; kasir melihat stok dan penjualan tanpa pernah menyentuh uang toko.
 * Service ini menerima `$viewer` dan menyembunyikan kartu itu sendiri, bukan
 * menyerahkannya ke Blade -- keputusan yang sama dipakai di halaman lain
 * (`visible()` pada kolom HPP), supaya "siapa boleh lihat uang" tidak terpecah
 * menjadi tebakan per template.
 */
class DashboardService
{
    /**
     * Empat kartu di baris atas beranda.
     *
     * @return array{
     *     penjualanHariIni: int,
     *     notaHariIni: int,
     *     pertumbuhanPenjualan: float|null,
     *     marginHariIni: int|null,
     *     saldoTitipan: int|null,
     *     penitipBerhutang: int|null,
     *     unitStok: int,
     *     stokMenipis: int,
     * }
     */
    public function cards(?User $viewer): array
    {
        $valuation = (new StockReportService)->valuation();

        $cards = [
            'penjualanHariIni' => null,
            'notaHariIni' => null,
            'pertumbuhanPenjualan' => null,
            'marginHariIni' => null,
            'saldoTitipan' => null,
            'penitipBerhutang' => null,
            'unitStok' => $valuation['units'],
            'stokMenipis' => $valuation['lowStock'],
        ];

        $today = $this->paidStats(now()->toDateString(), now()->toDateString());
        $yesterday = $this->paidStats(now()->subDay()->toDateString(), now()->subDay()->toDateString());

        $cards['penjualanHariIni'] = $today['total'];
        $cards['notaHariIni'] = $today['nota'];

        if ($yesterday['total'] > 0) {
            $cards['pertumbuhanPenjualan'] = round(($today['total'] - $yesterday['total']) / $yesterday['total'] * 100, 1);
        }

        if ($viewer?->isOwner()) {
            $margin = (new MarginReportService)->summary(now()->toDateString(), now()->toDateString());
            $owed = array_values(array_filter(
                (new SettlementReportService)->balances(),
                fn (array $b): bool => $b['balance'] > 0,
            ));

            $cards['marginHariIni'] = $margin['pendapatanToko'];
            $cards['saldoTitipan'] = array_sum(array_column($owed, 'balance'));
            $cards['penitipBerhutang'] = count($owed);
        }

        return $cards;
    }

    /**
     * Peringatan beranda: label yang belum dikonfirmasi dan karantina terbuka.
     *
     * @return array{belumBerlabel: int, karantina: int, karantinaAging: int}
     */
    public function warnings(): array
    {
        $quarantine = $this->quarantineSummary();

        return [
            'belumBerlabel' => $this->unconfirmedLabels(),
            'karantina' => $quarantine['count'],
            'karantinaAging' => $quarantine['oldestDays'],
        ];
    }

    /**
     * Ringkasan kasus karantina terbuka: jumlahnya dan umur kasus tertua.
     *
     * Satu definisi untuk dashboard, header, dan sidebar supaya angka "kasus
     * menunggu verifikasi" tidak bisa berbeda antar tempat.
     *
     * @return array{count: int, oldestDays: int}
     */
    public function quarantineSummary(): array
    {
        $oldest = QuarantineCase::query()
            ->whereIn('status', QuarantineStatus::openValues())
            ->orderBy('created_at')
            ->first();

        return [
            'count' => QuarantineCase::query()->whereIn('status', QuarantineStatus::openValues())->count(),
            'oldestDays' => $oldest === null ? 0 : max(0, (int) $oldest->created_at->diffInDays(now())),
        ];
    }

    /**
     * Label hasil cetak yang belum dikonfirmasi printer.
     *
     * Definisi tunggal "antrean konfirmasi", dipakai banner dashboard dan
     * pusat notifikasi topbar supaya keduanya tidak bisa berbeda.
     */
    public function unconfirmedLabels(): int
    {
        return LabelPrintJob::query()
            ->where('status', LabelStatus::Sent->value)
            ->whereNull('confirmed_at')
            ->count();
    }

    /**
     * Pemakaian rak untuk beranda, dan ambang warna yang sama di semua rak.
     *
     * @return array<int, array{code: string, items: int, capacity: int, usage: int}>
     */
    public function rackCapacity(): array
    {
        return Rack::query()
            ->where('is_active', true)
            ->withCount(['stockLots as items' => fn ($q) => $q->where('qty_on_hand', '>', 0)])
            ->orderBy('code')
            ->get()
            ->map(fn (Rack $rack): array => [
                'code' => $rack->code,
                'items' => $rack->items,
                'capacity' => $rack->capacity,
                'usage' => $rack->capacity > 0 ? min(100, (int) round($rack->items / $rack->capacity * 100)) : 0,
            ])
            ->all();
    }

    /**
     * Penitip dengan hak belum dibayar terbesar.
     *
     * @return array<int, array{consignorId: int, code: string, name: string, accrual: int, paid: int, balance: int}>
     */
    public function topConsignors(): array
    {
        $owed = array_filter(
            (new SettlementReportService)->balances(),
            fn (array $b): bool => $b['balance'] > 0,
        );

        usort($owed, fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        return array_slice(array_values($owed), 0, 3);
    }

    /**
     * Aktivitas terakhir dari tabel gerakan stok.
     *
     * @return array<int, array{time: string, text: string}>
     */
    public function activities(): array
    {
        return StockMovement::query()
            ->with(['lot', 'actor:id,name'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (StockMovement $m): array => [
                'time' => $m->created_at?->format('H:i') ?? '—',
                'text' => $this->activityText($m),
            ])
            ->all();
    }

    private function activityText(StockMovement $movement): string
    {
        $label = $movement->type?->label() ?? (string) $movement->type;
        $sku = $movement->lot?->sku;
        $actor = $movement->actor?->name;

        $text = $sku === null ? $label : $label.' '.$sku;

        if ($movement->qty_delta !== 0) {
            $text .= ' ('.($movement->qty_delta > 0 ? '+' : '').$movement->qty_delta.')';
        }

        return $actor === null
            ? $text
            : $text.' · '.$actor;
    }

    /**
     * @return array{total: int, nota: int}
     */
    private function paidStats(string $from, string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $row = Sale::query()
            ->where('status', SaleStatus::Paid->value)
            ->whereBetween('sold_at', [$start, $end])
            ->selectRaw('coalesce(sum(total), 0) as total, count(*) as nota')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'nota' => (int) ($row->nota ?? 0),
        ];
    }
}
