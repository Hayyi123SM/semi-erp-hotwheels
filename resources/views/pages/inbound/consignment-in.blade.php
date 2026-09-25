<x-ui.page-header
    title="Consignment In"
    subtitle="Penerimaan barang titipan (CNxx). Grid padat + pratinjau fee penitip real-time sebelum commit."
    :crumbs="['Inbound', 'Consignment In']"
>
</x-ui.page-header>

<div class="space-y-6" x-data="bulkGrid({{ json_encode([
    [
        'sku' => 'CN01-HW-004', 'product' => 'Honda Prelude Type-G', 'series' => 'J-Import',
        'condition' => 'Mint', 'qty' => 2, 'price' => 175000, 'scheme' => 'percent', 'schemeValue' => 20, 'rack' => 'B-01-05',
    ],
    [
        'sku' => 'CN01-HW-005', 'product' => 'Subaru Impreza WRX STI 22B', 'series' => 'Fast & Furious',
        'condition' => 'Mint', 'qty' => 1, 'price' => 210000, 'scheme' => 'percent', 'schemeValue' => 20, 'rack' => 'B-01-05',
    ],
]) }})"
     @keydown.window.ctrl.d.prevent="lastActive >= 0 && duplicateRow(lastActive)"
     @keydown.window.ctrl.backspace.prevent="lastActive >= 0 && removeRow(lastActive)">

    <!-- Tier 1: Consignor Meta Header -->
    <div class="card card-pad">
        <div class="grid gap-5 lg:grid-cols-[1fr_auto]">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field label="Penitip (Consignor)" name="ci_consignor" required>
                    <select id="ci_consignor" class="input-base" @change="$store.toast.push('Consignor dipilih', 'info')">
                        @foreach ($consignors as $consignor)
                            <option value="{{ $consignor['code'] }}">{{ $consignor['name'] }} ({{ $consignor['code'] }})</option>
                        @endforeach
                        <option value="new">+ Tambah penitip baru...</option>
                    </select>
                </x-ui.field>
                <x-ui.field label="Tanggal Terima" name="ci_date">
                    <input id="ci_date" type="date" class="input-base" value="2026-09-24">
                </x-ui.field>
                <x-ui.field label="Ukuran Label Thermal" name="ci_label">
                    <select id="ci_label" class="input-base">
                        <option>3×2 cm</option>
                        <option>4×3 cm</option>
                    </select>
                </x-ui.field>
                <x-ui.field label="Referensi / Drop-off" name="ci_ref">
                    <input id="ci_ref" class="input-base" placeholder="Kosongkan utk otomatis">
                </x-ui.field>
            </div>
            <div class="flex items-center gap-3 rounded-lg bg-primary-soft px-4 py-3">
                <div>
                    <p class="text-label-sm text-text-muted">Fee preview (CN01 · 20%)</p>
                    <p class="text-currency-display text-primary tabular-nums" x-text="'Rp' + Math.round(totalValue() * 0.2).toLocaleString('id-ID')">Rp0</p>
                </div>
                <svg class="h-6 w-6 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
        </div>
    </div>

    <!-- Tier 2: Batch Entry Grid -->
    <x-ui.section-card pad="false">
        <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary" @click="addRow()">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Baris
                </button>
                <button type="button" class="btn-secondary" @click="onPaste($event)">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3"/></svg>
                    Tempel Spreadsheet
                </button>
            </div>
            <p class="text-label-sm text-text-subtle">
                <span class="mr-1 inline-block rounded bg-canvas px-1.5 py-0.5 font-mono">Ctrl+D</span> duplikat
                · <span class="mr-1 inline-block rounded bg-canvas px-1.5 py-0.5 font-mono">Ctrl+Backspace</span> hapus
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[1080px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-3 py-3 font-semibold">Produk / SKU</th>
                        <th class="px-3 py-3 font-semibold">Seri</th>
                        <th class="px-3 py-3 font-semibold">Kondisi</th>
                        <th class="px-3 py-3 text-center font-semibold">Qty</th>
                        <th class="px-3 py-3 text-right font-semibold">Harga Satuan</th>
                        <th class="px-3 py-3 text-center font-semibold">Skema</th>
                        <th class="px-3 py-3 text-right font-semibold">Nilai Skema</th>
                        <th class="px-3 py-3 font-semibold">Rak</th>
                        <th class="px-3 py-3 text-center font-semibold">Pilihan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    <template x-for="(row, index) in rows" :key="index">
                        <tr class="h-12 border-b border-border-subtle transition hover:bg-canvas">
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <input x-model="row.product" placeholder="Scan / ketik..."
                                           class="w-48 bg-transparent outline-none"
                                           @focus="lastActive = index" x-scan="$store.toast.push('SKU ' + $el.value + ' di-resolve', 'success')">
                                    <span class="font-mono text-sku text-text-subtle" x-text="row.sku"></span>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <input x-model="row.series" class="w-28 bg-transparent outline-none" @focus="lastActive = index">
                            </td>
                            <td class="px-3 py-2">
                                <select x-model="row.condition" class="w-24 rounded-lg border border-border-subtle bg-surface-lowest px-1.5 py-1 text-body-sm" @focus="lastActive = index">
                                    <option>Mint</option>
                                    <option>Clear</option>
                                    <option>Used</option>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="number" x-model.number="row.qty" class="w-14 rounded-lg border border-border-subtle bg-surface-lowest text-center tabular-nums" @focus="lastActive = index">
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input type="number" x-model.number="row.price" class="w-28 rounded-lg border border-border-subtle bg-surface-lowest text-right tabular-nums" @focus="lastActive = index">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <div class="inline-flex items-center gap-0.5 rounded-lg bg-canvas p-0.5" x-data>
                                    <button type="button" :class="row.scheme === 'percent' ? 'bg-text-strong text-white' : 'text-text-muted'" class="rounded px-2 py-1 text-label-sm transition" @click="row.scheme = 'percent'">%</button>
                                    <button type="button" :class="row.scheme === 'nett' ? 'bg-text-strong text-white' : 'text-text-muted'" class="rounded px-2 py-1 text-label-sm transition" @click="row.scheme = 'nett'">Nett</button>
                                    <button type="button" :class="row.scheme === 'flat' ? 'bg-text-strong text-white' : 'text-text-muted'" class="rounded px-2 py-1 text-label-sm transition" @click="row.scheme = 'flat'">Flat</button>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input type="number" x-model.number="row.schemeValue" :placeholder="row.scheme === 'percent' ? '%' : 'Rp/unit'"
                                       class="w-24 rounded-lg border border-border-subtle bg-surface-lowest text-right tabular-nums">
                            </td>
                            <td class="px-3 py-2">
                                <input x-model="row.rack" placeholder="auto"
                                       class="w-24 bg-transparent font-mono uppercase outline-none" @focus="lastActive = index">
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-center gap-1">
                                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition hover:bg-canvas" @click="duplicateRow(index)" title="Duplikat (Ctrl+D)">
                                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h9a2 2 0 002-2v-3m3-3H9a2 2 0 00-2 2v9a2 2 0 002 2h9a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
                                    </button>
                                    <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-error-text transition hover:bg-error-bg" @click="removeRow(index)" title="Hapus (Ctrl+Backspace)">
                                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="9">
                            <x-ui.empty-state title="Belum ada baris" description="Klik Tambah Baris atau tempel data dari spreadsheet (tab-separated)." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-t border-border-subtle bg-canvas px-6 py-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-6 text-body-sm">
                    <span class="text-text-muted">Total Baris: <b class="text-text-strong" x-text="rows.length"></b></span>
                    <span class="text-text-muted">Total Unit: <b class="text-text-strong tabular-nums" x-text="totalQty()"></b></span>
                    <span class="text-text-muted">Nilai Jual: <b class="text-text-strong tabular-nums" x-text="'Rp' + totalValue().toLocaleString('id-ID')"></b></span>
                    <span class="text-text-muted">Fee Penitip: <b class="text-titip-text tabular-nums" x-text="'Rp' + Math.round(totalValue()*0.2).toLocaleString('id-ID')"></b></span>
                </div>
            </div>
        </div>
    </x-ui.section-card>

    <!-- Tier 3: Sticky Commmit / Print Bar -->
    <div class="sticky bottom-0 z-10 rounded-xl border border-border-subtle bg-surface-lowest px-5 py-4 sticky-bar">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <label class="flex cursor-pointer items-center gap-3 text-body-sm text-text-strong">
                <input type="checkbox" class="h-5 w-5 rounded border-border-strong text-primary focus:ring-primary/30">
                Saya telah memverifikasi fisik unit sesuai baris (&nbsp;<b class="tabular-nums" x-text="totalQty()"></b>&nbsp;unit)
            </label>
            <div class="flex flex-wrap items-center gap-3">
                <div class="text-right">
                    <p class="text-label-sm text-text-muted">Estimasi nilai jual</p>
                    <p class="text-currency-display text-text-strong tabular-nums" x-text="'Rp' + totalValue().toLocaleString('id-ID')">Rp0</p>
                </div>
                <button type="button" class="btn-secondary" @click="rows.length && ($store.toast.push('Draft disimpan', 'success'))">
                    Simpan Draft
                </button>
                <button type="button" class="btn-primary" x-on:click="commit()">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    Commit &amp; Buat Label
                </button>
            </div>
        </div>
    </div>
</div>