@use('App\Support\Format')

<x-ui.page-header
    title="Laporan Penjualan & Stok"
    subtitle="Penjualan per shift/kasir/metode serta posisi stok periode ini."
    :crumbs="['Reports & Analisis', 'Laporan Penjualan & Stok']"
>
    <x-slot:actions>
        <a href="{{ route('report.laporan', array_merge(request()->query(), ['format' => 'csv'])) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor CSV
        </a>
        <a href="{{ route('report.laporan', array_merge(request()->query(), ['format' => 'xlsx'])) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor XLSX
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.filter-bar
        :action="route('report.laporan')"
        :reset-url="route('report.laporan')"
        :active="request()->has('from') || request()->has('to')"
    >
        <x-ui.date-range :from="$from" :to="$to" />
    </x-ui.filter-bar>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Penjualan Periode" value="{{ Format::rupiah($summary['total']) }}" delta="{{ $summary['nota'] }} nota · {{ $summary['items'] }} item" delta-tone="info" />
        @if ($summary['topKasir'])
            <x-ui.stat-card label="Per Kasir (TOP)" value="{{ $summary['topKasir']['name'] }}" delta="{{ Format::rupiah($summary['topKasir']['total']) }} · {{ $summary['topKasir']['nota'] }} nota" delta-tone="success" />
        @else
            <x-ui.stat-card label="Per Kasir (TOP)" value="—" delta="belum ada transaksi" delta-tone="info" />
        @endif
        <x-ui.stat-card label="Nilai Stok On-hand" value="{{ Format::rupiah($valuation['ownValue'] + $valuation['consignValue']) }}" delta="{{ $valuation['units'] }} unit" delta-tone="info" />
        <x-ui.stat-card label="Dead Stock (90+)" value="{{ count($deadStock) }} SKU" delta="{{ count(array_filter($deadStock, fn ($row) => $row['owner'] !== 'PRIBADI')) }} titipan" delta-tone="{{ count($deadStock) ? 'error' : 'success' }}" />
    </div>

    <section class="space-y-6">
        <h2 class="text-label-sm font-semibold uppercase tracking-wide text-text-subtle">Penjualan Periode</h2>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.section-card title="Ringkasan Penjualan per Shift" class="lg:col-span-2">
                @if (count($shifts) === 0)
                    <x-ui.empty-state title="Tidak ada penjualan di periode ini" description="Ubah rentang tanggal untuk melihat shift yang berbeda." />
                @else
                    <div class="table-scroll">
                        <table class="w-full min-w-max text-left">
                            <thead class="thead-dense">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Shift</th>
                                    <th class="px-4 py-3 text-center font-semibold">Nota</th>
                                    <th class="px-4 py-3 text-center font-semibold">Item</th>
                                    <th class="px-4 py-3 text-right font-semibold">Total</th>
                                    <th class="px-4 py-3 text-right font-semibold">Metode utama</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-subtle">
                                @foreach ($shifts as $shift)
                                    <tr class="row-dense">
                                        <td class="px-4 py-3 text-body-md font-medium text-text-strong">{{ $shift['label'] }}</td>
                                        <td class="px-4 py-3 text-center tabular-nums">{{ $shift['nota'] }}</td>
                                        <td class="px-4 py-3 text-center tabular-nums">{{ $shift['items'] }}</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Format::rupiah($shift['total']) }}</td>
                                        <td class="px-4 py-3 text-right text-body-sm text-text-muted">{{ $shift['metode'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.section-card>

            <x-ui.section-card title="Pembayaran per Metode">
                @if (count($summary['perMetode']) === 0)
                    <x-ui.empty-state title="Belum ada pembayaran" description="Pembayaran akan muncul di sini begitu ada penjualan." />
                @else
                    <div class="space-y-4">
                        @foreach ($summary['perMetode'] as $method)
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-body-md font-semibold text-text-strong">{{ $method['label'] }}</p>
                                    <p class="text-label-sm text-text-muted">{{ $method['nota'] }} nota</p>
                                </div>
                                <p class="text-body-md font-semibold tabular-nums">{{ Format::rupiah($method['amount']) }}</p>
                            </div>
                            @if (!$loop->last)
                                <div class="border-b border-border-subtle"></div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </x-ui.section-card>
        </div>

        <x-ui.section-card title="Transaksi Periode" :pad="false">
            <x-ui.data-table :table="$table">
                {{-- Strip tabel ini sendiri kosong di desktop -- satu-satunya
                     kontrolnya adalah tombol Urutkan yang `md:hidden` -- jadi
                     ringkasannya ditulis di sini supaya barisnya berisi sesuatu,
                     bukan pita kosong. --}}
                <x-slot:summary>{{ $summary['nota'] }} nota · {{ $summary['items'] }} item di periode ini</x-slot:summary>

                {{-- Rentang tanggal dimiliki halaman, bukan tabel: tanpa dua
                     input ini pencarian di dalam tabel akan membangun query
                     hanya dari kontrolnya sendiri dan diam-diam kembali ke 30
                     hari terakhir, sementara kartu KPI tetap di periode yang
                     dipilih. --}}
                <x-slot:actions>
                    <input type="hidden" name="from" value="{{ $from }}">
                    <input type="hidden" name="to" value="{{ $to }}">
                </x-slot:actions>
            </x-ui.data-table>
        </x-ui.section-card>
    </section>

    <section class="space-y-6">
        <h2 class="text-label-sm font-semibold uppercase tracking-wide text-text-subtle">Pergerakan & Posisi Stok</h2>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.section-card title="Gerakan Stok per Jenis" class="lg:col-span-2">
                @if (count($movements) === 0)
                    <x-ui.empty-state title="Belum ada gerakan di periode ini" description="Stock in, penjualan, dan penyesuaian akan tercatat di sini." />
                @else
                    <div class="table-scroll">
                        <table class="w-full min-w-max text-left">
                            <thead class="thead-dense">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Jenis</th>
                                    <th class="px-4 py-3 text-right font-semibold">Baris</th>
                                    <th class="px-4 py-3 text-right font-semibold">Qty delta</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-subtle">
                                @foreach ($movements as $movement)
                                    <tr class="row-dense">
                                        <td class="px-4 py-3 text-body-sm text-text-strong">{{ $movement['label'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $movement['lines'] }}</td>
                                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $movement['qty'] > 0 ? '+' : '' }}{{ $movement['qty'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.section-card>

            <x-ui.section-card title="Nilai Stok per Kepemilikan">
                <ul class="space-y-4">
                    <li class="rounded-lg border border-border-subtle bg-canvas p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-body-md font-semibold text-text-strong">Stok PRIBADI</p>
                                <p class="text-label-sm text-text-muted">{{ $valuation['ownUnits'] }} unit</p>
                            </div>
                            <p class="text-headline-md font-semibold tabular-nums">{{ Format::rupiah($valuation['ownValue']) }}</p>
                        </div>
                        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-border-subtle">
                            <div class="h-2 rounded-full bg-primary" style="width: {{ min(100, $valuation['units'] > 0 ? round($valuation['ownUnits'] / $valuation['units'] * 100) : 0) }}%"></div>
                        </div>
                    </li>
                    <li class="rounded-lg border border-border-subtle bg-canvas p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-body-md font-semibold text-text-strong">Stok TITIPAN</p>
                                <p class="text-label-sm text-text-muted">{{ $valuation['consignUnits'] }} unit · {{ $valuation['consignors'] }} penitip</p>
                            </div>
                            <p class="text-headline-md font-semibold text-titip-text tabular-nums">{{ Format::rupiah($valuation['consignValue']) }}</p>
                        </div>
                        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-border-subtle">
                            <div class="h-2 rounded-full bg-titip-text" style="width: {{ min(100, $valuation['units'] > 0 ? round($valuation['consignUnits'] / $valuation['units'] * 100) : 0) }}%"></div>
                        </div>
                    </li>
                    <li class="flex items-center justify-between rounded-lg border border-border-subtle bg-canvas p-4">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">Stok Menipis (≤ 1 unit)</p>
                            <p class="text-label-sm text-text-muted">Perlu segera ditambah</p>
                        </div>
                        <x-ui.badge-status :type="$valuation['lowStock'] ? 'warning' : 'success'">{{ $valuation['lowStock'] }} SKU</x-ui.badge-status>
                    </li>
                </ul>
            </x-ui.section-card>
        </div>

        @if (count($deadStock))
            <x-ui.section-card title="Dead Stock (tidak keluar 90+ hari)">
                <div class="table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">SKU</th>
                                <th class="px-4 py-3 font-semibold">Produk</th>
                                <th class="px-4 py-3 font-semibold">Pemilik</th>
                                <th class="px-4 py-3 text-right font-semibold">Qty</th>
                                <th class="px-4 py-3 font-semibold">Masuk</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach ($deadStock as $row)
                                <tr class="row-dense">
                                    <td class="px-4 py-3 font-mono text-sku text-text-strong">{{ $row['sku'] }}</td>
                                    <td class="px-4 py-3 text-body-sm">{{ $row['product'] }}</td>
                                    <td class="px-4 py-3 text-body-sm text-text-muted">{{ $row['owner'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $row['qty'] }}</td>
                                    <td class="px-4 py-3 text-body-sm text-text-muted">{{ $row['since'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.section-card>
        @endif
    </section>
</div>
