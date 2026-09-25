<div class="min-h-full" x-data="posCart({{ json_encode(collect($activeBank)->map(fn ($i) => ['sku' => $i['sku'], 'name' => $i['model'], 'price' => $i['price'], 'ownership' => $i['owner'] == 'OW00' ? 'PRIBADI' : 'TITIP'])->values()->toArray()) }})"
     @keydown.window.f2.prevent="$store.toast.push('Fokus ke pencarian (mock)', 'info')"
     @keydown.window.f8.prevent="pay()"
     @keydown.window.escape.prevent="items = []">

    <!-- POS: 62/38 split -->
    <div class="grid h-full gap-0 lg:grid-cols-[62fr_38fr]">
        <!-- Left work zone -->
        <div class="bg-canvas p-4 sm:p-6">
            <div class="mx-auto max-w-4xl">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-headline-md text-text-strong">Kasir · Reguler Shift 2</h1>
                        <p class="text-label-sm text-text-muted">Dewi Lestari · POS-CP2 · 24 Sep 2026 14:05</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label-sm bg-success-bg text-success-text">
                            <span class="h-2 w-2 rounded-full bg-success-text"></span> ONLINE
                        </span>
                        <button type="button" class="btn-ghost h-10 px-3" @click="$store.toast.push('Shift dibuka', 'info')">Shift</button>
                    </div>
                </div>

                <!-- Warning banner (non-modal) -->
                <div class="mt-4">
                    <x-ui.banner tone="warning">
                        <span class="font-semibold">Barcode pabrik terdeteksi.</span>
                        Gunakan label internal WMS <b>[F4]</b> untuk SKU tanpa label. Kirim ke karantina bila tidak dikenal.
                    </x-ui.banner>
                </div>

                <!-- Scan input -->
                <div class="mt-4" x-data x-ref="scanZone">
                    <div class="relative">
                        <input
                            type="text"
                            placeholder="Scan barcode internal / ketik SKU [F2]"
                            class="scan-input w-full pr-16"
                            x-scan="addItem($el.value, 'Nissan Skyline GT-R R34', 220000, 'TITIP')"
                        >
                        <span class="absolute right-3 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded bg-primary-soft text-label-sm font-bold text-primary">⌐</span>
                    </div>

                    <!-- Error slot -->
                    <div class="mt-3 rounded-lg border border-error-border bg-error-bg px-4 py-2.5 text-body-sm text-error-text">
                        <span class="font-semibold">SKU tidak ditemukan.</span>
                        Periksa huruf kapital atau <b>Kirim ke Karantina →</b>
                    </div>
                </div>

                <!-- Basket -->
                <div class="mt-5 card overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Item</th>
                                <th class="px-4 py-3 text-center font-semibold">Qty</th>
                                <th class="px-4 py-3 text-right font-semibold">Harga</th>
                                <th class="px-4 py-3 text-right font-semibold">Jumlah</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <template x-for="item in items" :key="item.sku">
                                <tr class="h-12 border-b border-border-subtle transition hover:bg-canvas">
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2.5">
                                            <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-label-md"
                                                  :class="item.ownership === 'TITIP'
                                                      ? 'border-titip-border bg-titip-bg text-titip-text'
                                                      : 'border-pribadi-border bg-pribadi-bg text-pribadi-text'">
                                                <span x-text="item.ownership === 'TITIP' ? 'TITIP' : 'PRIBADI'"></span>
                                            </span>
                                            <div>
                                                <p class="text-body-md font-medium text-text-strong" x-text="item.name"></p>
                                                <p class="font-mono text-label-sm text-text-subtle" x-text="item.sku"></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center justify-center gap-1">
                                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-text-muted hover:bg-canvas" @click="decrement(item.sku)">−</button>
                                            <span class="w-8 text-center text-body-md font-semibold tabular-nums" x-text="item.qty"></span>
                                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-text-muted hover:bg-canvas" @click="increment(item.sku)">+</button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-right text-body-md tabular-nums" x-text="'Rp' + item.price.toLocaleString('id-ID')"></td>
                                    <td class="px-4 py-2 text-right text-body-md font-semibold tabular-nums" x-text="'Rp' + (item.price * item.qty).toLocaleString('id-ID')"></td>
                                    <td class="px-4 py-2 text-right">
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-error-text hover:bg-error-bg" @click="removeItem(item.sku)" aria-label="Hapus item">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0" x-cloak>
                                <td colspan="5">
                                    <x-ui.empty-state
                                        title="Keranjang kosong"
                                        description="Scan produk di atas. Baris baru akan ber-pulse hijau saat di-lookup."
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-label-sm text-text-subtle">
                    <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">F2</span> Fokus scan
                    · <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">F8</span> Bayar
                    · <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">Esc</span> Kosongkan
                </p>
            </div>
        </div>

        <!-- Right settlement zone -->
        <div class="flex flex-col border-l border-border-subtle bg-surface-lowest p-5 sm:p-6">
            <p class="text-label-sm uppercase tracking-wide text-text-muted">Total Belanja</p>
            <p class="mt-1 text-display-total font-bold text-text-strong tabular-nums"
               x-text="'Rp' + subtotal.toLocaleString('id-ID')">Rp0</p>

            <div class="mt-6">
                <p class="mb-2 text-label-md text-text-muted">Metode Pembayaran</p>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['TUNAI', 'QRIS', 'KARTU'] as $method)
                        <button type="button"
                                class="rounded-lg border px-3 py-2 text-body-sm font-semibold transition"
                                :class="paymentMethod === @js($method)
                                    ? 'border-primary bg-primary text-on-primary'
                                    : 'border-border-subtle bg-canvas text-text-muted hover:border-border-strong'"
                                @click="paymentMethod = @js($method)">
                            {{ $method }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="mt-6" x-data="{ showNumpad: true }">
                <div class="flex items-center justify-between">
                    <p class="mb-2 text-label-md text-text-muted">Uang Diterima</p>
                    <span class="text-label-sm text-text-muted">Quick cash:</span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ([50000, 100000, 150000] as $denom)
                        <button type="button"
                                class="h-14 rounded-lg border border-border-subtle bg-canvas text-body-md font-semibold text-text-strong transition hover:border-primary hover:bg-primary-soft active:scale-[0.98]"
                                @click="quickTender({{ $denom }})">
                            {{ \App\Support\MockData::rupiah($denom) }}
                        </button>
                    @endforeach
                    <button type="button"
                            class="col-span-3 h-14 rounded-lg border border-border-subtle bg-canvas text-body-md font-semibold text-text-strong transition hover:bg-canvas/70"
                            @click="quickTender(subtotal)">
                        Uang Pas (exact)
                    </button>
                </div>
                <input type="number"
                       x-model.number="tender"
                       class="input-base mt-3 text-right text-headline-md font-semibold tabular-nums"
                       placeholder="Masukkan nominal...">
            </div>

            <div class="mt-4 rounded-lg bg-canvas p-4">
                <p class="text-label-sm text-text-muted">Kembalian</p>
                <p class="text-currency-display font-bold text-success-text tabular-nums"
                   x-text="'Rp' + change.toLocaleString('id-ID')">Rp0</p>
            </div>

            <div class="mt-6 space-y-2">
                <button type="button" class="btn-ghost w-full justify-between font-normal" @click="pay()">
                    <span>Split Laba Owner</span>
                    <span class="text-label-sm text-text-subtle tabular-nums" x-text="'Rp' + Math.round(subtotal*0.8).toLocaleString('id-ID') + ' toko · ' + Math.round(subtotal*0.2).toLocaleString('id-ID') + ' penitip'"></span>
                </button>
                <button type="button" class="btn-ghost w-full justify-between font-normal" @click="pay()">
                    <span>Riwayat Shift</span>
                    <span class="text-label-sm text-text-subtle">7 nota</span>
                </button>
            </div>

            <div class="mt-auto pt-6">
                <button type="button" class="btn-primary w-full h-14 text-body-md" @click="pay()">
                    Bayar &amp; Cetak Struk <span class="rounded bg-on-primary/20 px-2 py-0.5 font-mono text-label-sm">F8</span>
                </button>
            </div>
        </div>
    </div>
</div>