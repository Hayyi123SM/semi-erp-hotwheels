@use('App\Support\Format')

<x-ui.page-header
    title="Consignor Settlement"
    subtitle="Rekonsiliasi penjualan barang titipan dan saldo hak penitip."
    :crumbs="['Reports & Analisis', 'Consignor Settlement']"
>
    <x-slot:actions>
        <a href="{{ route('report.settlement', ['format' => 'csv']) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor CSV
        </a>
        <a href="{{ route('report.settlement', ['format' => 'xlsx']) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor XLSX
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Draft Settlement" value="{{ $summary['draft'] }}" delta="menunggu dikunci" delta-tone="info" />
        <x-ui.stat-card label="Menunggu Pembayaran" value="{{ Format::rupiah($summary['waiting']) }}" delta="sisa net payable" delta-tone="warning" />
        <x-ui.stat-card label="Dibayar (30 hari)" value="{{ Format::rupiah($summary['paid30d']) }}" delta="settlement di 30 hari terakhir" delta-tone="success" icon="M5 13l4 4L19 7" />
        <x-ui.stat-card label="Carry-over" value="{{ Format::rupiah($summary['carryOver']) }}" delta="selisih yang dibawa ke periode lain" delta-tone="error" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.section-card title="Saldo Hak per Penitip" :actions="null">
                <div class="table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Kode</th>
                                <th class="px-4 py-3 font-semibold">Penitip</th>
                                <th class="px-4 py-3 text-right font-semibold">Total Hak</th>
                                <th class="px-4 py-3 text-right font-semibold">Sudah Dibayar</th>
                                <th class="px-4 py-3 text-right font-semibold">Sisa Hak</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @forelse ($balances as $balance)
                                <tr class="row-dense">
                                    <td class="px-4 py-3 font-mono text-sku text-text-strong">{{ $balance['code'] }}</td>
                                    <td class="px-4 py-3 text-body-sm font-medium text-text-strong">{{ $balance['name'] }}</td>
                                    <td class="px-4 py-3 text-right text-body-md tabular-nums">{{ Format::rupiah($balance['accrual']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md text-text-muted tabular-nums">{{ Format::rupiah($balance['paid']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md font-semibold tabular-nums">
                                        @if ($balance['balanced'] ?? false)
                                            <span class="text-text-subtle">LUNAS</span>
                                        @else
                                            <span class="{{ $balance['balance'] > 0 ? 'text-titip-text' : 'text-text-subtle' }}">{{ Format::rupiah($balance['balance']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-body-sm text-text-muted">Belum ada entri ledger.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.section-card>

            <x-ui.section-card title="Daftar Settlement Termurah" :actions="null">
                <div class="table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">No</th>
                                <th class="px-4 py-3 font-semibold">Penitip</th>
                                <th class="px-4 py-3 font-semibold">Periode</th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 text-right font-semibold">Net Payable</th>
                                <th class="px-4 py-3 text-right font-semibold">Dibayar</th>
                                <th class="px-4 py-3 text-right font-semibold">Sisa</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @forelse ($settlements as $settlement)
                                <tr class="row-dense">
                                    <td class="px-4 py-3 font-mono text-sku text-text-strong">{{ $settlement['no'] }}</td>
                                    <td class="px-4 py-3 text-body-sm text-text-strong">{{ $settlement['consignor'] }}</td>
                                    <td class="px-4 py-3 text-body-sm text-text-muted">{{ $settlement['period'] }}</td>
                                    <td class="px-4 py-3">
                                        <x-ui.badge-status :type="$settlement['statusTone']">{{ $settlement['statusLabel'] }}</x-ui.badge-status>
                                    </td>
                                    <td class="px-4 py-3 text-right text-body-md tabular-nums">{{ Format::rupiah($settlement['netPayable']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md text-text-muted tabular-nums">{{ Format::rupiah($settlement['paid']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md font-semibold tabular-nums">{{ Format::rupiah($settlement['remaining']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-body-sm text-text-muted">Belum ada settlement yang terbentuk.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.section-card>
        </div>

        <div class="space-y-6">
            <x-ui.section-card title="Umur Hak Belum Dikunci">
                @if (count($aging) === 0)
                    <x-ui.empty-state title="Semua hak sudah dikunci" description="Tidak ada akrual yang menunggu settlement." />
                @else
                    <ul class="divide-y divide-border-subtle">
                        @foreach ($aging as $row)
                            <li class="flex items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-body-sm font-medium text-text-strong">{{ $row['name'] }}</p>
                                    <p class="text-label-sm text-text-muted">dari {{ $row['oldest'] }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-body-md font-semibold tabular-nums">{{ Format::rupiah($row['amount']) }}</span>
                                    <x-ui.badge-status :type="$row['bucket'] === '> 90 hari' ? 'error' : ($row['bucket'] === '61–90 hari' ? 'warning' : 'info')">{{ $row['bucket'] }}</x-ui.badge-status>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.section-card>

            <x-ui.banner tone="info">
                <span class="font-semibold">Invarian BR-05:</span>
                bruto titipan = hak penitip + fee toko, diverifikasi ulang di setiap laporan margin.
            </x-ui.banner>
        </div>
    </div>
</div>