<x-ui.page-header
    title="Stock In Pribadi"
    subtitle="Terima stok milik toko (OW00). Harga modal (HPP) hanya terlihat oleh Owner &amp; Manager."
    :crumbs="['Inbound', 'Stock In Pribadi']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Draft tersimpan lokal (mock)', 'success')">Simpan Draft</button>
        <button type="button" class="btn-primary" @click="$store.toast.push('Committed: 6 unit masuk + label dibuat', 'success')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
            Simpan &amp; Buat Label
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Informasi Penerimaan">
        <div class="grid gap-5 sm:grid-cols-3">
            <x-ui.field label="Referensi / DO" name="si_ref">
                <input id="si_ref" class="input-base" placeholder="Lakukan otomatis: INV-xxxx" value="INV-2026-0917">
            </x-ui.field>
            <x-ui.field label="Supplier / Sumber" name="si_src">
                <input id="si_src" class="input-base" placeholder="mis. PT Distributor Utama">
            </x-ui.field>
            <x-ui.field label="Catatan" name="si_note">
                <input id="si_note" class="input-base" placeholder="Opsional">
            </x-ui.field>
        </div>
    </x-ui.section-card>

    <x-ui.section-card>
        <div class="flex items-center justify-between px-6 py-4">
            <div>
                <h2 class="text-headline-sm text-text-strong">Baris Item</h2>
                <p class="text-label-sm text-text-muted">Kolom HPP hanya untuk Owner &amp; Manager · normalisasi SKU menuju OW00-.</p>
            </div>
            <button type="button" class="btn-secondary" @click="$store.toast.push('Baris baru ditambahkan (mock)', 'info')">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                Tambah Baris
            </button>
        </div>

        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">Produk</th>
                    <th class="px-6 py-3 font-semibold">Kondisi</th>
                    <th class="px-6 py-3 font-semibold">Qty</th>
                    <th class="px-6 py-3 text-right font-semibold">HPP / Unit (Owner)</th>
                    <th class="px-6 py-3 text-right font-semibold">Harga Jual</th>
                    <th class="px-6 py-3 font-semibold">Rak</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                @for ($i = 1; $i <= 4; $i++)
                    <tr class="row-dense transition hover:bg-canvas">
                        <td class="px-6 py-3">
<input class="w-full bg-transparent outline-none" placeholder="Scan / ketik SKU atau nama..."
                       {{ $i === 1 ? 'x-scan="$store.toast.push(\'SKU diterima (mock)\', \'success\')"' : '' }}>
                        </td>
                        <td class="px-6 py-3">
                            <select class="w-28 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                <option>Mint</option>
                                <option>Clear</option>
                                <option>Used</option>
                            </select>
                        </td>
                        <td class="px-6 py-3"><x-ui.qty-stepper value="{{ $i + 1 }}" /></td>
                        <td class="px-6 py-3 text-right">
                            <input class="w-32 bg-transparent text-right tabular-nums outline-none" placeholder="0" value="{{ $i === 2 ? 95 : '' }}">
                        </td>
                        <td class="px-6 py-3 text-right">
                            <input class="w-32 bg-transparent text-right tabular-nums outline-none" placeholder="0" value="{{ 135000 + $i * 10000 }}">
                        </td>
                        <td class="px-6 py-3">
                            <input class="w-24 bg-transparent font-mono uppercase outline-none" value="A-01-03">
                        </td>
                        <td class="px-6 py-3 text-right">
                            <button type="button" class="btn-ghost h-9 w-9 px-0 text-error-text" @click="$store.toast.push('Baris dihapus', 'warning')" aria-label="Hapus baris">
                                <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </td>
                    </tr>
                @endfor
            </tbody>
        </table>

        <div class="border-t border-border-subtle px-6 py-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-body-sm text-text-muted">
                    <span class="font-semibold text-warning-text">Peringatan:</span> harga jual &lt; HPP pada baris ke-2. Margin negatif.
                </p>
                <div class="text-right">
                    <p class="text-label-md text-text-muted">Total 6 unit · Estimasi nilai jual</p>
                    <p class="text-currency-display text-text-strong tabular-nums">Rp615.000</p>
                </div>
            </div>
        </div>
    </x-ui.section-card>

    <x-ui.banner tone="success">
        <span class="font-semibold">Label otomatis.</span>
        Setiap baris di-commit akan menghasilkan label 3×2cm dengan QR, SKU, dan harga. Buka antrean cetak di <a href="{{ route('inbound.cetak-label') }}" class="font-semibold underline">Cetak / Re-print Label →</a>
    </x-ui.banner>
</div>