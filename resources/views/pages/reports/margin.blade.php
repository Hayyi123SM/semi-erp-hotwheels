@use('App\Support\Format')

<x-ui.page-header
    title="Analisis Margin & Fee"
    subtitle="Profitabilitas stok PRIBADI vs take-rate fee titipan. Hanya Owner."
    :crumbs="['Reports & Analisis', 'Profit Margin vs Fee']"
>
    <x-slot:actions>
        <a href="{{ route('report.margin', array_merge(request()->query(), ['format' => 'csv'])) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor CSV
        </a>
        <a href="{{ route('report.margin', array_merge(request()->query(), ['format' => 'xlsx'])) }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor XLSX
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.filter-bar
        :action="route('report.margin')"
        :reset-url="route('report.margin', ['d' => $dimension])"
        :active="request()->has('from') || request()->has('to')"
    >
        {{-- Dimensi adalah navigasi, bukan nilai form: tautannya memuat ulang
             halaman dan membawa `from`/`to` yang sedang aktif lewat
             `request()->except('d')`. `d` tetap dikirim sebagai input tersembunyi
             supaya Terapkan tidak membuang dimensi yang sedang dipilih. --}}
        <input type="hidden" name="d" value="{{ $dimension }}">

        <div class="flex flex-col gap-1">
            <span class="text-label-sm text-text-subtle">Dimensi</span>
            <div class="inline-flex h-11 items-center gap-0.5 rounded-lg bg-canvas p-1">
                @foreach (['seri' => 'Seri', 'penitip' => 'Penitip', 'sku' => 'SKU', 'hari' => 'Hari'] as $value => $label)
                    <a href="{{ route('report.margin', array_merge(request()->except('d'), ['d' => $value])) }}"
                       @if ($dimension === $value) aria-current="true" @endif
                       class="rounded-md px-3 py-1.5 text-label-md {{ $dimension === $value ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted transition hover:text-text-strong' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <x-ui.date-range :from="$from" :to="$to" />
    </x-ui.filter-bar>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Bruto Periode" value="{{ Format::rupiah($summary['bruto']) }}" delta="{{ $summary['nota'] }} nota" delta-tone="info" />
        <x-ui.stat-card label="Laba PRIBADI" value="{{ Format::rupiah($summary['labaPribadi']) }}" delta="{{ $summary['sharePribadi'] }}% dari pendapatan" delta-tone="success" />
        <x-ui.stat-card label="Fee Titipan" value="{{ Format::rupiah($summary['feeTitipan']) }}" delta="{{ $summary['shareFee'] }}% take-rate" delta-tone="info" />
        <x-ui.stat-card label="Pendapatan Toko" value="{{ Format::rupiah($summary['pendapatanToko']) }}" delta="laba pribadi + fee titipan" delta-tone="success" />
    </div>

    <x-ui.banner :tone="$reconciliation['ok'] ? 'success' : 'error'">
        <span class="font-semibold">Invariant BR-05:</span>
        @if ($reconciliation['ok'])
            bruto titipan = hak penitip + fee toko terverifikasi
            <b>{{ Format::rupiah($reconciliation['brutoTitipan']) }}</b>.
        @else
            selisih <b>{{ Format::rupiah($reconciliation['selisih']) }}</b> — ada penjualan titipan yang snapshootnya tidak utuh.
        @endif
    </x-ui.banner>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.section-card title="Pendapatan per Dimensi" class="lg:col-span-2">
            @php
                $max = max(array_column($dimensions, 'pendapatan') ?: [1]);
            @endphp
            @if (count($dimensions) === 0)
                <x-ui.empty-state title="Belum ada baris dimensi" description="Belum ada penjualan di periode ini." />
            @else
                <div class="space-y-3">
                    @foreach ($dimensions as $bar)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-body-sm">
                                <span class="truncate text-text-strong">{{ $bar['label'] }}</span>
                                <span class="text-text-muted tabular-nums">{{ Format::rupiah($bar['pendapatan']) }} · {{ $bar['share'] }}%</span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-canvas">
                                <div class="h-2.5 rounded-full bg-primary" style="width: {{ max(2, round($bar['pendapatan'] / $max * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">{{ ucfirst($dimension) }}</th>
                                <th class="px-4 py-3 text-right font-semibold">Bruto</th>
                                <th class="px-4 py-3 text-right font-semibold">Laba</th>
                                <th class="px-4 py-3 text-right font-semibold">Fee</th>
                                <th class="px-4 py-3 text-right font-semibold">Hak</th>
                                <th class="px-4 py-3 text-right font-semibold">Pendapatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach ($dimensions as $row)
                                <tr class="row-dense">
                                    <td class="px-4 py-3 text-body-sm font-medium text-text-strong">{{ $row['label'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ Format::rupiah($row['bruto']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ Format::rupiah($row['laba']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ Format::rupiah($row['fee']) }}</td>
                                    <td class="px-4 py-3 text-right text-titip-text tabular-nums">{{ Format::rupiah($row['hak']) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Format::rupiah($row['pendapatan']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.section-card>

        <x-ui.section-card title="Take-rate per Skema">
            <div class="space-y-4">
                @forelse ($takeRates as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-body-sm">
                            <span class="text-text-strong">{{ $row['label'] }}</span>
                            <span class="text-text-muted tabular-nums">{{ $row['rate'] }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-canvas">
                            <div class="h-2.5 rounded-full bg-titip-text" style="width: {{ min(100, $row['rate']) }}%"></div>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state title="Tidak ada skema terjual" description="Penjualan barang titipan akan muncul di sini." />
                @endforelse
                <p class="pt-1 text-label-sm text-text-subtle">Take-rate dihitung dari total fee ÷ total nilai terjual per skema.</p>
            </div>
        </x-ui.section-card>
    </div>

    @if (count($insights['topSku']) || count($insights['sellThrough']) || count($insights['deadStock']) || $insights['negativeMargin']['lines'])
        <x-ui.section-card title="Insight">
            <ul class="divide-y divide-border-subtle">
                @forelse ($insights['topSku'] as $sku)
                    <li class="flex items-start justify-between gap-4 py-3">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">{{ $sku['sku'] }}</p>
                            <p class="text-body-sm text-text-muted">{{ $sku['qty'] }} unit · {{ Format::rupiah($sku['bruto']) }}</p>
                        </div>
                        <x-ui.badge-status type="info">Top SKU</x-ui.badge-status>
                    </li>
                @empty
                    @if (! count($insights['sellThrough']) && ! count($insights['deadStock']) && ! $insights['negativeMargin']['lines'])
                        <x-ui.empty-state title="Tidak ada insight anomali" description="Semua posisi sehat di periode ini." />
                    @endif
                @endforelse

                @foreach ($insights['sellThrough'] as $consignor)
                    @if ($consignor['received'] > 0)
                        <li class="flex items-start justify-between gap-4 py-3">
                            <div>
                                <p class="text-body-md font-semibold text-text-strong">{{ $consignor['name'] }}</p>
                                <p class="text-body-sm text-text-muted">Sell-through {{ $consignor['sold'] }} dari {{ $consignor['received'] }} unit</p>
                            </div>
                            <span class="text-body-md font-semibold tabular-nums">{{ $consignor['rate'] !== null ? $consignor['rate'].'%' : '—' }}</span>
                        </li>
                    @endif
                @endforeach

                @foreach ($insights['deadStock'] as $dead)
                    <li class="flex items-start justify-between gap-4 py-3">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">{{ $dead['sku'] }}</p>
                            <p class="text-body-sm text-text-muted">{{ $dead['product'] }} · {{ $dead['owner'] }} · diam sejak {{ $dead['since'] }}</p>
                        </div>
                        <x-ui.badge-status type="error">Dead stock</x-ui.badge-status>
                    </li>
                @endforeach

                @if ($insights['negativeMargin']['lines'])
                    <li class="flex items-start justify-between gap-4 py-3">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">Margin negatif</p>
                            <p class="text-body-sm text-text-muted">{{ $insights['negativeMargin']['lines'] }} baris harga jual di bawah hak penitip, menelan fee toko.</p>
                        </div>
                        <x-ui.badge-status type="warning">{{ Format::rupiah($insights['negativeMargin']['amount']) }}</x-ui.badge-status>
                    </li>
                @endif
            </ul>
        </x-ui.section-card>
    @endif
</div>
