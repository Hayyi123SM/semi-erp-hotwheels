<x-ui.page-header
    title="Stock In Pribadi"
    subtitle="Terima stok milik toko (OW00). Harga modal (HPP) hanya diisi Owner dan hanya Commit oleh Owner."
    :crumbs="['Inbound', 'Stock In Pribadi']"
>
</x-ui.page-header>

<form method="POST" action="{{ route('inbound.stock-in-pribadi.store') }}"
      class="space-y-6"
      x-data="{
          rows: [{ qty: 1, card_condition: 'MINT', blister_condition: 'CLEAR' }],
          totalQty() { return this.rows.reduce((sum, row) => sum + (Number(row.qty) || 0), 0); },
          totalHpp() { return this.rows.reduce((sum, row) => sum + ((Number(row.qty) || 0) * (Number(row.cost_price) || 0)), 0); },
      }">
    @csrf

    @if ($errors->any())
        <x-ui.banner tone="error">
            Form belum bisa dikirim. Periksa kembali isian, termasuk setiap baris grid di bawah.
        </x-ui.banner>
    @endif

    @if (! auth()->user()->isOwner())
        <x-ui.banner tone="info">Commit Stock In Pribadi hanya dapat dilakukan oleh Owner. Anda tetap bisa mengisi draft di bawah.</x-ui.banner>
    @endif

    <!-- Tier 1: Informasi Penerimaan -->
    <x-ui.section-card title="Informasi Penerimaan">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.field label="Referensi / DO" name="source">
                <input id="source" name="source" value="{{ old('source') }}"
                       class="input-base @error('source') border-error-border @enderror" placeholder="mis. INV-2026-0917">
                @error('source')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Catatan" name="notes">
                <input id="notes" name="notes" value="{{ old('notes') }}"
                       class="input-base @error('notes') border-error-border @enderror" placeholder="opsional">
                @error('notes')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
        </div>
    </x-ui.section-card>

    <!-- Tier 2: Batch Entry Grid -->
    <x-ui.section-card pad="false">
        <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary" @click="rows.push({ qty: 1, card_condition: 'MINT', blister_condition: 'CLEAR' })">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Baris
                </button>
            </div>
            <p class="text-label-sm text-text-subtle">HPP (harga modal) dibutuhkan per baris sebelum commit.</p>
        </div>

        <div class="table-scroll">
            <table class="w-full min-w-[900px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-3 py-3 font-semibold">Produk</th>
                        <th class="px-3 py-3 font-semibold">Kondisi Card</th>
                        <th class="px-3 py-3 font-semibold">Kondisi Blister</th>
                        <th class="px-3 py-3 text-center font-semibold">Qty</th>
                        <th class="px-3 py-3 text-right font-semibold">HPP / Unit</th>
                        <th class="px-3 py-3 font-semibold">Rak</th>
                        <th class="px-3 py-3 text-center font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    <template x-for="(row, index) in rows" :key="index">
                        <tr class="h-12 border-b border-border-subtle transition hover:bg-canvas">
                            <td class="px-3 py-2">
                                <x-ui.searchable-select placeholder="— pilih produk —" class="w-64">
                                    <select x-model="row.product_id" :name="`items[${index}][product_id]`" class="sr-only" required @focus="openPanel()" @keydown="onSearchKeydown($event)">
                                        <option value="">— pilih produk —</option>
                                        @foreach ($products->groupBy(fn ($p) => $p->series?->code ?? 'Tanpa Seri') as $seriesCode => $seriesProducts)
                                            <optgroup label="{{ $seriesCode }}">
                                                @foreach ($seriesProducts as $product)
                                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </x-ui.searchable-select>
                            </td>
                            <td class="px-3 py-2">
                                <select x-model="row.card_condition" :name="`items[${index}][card_condition]`"
                                        class="w-40 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    @foreach ($card_conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <select x-model="row.blister_condition" :name="`items[${index}][blister_condition]`"
                                        class="w-40 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    @foreach ($blister_conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="number" x-model.number="row.qty" :name="`items[${index}][qty]`"
                                       min="1" max="999" required
                                       class="w-20 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-center tabular-nums">
                            </td>
                            <td class="px-3 py-2 text-right">
                                <input type="number" x-model.number="row.cost_price" :name="`items[${index}][cost_price]`"
                                       min="0" step="1000" required
                                       class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-right tabular-nums">
                            </td>
                            <td class="px-3 py-2">
                                <select x-model="row.rack_id" :name="`items[${index}][rack_id]`"
                                        class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    <option value="">—</option>
                                    @foreach ($racks as $rack)
                                        <option value="{{ $rack->id }}">{{ $rack->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-center">
                                    <button type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-error-text transition hover:bg-error-bg"
                                            @click="rows.splice(index, 1)" title="Hapus baris">
                                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="7">
                            <x-ui.empty-state title="Belum ada baris" description="Klik Tambah Baris untuk mulai mengisi penerimaan." />
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
                    <span class="text-text-muted">Total HPP: <b class="text-text-strong tabular-nums" x-text="totalHpp().toLocaleString('id-ID')"></b></span>
                </div>
            </div>
        </div>
    </x-ui.section-card>

    <!-- Tier 3: Sticky Commit Bar -->
    <div class="sticky bottom-0 z-10 rounded-xl border border-border-subtle bg-surface-lowest px-5 py-4 sticky-bar">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <label class="flex cursor-pointer items-center gap-3 text-body-sm text-text-strong">
                <input type="checkbox" name="verified" value="1"
                       class="h-5 w-5 rounded border-border-strong text-primary focus:ring-primary/30" required>
                Saya telah memverifikasi fisik unit sesuai baris (<b class="tabular-nums" x-text="totalQty()"></b>&nbsp;unit)
            </label>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    Simpan &amp; Buat Label
                </button>
            </div>
        </div>
    </div>
</form>