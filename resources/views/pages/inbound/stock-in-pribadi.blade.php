<x-ui.page-header
    title="Stock In Pribadi"
    subtitle="Terima stok milik toko (OW00). Harga modal (HPP) hanya diisi Owner dan hanya Commit oleh Owner."
    :crumbs="['Inbound', 'Stock In Pribadi']"
>
</x-ui.page-header>

<div
    x-data="stockInPribadiForm({
        lookupUrl: @js($lookupUrl),
        racks: @js($racks),
        cardConditions: @js($card_conditions),
        blisterConditions: @js($blister_conditions),
        initialItems: @js($initialItems),
    })"
    class="space-y-6"
    @keydown.esc.window="if(pickerOpen) closePicker()"
    @stock-in-pribadi:add-product.window="onProductPicked($event)"
    x-effect="document.body.style.overflow = pickerOpen ? 'hidden' : ''"
>
    <form method="POST" action="{{ route('inbound.stock-in-pribadi.store') }}" class="space-y-6">
        @csrf

        @if ($errors->any())
            <x-ui.banner tone="error">
                Form belum bisa dikirim. Periksa kembali isian, termasuk setiap baris di daftar produk.
            </x-ui.banner>
        @endif

        @if (! auth()->user()->isOwner())
            <x-ui.banner tone="info">Commit Stock In Pribadi hanya dapat dilakukan oleh Owner. Anda tetap bisa mengisi form di bawah.</x-ui.banner>
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

                <x-ui.field label="Scan Barcode" name="scan_barcode">
                    <input
                        id="scan_barcode"
                        x-ref="scanInput"
                        x-model="scanCode"
                        @keydown.enter.prevent="onScanKeydown($event)"
                        @input="clearScanError()"
                        class="input-base"
                        placeholder="Scan barcode atau ketik lalu Enter"
                        autocomplete="off"
                    >
                    <p x-show="scanError" x-text="scanError" class="mt-1 text-label-sm text-error-text" x-cloak></p>
                </x-ui.field>
            </div>
        </x-ui.section-card>

        <!-- Tier 2: Daftar Produk -->
        <x-ui.section-card pad="false">
            <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-title-sm font-semibold">Daftar Produk</h3>
                <button type="button" class="btn-secondary" @click="openPicker()">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Produk
                </button>
            </div>

            <div x-show="items.length === 0" class="px-6 pb-6" x-cloak>
                <x-ui.empty-state title="Belum ada produk terpilih" description="Tambah produk via popup pencarian atau scan barcode di Info Penerimaan.">
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-center">
                        <button type="button" class="btn-primary" @click="openPicker()">Tambah Produk</button>
                        <button type="button" class="btn-secondary" @click="focusScan()">Scan Barcode</button>
                    </div>
                </x-ui.empty-state>
            </div>

            <div x-show="items.length > 0" class="space-y-3 px-6 pb-6" x-cloak>
                <template x-for="(item, idx) in items" :key="item.id">
                    <div
                        :data-row-idx="idx"
                        class="overflow-hidden rounded-lg border bg-surface-lowest transition"
                        :class="highlightedIndex === idx ? 'border-primary bg-primary-soft' : 'border-border-subtle'"
                    >
                        <!-- Identitas produk + hapus -->
                        <div class="flex items-start justify-between gap-3 border-b border-border-subtle px-4 py-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded bg-canvas text-label-sm font-semibold tabular-nums text-text-muted" x-text="idx + 1"></span>
                                    <span class="truncate text-body-sm font-medium text-text-strong" x-text="item.name"></span>
                                </div>
                                <span
                                    x-show="item.casting_code"
                                    class="mt-1 ml-7 inline-flex items-center rounded border border-border-subtle bg-canvas px-1.5 py-0.5 font-mono text-label-sm text-text-subtle"
                                    x-text="item.casting_code"
                                ></span>
                            </div>
                            <button
                                type="button"
                                @click="remove(idx)"
                                class="btn-ghost shrink-0 text-error-text"
                                :title="`Hapus ${item.name} dari daftar`"
                                aria-label="Hapus baris"
                            >
                                <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>

                        <!-- Isian baris -->
                        <div class="grid gap-3 px-4 py-3 sm:grid-cols-2 lg:grid-cols-5">
                            <input type="hidden" :name="`items[${idx}][product_id]`" :value="item.product_id">

                            <div>
                                <label class="text-label-sm text-text-muted">Qty</label>
                                <input
                                    type="number"
                                    :name="`items[${idx}][qty]`"
                                    x-model.number="item.qty"
                                    min="1"
                                    max="999"
                                    required
                                    class="input-base mt-1 text-center tabular-nums"
                                >
                            </div>
                            <div>
                                <label class="text-label-sm text-text-muted">HPP / Unit</label>
                                <div class="mt-1 flex">
                                    <span class="inline-flex items-center rounded-l-lg border border-r-0 border-border-subtle bg-canvas px-3 text-text-muted">Rp</span>
                                    <input
                                        type="number"
                                        :name="`items[${idx}][cost_price]`"
                                        x-model.number="item.cost_price"
                                        min="0"
                                        step="1000"
                                        required
                                        placeholder="0"
                                        class="input-base rounded-l-none text-right tabular-nums"
                                    >
                                </div>
                            </div>
                            <div>
                                <label class="text-label-sm text-text-muted">Kondisi Card</label>
                                <select
                                    :name="`items[${idx}][card_condition]`"
                                    x-model="item.card_condition"
                                    class="input-base mt-1"
                                >
                                    <template x-for="[val, lbl] in Object.entries(cardConditions)" :key="val">
                                        <option :value="val" x-text="lbl"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="text-label-sm text-text-muted">Kondisi Blister</label>
                                <select
                                    :name="`items[${idx}][blister_condition]`"
                                    x-model="item.blister_condition"
                                    class="input-base mt-1"
                                >
                                    <template x-for="[val, lbl] in Object.entries(blisterConditions)" :key="val">
                                        <option :value="val" x-text="lbl"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="sm:col-span-2 lg:col-span-1">
                                <label class="text-label-sm text-text-muted">Rak</label>
                                <select :name="`items[${idx}][rack_id]`" x-model="item.rack_id" class="input-base mt-1">
                                    <option value="">—</option>
                                    <template x-for="r in racks" :key="r.id">
                                        <option :value="r.id" x-text="r.code"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="border-t border-border-subtle bg-canvas px-6 py-3" x-show="items.length > 0" x-cloak>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex flex-wrap gap-6 text-body-sm">
                        <span class="text-text-muted">Total Baris: <b class="text-text-strong" x-text="items.length"></b></span>
                        <span class="text-text-muted">Total Unit: <b class="text-text-strong tabular-nums" x-text="totalQty()"></b></span>
                        <span class="text-text-muted">Total HPP: <b class="text-text-strong tabular-nums" x-text="formatRupiah(totalHpp())"></b></span>
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
                    Saya telah memverifikasi fisik unit sesuai daftar (<b class="tabular-nums" x-text="totalQty()"></b>&nbsp;unit)
                </label>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn-primary" :disabled="items.length === 0">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                        Simpan &amp; Buat Label
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- Picker: Bottom drawer mobile, Modal desktop -->
    <div x-show="pickerOpen" x-cloak>
        <div class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/40" @click="closePicker()"></div>
            <div
                class="absolute inset-x-0 bottom-0 flex max-h-[85dvh] flex-col rounded-t-xl bg-surface-lowest shadow-xl sm:inset-auto sm:top-1/2 sm:left-1/2 sm:max-h-[90vh] sm:w-full sm:max-w-3xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl"
            >
                <div class="flex flex-col">
                    <div class="mx-auto mt-2 h-1.5 w-10 rounded-full bg-border-subtle sm:hidden"></div>
                    <div class="flex items-center justify-between px-4 py-3 sm:px-6 sm:py-4 border-b border-border-subtle">
                        <h3 class="text-title-sm font-semibold">Cari Produk</h3>
                        <button type="button" class="btn-ghost" @click="closePicker()">Tutup</button>
                    </div>
                </div>
                <div
                    class="flex-1 min-h-0 overflow-auto px-4 py-4 sm:px-6"
                    x-data="productPicker({ url: lookupUrl, emitEvent: 'stock-in-pribadi:add-product' })"
                >
                    <div class="space-y-3">
                        <div class="relative">
                            <input
                                id="stock-in-picker-search"
                                type="text"
                                class="input-base pl-9"
                                placeholder="Ketik nama produk, kode casting, atau barcode..."
                                x-model="term"
                                @input="searchSoon()"
                                @keydown.enter.prevent="submit()"
                                @keydown.arrow-down.prevent="move(1)"
                                @keydown.arrow-up.prevent="move(-1)"
                                @keydown.esc.prevent="closePicker()"
                            >
                            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                        </div>

                        <div x-show="loading" class="text-body-sm text-text-muted">Mencari...</div>
                        <div x-show="error" class="text-body-sm text-error-text" x-text="error"></div>
                        <div x-show="!loading && searched && !hasResults" class="text-body-sm text-text-muted">Tidak ditemukan</div>

                        <div x-show="hasResults" class="divide-y divide-border-subtle rounded-lg border border-border-subtle">
                            <template x-for="(item, index) in items" :key="index">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-canvas"
                                    :class="activeIndex === index ? 'bg-primary-soft' : ''"
                                    @click="choose(item)"
                                    @mouseenter="activeIndex = index"
                                >
                                    <div class="flex-1 min-w-0">
                                        <div class="text-body-sm font-medium text-text-strong truncate" x-text="item.name"></div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-label-sm text-text-subtle">
                                            <span x-show="item.casting_code">Kode: <span x-text="item.casting_code"></span></span>
                                            <span x-show="item.series">Seri: <span x-text="item.series"></span></span>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
