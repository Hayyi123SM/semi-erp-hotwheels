<x-ui.page-header
    title="Live Stock"
    subtitle="Stok real-time per pemilik. Tab pemilik mengisolasi isolasi stok PRIBADI vs TITIPAN."
    :crumbs="['Inventory', 'Live Stock']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Ekspor CSV diunduh (mock)', 'success')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6" x-data="{ activeTab: 'semua' }">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total Unit Tersedia" value="412" delta="8 terlaris hari ini" delta-tone="success" />
        <x-ui.stat-card label="Stok PRIBADI" value="186" delta="45,1% dari total" delta-tone="info" icon="M5 13l4 4L19 7" />
        <x-ui.stat-card label="Stok TITIPAN" value="219" delta="3 penitip aktif" delta-tone="warning" icon="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
        <x-ui.stat-card label="Nilai Stok on-hand" value="Rp74,6jt" delta="+1,2% minggu ini" delta-tone="info" />
    </div>

    <x-ui.section-card>
        <div class="flex flex-col gap-4 border-b border-border-subtle px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="inline-flex items-center gap-0.5 rounded-lg bg-canvas p-1">
                @foreach ([['v' => 'semua', 'l' => 'Semua'], ['v' => 'PRIBADI', 'l' => 'PRIBADI'], ['v' => 'TITIP', 'l' => 'TITIPAN']] as $tab)
                    <button type="button"
                            class="rounded-md px-3 py-1.5 text-label-md transition"
                            :class="activeTab === @js($tab['v']) ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'"
                            @click="activeTab = @js($tab['v'])">
                        {{ $tab['l'] }}
                    </button>
                @endforeach
            </div>
            <div class="relative w-full lg:max-w-xs">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input type="search" placeholder="Scan barcode / cari SKU..." class="input-base pl-10" x-scan="$store.toast.push('SKU ' + $el.value + ' → filter stok', 'success')">
            </div>
        </div>

        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">SKU</th>
                    <th class="px-6 py-3 font-semibold">Produk</th>
                    <th class="px-6 py-3 font-semibold">Kondisi</th>
                    <th class="px-6 py-3 font-semibold">Pemilik</th>
                    <th class="px-6 py-3 text-center font-semibold">Qty</th>
                    <th class="px-6 py-3 font-semibold">Rak</th>
                    <th class="px-6 py-3 text-right font-semibold">Harga Jual</th>
                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                <template x-for="item in {{ json_encode(collect($catalog)->map(fn ($p) => ['sku' => $p['sku'], 'model' => $p['model'], 'condition' => $p['condition'], 'category' => $p['category'], 'rack' => $p['rack'], 'price' => $p['price'], 'qty' => 2])->values()->toArray()) }}">
                    <tr x-show="activeTab === 'semua' || activeTab === item.category" class="row-dense transition hover:bg-canvas">
                        <td class="px-6 py-3 font-mono text-sku text-text-strong" x-text="item.sku"></td>
                        <td class="px-6 py-3">
                            <p class="text-body-md font-medium text-text-strong" x-text="item.model"></p>
                        </td>
                        <td class="px-6 py-3 text-body-sm text-text-muted" x-text="item.condition"></td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-label-md"
                                  :class="item.category === 'TITIP'
                                      ? 'border-titip-border bg-titip-bg text-titip-text'
                                      : 'border-pribadi-border bg-pribadi-bg text-pribadi-text'">
                                <span x-text="item.category === 'TITIP' ? 'TITIP · ' + item.sku.slice(0,4) : 'PRIBADI'"></span>
                            </span>
                        </td>
                        <td class="px-6 py-3 text-center text-body-md font-semibold tabular-nums" x-text="item.qty"></td>
                        <td class="px-6 py-3 font-mono text-body-sm text-text-muted" x-text="item.rack"></td>
                        <td class="px-6 py-3 text-right text-body-md font-semibold tabular-nums" x-text="'Rp' + Number(item.price).toLocaleString('id-ID')"></td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn-ghost h-9 px-3 text-primary" @click="activeTab='semua', $store.toast.push('Open kartu stok: ' + item.sku, 'info')">Kartu</button>
                                <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Pindah rak terbuka', 'info')">Pindah</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </x-ui.section-card>
</div>