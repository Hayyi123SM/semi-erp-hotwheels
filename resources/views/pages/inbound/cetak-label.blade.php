<x-ui.page-header
    title="Cetak / Re-print Label"
    subtitle="Antrean cetak label thermal 3×2 cm & 4×3 cm. Re-print dibatasi 3×/hari per SKU."
    :crumbs="['Inbound', 'Cetak / Re-print Label']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Tes cetak dikirim ke printer WMS-01 (mock)', 'info')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5H6a2 2 0 00-2 2v5m0 0h12m-12 0v6m12-11h-7m0 5h.01M21 8a3 3 0 00-3-3m0 0H6m12 8a2 2 0 01-2 2H8m8 0a2 2 0 012 2v2a2 2 0 01-2 2H8a2 2 0 01-2-2v-2m0 0a2 2 0 012-2H8"/></svg>
            Test Print
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <div class="card card-pad">
        <h2 class="mb-4 text-headline-sm text-text-strong">Status Printer</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['name' => 'WMS-01 · Rak A (3×2cm)', 'status' => 'online', 'qty' => 340, 'label' => 'ONLINE'],
                ['name' => 'WMS-02 · Rak B (4×3cm)', 'status' => 'online', 'qty' => 125, 'label' => 'ONLINE'],
                ['name' => 'POS-01 · Struk Thermal', 'status' => 'error', 'qty' => 12, 'label' => 'OFFLINE'],
            ] as $printer)
                <div class="flex items-center justify-between rounded-lg border border-border-subtle bg-canvas px-4 py-3.5">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">{{ $printer['name'] }}</p>
                        <p class="text-label-sm text-text-muted">Sisa roll ≈ {{ $printer['qty'] }} label</p>
                    </div>
                    @if ($printer['status'] === 'online')
                        <x-ui.badge-status type="success" dot>{{ $printer['label'] }}</x-ui.badge-status>
                    @else
                        <x-ui.badge-status type="error">{{ $printer['label'] }}</x-ui.badge-status>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.section-card title="Antrean Siap Cetak">
                <x-slot:actions>
                    <span class="text-label-sm text-text-subtle">3 kasus · 8 label</span>
                </x-slot:actions>

                <x-ui.toolbar search-placeholder="Cari SKU / kasus...">
                    <x-slot:filters>
                        <select class="input-base h-11 w-auto">
                            <option>Semua Ukuran</option>
                            <option>3×2 cm</option>
                            <option>4×3 cm</option>
                        </select>
                    </x-slot:filters>
                </x-ui.toolbar>

                <div class="space-y-3 px-6">
                    @foreach ($catalog as $product)
                        @if ($loop->index >= 3) @break @endif
                        <div class="flex flex-col gap-4 rounded-lg border border-border-subtle p-4 sm:flex-row sm:items-center" x-data="{ selected: false }">
                            <input type="checkbox" x-model="selected" class="h-5 w-5 rounded border-border-strong text-primary focus:ring-primary/30" aria-label="Pilih label">
                            <div class="flex flex-1 items-start gap-4">
                                <x-ui.label-preview
                                    :sku="$product['sku']"
                                    :model="$product['model']"
                                    :condition="$product['condition']"
                                    :price="$product['price']"
                                    :rack="$product['rack']"
                                    :show-price="true" />
                                <div>
                                    <p class="text-body-md font-semibold text-text-strong">{{ $product['model'] }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <x-ui.badge-ownership :type="$product['category']" :consignor="$product['category'] === 'TITIP' ? substr($product['sku'], 0, 4) : null" />
                                        <span class="font-mono text-label-sm text-text-subtle">{{ $product['sku'] }}</span>
                                    </div>
                                    <div class="mt-2 flex items-center gap-3">
                                        <label class="text-label-sm text-text-muted">Jumlah:</label>
                                        <x-ui.qty-stepper value="1" />
                                        <span class="rounded bg-primary-soft px-2 py-0.5 text-label-sm text-primary">3×2 cm</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" class="btn-secondary" @click="$store.toast.push('Print label diproses (mock)', 'success')">Cetak</button>
                                <button type="button" class="btn-ghost h-11 px-3" @click="$store.toast.push('Buka menu re-print', 'info')">Re-print</button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="px-6 py-4">
                    <button type="button" class="btn-primary w-full sm:w-auto">Cetak Terpilih (0)</button>
                </div>
            </x-ui.section-card>
        </div>

        <div class="space-y-6">
            <x-ui.section-card title="Label Rak">
                <div class="space-y-4">
                    <p class="text-body-sm text-text-muted">Cetak label identifikasi untuk rak KARANTINA / RTV_STAGING / DISPLAY.</p>
                    <button type="button" class="btn-secondary w-full" @click="$store.toast.push('Label rak Q-00-01 dikirim (mock)', 'success')">
                        Cetak RK-Q-00-01
                    </button>
                    <button type="button" class="btn-secondary w-full" @click="$store.toast.push('Label rak A-01-03 dikirim (mock)', 'success')">
                        Cetak RK-A-01-03
                    </button>
                </div>
            </x-ui.section-card>

            <x-ui.section-card title="Riwayat Re-print">
                <ul class="divide-y divide-border-subtle">
                    @foreach ([
                        ['sku' => 'OW00-HW-002', 'user' => 'Dewi Lestari', 'at' => '21 Sep 14:02', 'reason' => 'Label luntur'],
                        ['sku' => 'CN01-HW-001', 'user' => 'Ahmad Fauzi', 'at' => '20 Sep 09:41', 'reason' => 'Salah harga'],
                        ['sku' => 'OW00-HW-003', 'user' => 'Dewi Lestari', 'at' => '18 Sep 16:20', 'reason' => 'Sobek'],
                    ] as $log)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div>
                                <p class="font-mono text-body-sm text-text-strong">{{ $log['sku'] }}</p>
                                <p class="text-label-sm text-text-subtle">{{ $log['user'] }} · {{ $log['at'] }} · {{ $log['reason'] }}</p>
                            </div>
                            <span class="text-label-sm text-text-subtle">#{{ $loop->iteration }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section-card>
        </div>
    </div>
</div>