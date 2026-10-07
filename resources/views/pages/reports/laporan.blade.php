<x-ui.page-header
    title="Laporan Penjualan &amp; Stok"
    subtitle="Laporan operasional: penjualan per shift/kasir/metode serta nilai stok."
    :crumbs="['Reports & Analisis', 'Laporan Penjualan & Stok']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Laporan CSV diunduh (mock)', 'success')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor CSV
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Penjualan Periode" value="Rp12,48jt" delta="24 Sep 2026" delta-tone="info" />
        <x-ui.stat-card label="Per Kasir (TOP)" value="Dewi Lestari" delta="Rp6,9jt · 12 nota" delta-tone="success" />
        <x-ui.stat-card label="Nilai Stok On-hand" value="Rp74,6jt" delta="412 unit" delta-tone="info" />
        <x-ui.stat-card label="Dead Stock (90+) " value="Rp645rb" delta="4 SKU TITIPAN" delta-tone="error" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.section-card title="Ringkasan Penjualan per Shift">
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
                        @foreach ([
                            ['s' => 'Reguler Shift 1', 'n' => 3, 'i' => 8, 't' => 1260000, 'm' => 'TUNAI'],
                            ['s' => 'Reguler Shift 2', 'n' => 4, 'i' => 10, 't' => 2955000, 'm' => 'QRIS'],
                        ] as $row)
                            <tr class="row-dense">
                                <td class="px-4 py-3 text-body-md font-medium text-text-strong">{{ $row['s'] }}</td>
                                <td class="px-4 py-3 text-center tabular-nums">{{ $row['n'] }}</td>
                                <td class="px-4 py-3 text-center tabular-nums">{{ $row['i'] }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ \App\Support\MockData::rupiah($row['t']) }}</td>
                                <td class="px-4 py-3 text-right text-body-sm text-text-muted">{{ $row['m'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.section-card>

        <x-ui.section-card title="Nilai Stok per Kepemilikan">
            <ul class="space-y-4">
                <li class="rounded-lg border border-border-subtle bg-canvas p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">Stok PRIBADI (OW00)</p>
                            <p class="text-label-sm text-text-muted">186 unit · 4 rak DISPLAY/STORAGE</p>
                        </div>
                        <p class="text-headline-md font-semibold tabular-nums">Rp31,2jt</p>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-border-subtle">
                        <div class="h-2 rounded-full bg-primary" style="width: 42%"></div>
                    </div>
                </li>
                <li class="rounded-lg border border-border-subtle bg-canvas p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">Stok TITIPAN</p>
                            <p class="text-label-sm text-text-muted">219 unit · 3 consignor</p>
                        </div>
                        <p class="text-headline-md font-semibold text-titip-text tabular-nums">Rp40,8jt</p>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-border-subtle">
                        <div class="h-2 rounded-full bg-titip-text" style="width: 55%"></div>
                    </div>
                </li>
                <li class="rounded-lg border border-border-subtle bg-canvas p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-body-md font-semibold text-text-strong">KARANTINA / RTV</p>
                            <p class="text-label-sm text-text-muted">10 unit · identifikasi &amp; staging</p>
                        </div>
                        <p class="text-headline-md font-semibold text-karantina-text tabular-nums">Rp2,6jt</p>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-border-subtle">
                        <div class="h-2 rounded-full bg-karantina-text" style="width: 3%"></div>
                    </div>
                </li>
            </ul>
        </x-ui.section-card>
    </div>
</div>