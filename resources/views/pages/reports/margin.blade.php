<x-ui.page-header
    title="Analisis Margin &amp; Fee"
    subtitle="Profitabilitas stok PRIBADI vs take-rate fee titipan. Hanya Owner &amp; Manager."
    :crumbs="['Reports & Analisis', 'Profit Margin vs Fee']"
>
</x-ui.page-header>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-headline-sm font-semibold text-text-strong">Periode Konsolidasi · Sep 2026</p>
            <p class="text-label-sm text-text-muted">Dimensi: Seri · Penitip · SKU · Periode</p>
        </div>
        <x-ui.segmented
            :options="[
                ['value' => 'seri', 'label' => 'Seri'],
                ['value' => 'penitip', 'label' => 'Penitip'],
                ['value' => 'sku', 'label' => 'SKU'],
            ]"
            selected="seri" />
    </div>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Margin Kotor (PRIBADI)" value="Rp2.410.000" delta="38,2% dari penjualan" delta-tone="success" />
        <x-ui.stat-card label="Total Fee Titipan" value="Rp884.000" delta="Take-rate 19,8%" delta-tone="info" icon="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
        <x-ui.stat-card label="Laba Bersih (gabungan)" value="Rp3.294.000" delta="+12,4% vs Sep norm" delta-tone="success" />
        <x-ui.stat-card label="Invariant Check" value="OK" delta="BR-05 terverifikasi" delta-tone="success" icon="M5 13l4 4L19 7" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.section-card title="Take-rate per Skema">
            <div class="space-y-4">
                @foreach ([
                    ['s' => 'Persentase (CN01 · 20%)', 'v' => 62.5, 'c' => 'bg-primary'],
                    ['s' => 'Nett (CN02 · Rp65.000)', 'v' => 27.1, 'c' => 'bg-info-text'],
                    ['s' => 'Flat (CN03 · Rp8.000)', 'v' => 10.4, 'c' => 'bg-titip-text'],
                ] as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-body-sm">
                            <span class="text-text-strong">{{ $row['s'] }}</span>
                            <span class="text-text-muted tabular-nums">{{ $row['v'] }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-canvas">
                            <div class="h-2.5 rounded-full {{ $row['c'] }}" style="width: {{ $row['v'] }}%"></div>
                        </div>
                    </div>
                @endforeach
                <p class="pt-1 text-label-sm text-text-subtle">Take-rate dihitung dari total fee ÷ total nilai terjual per skema.</p>
            </div>
        </x-ui.section-card>

        <x-ui.section-card title="Margin per Seri (bar chart)">
            <div class="flex h-56 items-end gap-4 px-2">
                @foreach ([
                    ['l' => 'FF', 'v' => 92], ['l' => 'CC', 'v' => 64], ['l' => 'Porsche', 'v' => 48],
                    ['l' => 'Honda', 'v' => 38], ['l' => 'J-Imp', 'v' => 29], ['l' => 'Amer.', 'v' => 21],
                ] as $i => $bar)
                    <div class="flex flex-1 flex-col items-center gap-2">
                        <span class="text-label-sm text-text-muted tabular-nums">{{ $bar['v'] }}%</span>
                        <div class="w-full rounded-t-lg {{ ($i % 2 === 0) ? 'bg-primary' : 'bg-primary-soft' }}" style="height: {{ $bar['v'] * 40 }}px"></div>
                        <span class="text-label-sm text-text-subtle">{{ $bar['l'] }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-label-sm text-text-subtle">F&amp;F (Fast &amp; Furious) dominan margin tertinggi dengan take-rate 20%.</p>
        </x-ui.section-card>
    </div>

    <x-ui.section-card title="Insight Anomali">
        <ul class="divide-y divide-border-subtle">
            @foreach ([
                ['t' => 'CN02 netting mendekati batas', 'd' => 'Hak penitip CN02 netting Rp65.000 mendekati harga jual (46%). Pertimbangkan ubah skema.', 'badge' => 'Warning', 'tone' => 'warning'],
                ['t' => 'Dead stock 90+ hari', 'd' => '4 SKU TITIPAN tanpa transaksi: nilai Rp645.000. Rekomendasi RTV.', 'badge' => 'RTV', 'tone' => 'error'],
                ['t' => 'HPP negatif margin', 'd' => 'Baris ke-2 Stock In Pribadi (INV-2026-0917) harga jual < HPP.', 'badge' => 'Fix', 'tone' => 'info'],
            ] as $insight)
                <li class="flex items-start justify-between gap-4 py-3">
                    <div>
                        <p class="text-body-md font-semibold text-text-strong">{{ $insight['t'] }}</p>
                        <p class="text-body-sm text-text-muted">{{ $insight['d'] }}</p>
                    </div>
                    <x-ui.badge-status :type="$insight['tone']">{{ $insight['badge'] }}</x-ui.badge-status>
                </li>
            @endforeach
        </ul>
    </x-ui.section-card>
</div>