<x-ui.page-header
    title="Katalog Produk"
    subtitle="Master data item Hot Wheels dengan status verifikasi & kode SKU internal."
    :crumbs="['Master Data', 'Katalog Produk']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Template impor CSV diunduh (mock)', 'info')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Impor CSV
        </button>
        <x-ui.modal title="Tambah Produk" description="Tambah/tab item ke katalog. Duplikat dideteksi non-modal." size="lg">
            <x-slot:trigger>
                <button type="button" class="btn-primary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Produk
                </button>
            </x-slot:trigger>
            <x-slot:panel>
                <form @submit.prevent="$store.toast.push('Produk tersimpan (mock)', 'success'); open = false" class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field label="Casting / Model" name="pr_model" required>
                            <input id="pr_model" class="input-base" placeholder="mis. Nissan Skyline GT-R R34">
                        </x-ui.field>
                        <x-ui.field label="Seri" name="pr_series">
                            <input id="pr_series" class="input-base" placeholder="mis. Fast &amp; Furious">
                        </x-ui.field>
                        <x-ui.field label="Tahun" name="pr_year">
                            <input id="pr_year" type="number" class="input-base" placeholder="2024">
                        </x-ui.field>
                        <x-ui.field label="Warna" name="pr_color">
                            <input id="pr_color" class="input-base" placeholder="mis. Bayside Blue">
                        </x-ui.field>
                        <x-ui.field label="Kondisi" name="pr_condition">
                            <select id="pr_condition" class="input-base">
                                <option>Mint</option>
                                <option>Clear</option>
                                <option>Used / Good</option>
                            </select>
                        </x-ui.field>
                        <x-ui.field label="Kepemilikan" name="pr_owner">
                            <select id="pr_owner" class="input-base">
                                <option value="PRIBADI">Stok Pribadi (OW00)</option>
                                <option value="TITIP">Titipan (CNxx)</option>
                            </select>
                        </x-ui.field>
                        <x-ui.field label="Harga Jual (Rp)" name="pr_price">
                            <input id="pr_price" class="input-base tabular-nums" placeholder="150000">
                        </x-ui.field>
                        <x-ui.field label="Rak Awal" name="pr_rack" hint="Kosongkan utk gunakan rak default">
                            <input id="pr_rack" class="input-base font-mono uppercase" placeholder="A-01-03">
                        </x-ui.field>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-border-subtle pt-4">
                        <button type="button" class="btn-secondary" @click="open = false">Batal</button>
                        <button type="submit" class="btn-primary">Simpan Produk</button>
                    </div>
                </form>
            </x-slot:panel>
        </x-ui.modal>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.banner tone="warning">
        <span class="font-semibold">Duplikat terdeteksi.</span>
        <span class="font-mono">CN02-HW-001 Lamborghini Huracan</span> sudah ada pada seri Car Culture. Gunakan label internal WMS bila menerima unit lain (non-modal).
    </x-ui.banner>

    <x-ui.section-card>
        <x-ui.toolbar search-placeholder="Cari casting, SKU, seri...">
            <x-slot:filters>
                <select class="input-base h-11 w-auto">
                    <option>Semua Seri</option>
                    <option>Car Culture</option>
                    <option>Fast &amp; Furious</option>
                    <option>Porsche</option>
                    <option>Honda</option>
                    <option>American Scene</option>
                </select>
                <select class="input-base h-11 w-auto">
                    <option>Semua Kondisi</option>
                    <option>Mint</option>
                    <option>Clear</option>
                    <option>Damaged</option>
                </select>
                <select class="input-base h-11 w-auto">
                    <option>Semua Pemilik</option>
                    <option>PRIBADI</option>
                    <option>TITIP</option>
                </select>
            </x-slot:filters>
        </x-ui.toolbar>

        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">SKU</th>
                    <th class="px-6 py-3 font-semibold">Produk</th>
                    <th class="px-6 py-3 font-semibold">Seri / Tahun</th>
                    <th class="px-6 py-3 font-semibold">Kondisi</th>
                    <th class="px-6 py-3 font-semibold">Pemilik</th>
                    <th class="px-6 py-3 font-semibold">Rak</th>
                    <th class="px-6 py-3 text-right font-semibold">Harga</th>
                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                @foreach ($products as $product)
                    <tr class="row-dense transition hover:bg-canvas">
                        <td class="px-6 py-3 font-mono text-sku text-text-strong">{{ $product['sku'] }}</td>
                        <td class="px-6 py-3 text-body-md font-medium text-text-strong">{{ $product['model'] }}</td>
                        <td class="px-6 py-3 text-body-sm text-text-muted">{{ $product['series'] }} · {{ $product['year'] }} · {{ $product['color'] }}</td>
                        <td class="px-6 py-3">
                            @if ($product['condition'] === 'Damaged')
                                <x-ui.badge-status type="error">{{ $product['condition'] }}</x-ui.badge-status>
                            @elseif ($product['condition'] === 'Clear')
                                <x-ui.badge-status type="info">{{ $product['condition'] }}</x-ui.badge-status>
                            @else
                                <x-ui.badge-status type="success">{{ $product['condition'] }}</x-ui.badge-status>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            <x-ui.badge-ownership
                                :type="$product['category']"
                                :consignor="$product['category'] === 'TITIP' ? substr($product['sku'], 0, 4) : null" />
                        </td>
                        <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $product['rack'] }}</td>
                        <td class="px-6 py-3 text-right text-body-md font-semibold text-text-strong tabular-nums">
                            {{ \App\Support\MockData::rupiah($product['price']) }}
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Edit produk dibuka (mock)', 'info')">Edit</button>
                                <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Cetak label dibuka (mock)', 'info')">Label</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.section-card>
</div>