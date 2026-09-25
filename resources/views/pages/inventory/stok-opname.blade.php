<x-ui.page-header
    title="Stok Opname"
    subtitle="Cycle count blind. Kolom jumlah sistem disembunyikan saat entry; selisih direview Owner."
    :crumbs="['Inventory', 'Stok Opname']"
>
</x-ui.page-header>

<div class="space-y-6" x-data="{ selisihTerbuka: null, blindMode: true }">
    <div class="card card-pad flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-4">
            <div class="relative h-16 w-16">
                <svg class="h-16 w-16 -rotate-90" viewBox="0 0 64 64">
                    <circle cx="32" cy="32" r="28" class="fill-none stroke-canvas stroke-8" />
                    <circle cx="32" cy="32" r="28" class="fill-none stroke-primary stroke-8" stroke-dasharray="175.9" stroke-dashoffset="36.6" stroke-linecap="round" />
                </svg>
                <span class="absolute inset-0 flex items-center justify-center text-body-md font-bold text-text-strong tabular-nums">76%</span>
            </div>
            <div>
                <h2 class="text-headline-sm text-text-strong">Sesi Opname #OPN-2026-04</h2>
                <p class="text-label-sm text-text-muted">342 / 450 rak terhitung · dimulai oleh Ahmad Fauzi · mode blind aktif</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" @click="blindMode = !blindMode" x-text="blindMode ? 'Lihat Qty Sistem' : 'Sembunyikan Qty Sistem'"></button>
            <button type="button" class="btn-primary" @click="$store.toast.push('Sesi ditutup &amp; siap review (mock)', 'success')">Selesaikan Sesi</button>
        </div>
    </div>

    <x-ui.section-card title="Blind Count">
        <x-ui.toolbar search-placeholder="Scan SKU untuk fokus baris...">
            <x-slot:filters>
                <select class="input-base h-11 w-auto">
                    <option>Semua Rak</option>
                    <option>A-01-03</option>
                    <option>A-02-01</option>
                    <option>B-01-05</option>
                </select>
            </x-slot:filters>
        </x-ui.toolbar>

        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">SKU</th>
                    <th class="px-6 py-3 font-semibold">Produk</th>
                    <th class="px-6 py-3 font-semibold">Rak</th>
                    <th class="px-6 py-3 font-semibold">Qty Sistem</th>
                    <th class="px-6 py-3 text-center font-semibold">Qty Terhitung</th>
                    <th class="px-6 py-3 text-right font-semibold">Selisih</th>
                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                @foreach ([
                    ['sku' => 'OW00-HW-001', 'model' => '97 Mazda RX-7', 'rack' => 'A-01-03', 'sys' => 4, 'count' => 4],
                    ['sku' => 'OW00-HW-002', 'model' => 'Porsche 911 GT3 RS', 'rack' => 'A-01-03', 'sys' => 3, 'count' => 3],
                    ['sku' => 'CN01-HW-001', 'model' => 'Nissan Skyline GT-R R34', 'rack' => 'A-01-03', 'sys' => 5, 'count' => 4],
                    ['sku' => 'OW00-HW-003', 'model' => 'Honda Civic Type R', 'rack' => 'A-02-01', 'sys' => 6, 'count' => 7],
                    ['sku' => 'CN02-HW-001', 'model' => 'Lamborghini Huracan', 'rack' => 'B-01-05', 'sys' => 2, 'count' => 2],
                ] as $row)
                    <tr class="row-dense transition hover:bg-canvas" x-data="{ entered: {{ $row['count'] }} }">
                        <td class="px-6 py-3 font-mono text-sku text-text-strong">{{ $row['sku'] }}</td>
                        <td class="px-6 py-3 text-body-md font-medium text-text-strong">{{ $row['model'] }}</td>
                        <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $row['rack'] }}</td>
                        <td class="px-6 py-3 text-right">
                            <span class="font-mono text-body-sm text-text-subtle" x-show="!blindMode" x-text="'{{ $row['sys'] }}'"></span>
                            <span x-show="blindMode" class="select-none font-mono text-body-sm text-text-subtle">•••</span>
                        </td>
                        <td class="px-6 py-3 text-center">
                            <x-ui.qty-stepper :value="$row['count']" x-model="entered" {{-- counts remain blind --}} />
                        </td>
                        <td class="px-6 py-3">
                            <span x-show="!blindMode"
                                  :class="entered - {{ $row['sys'] }} === 0 ? 'text-success-text' : 'text-warning-text'"
                                  class="block text-right font-semibold tabular-nums"
                                  x-text="(entered - {{ $row['sys'] }}) > 0 ? '+' + (entered - {{ $row['sys'] }}) : (entered - {{ $row['sys'] }})"></span>
                            <span x-show="blindMode" class="block text-right text-label-sm text-text-subtle">—</span>
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end">
                                <button type="button"
                                        class="btn-ghost h-9 px-3 text-primary"
                                        :disabled="blindMode"
                                        @click="selisihTerbuka = '{{ $row['sku'] }}'">Review</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.section-card>

    <!-- Approval panel (inline, non-modal) -->
    <div x-show="selisihTerbuka !== null" x-cloak x-transition
         class="card p-6">
        <div class="flex items-center justify-between" x-show="selisihTerbuka !== null">
            <h3 class="text-headline-sm text-text-strong">
                Review Selisih · <span class="font-mono text-sku" x-text="selisihTerbuka"></span>
            </h3>
            <button type="button" class="btn-ghost h-9 px-3" @click="selisihTerbuka = null">Tutup</button>
        </div>
        <div class="mt-4 grid gap-6 lg:grid-cols-2">
            <x-ui.pin-overlay context="Setujui selisih −1 unit dengan alasan wajib berikut." />
            <div class="space-y-4">
                <div>
                    <p class="mb-2 text-label-md text-text-muted">Alasan Selisih</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm">
                            <input type="radio" name="reason" class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                            LOST (hilang)
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm">
                            <input type="radio" name="reason" class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                            DAMAGED (rusak)
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm">
                            <input type="radio" name="reason" class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                            FOUND (ditemukan)
                        </label>
                    </div>
                </div>
                <button type="button" class="btn-primary w-full" @click="selisihTerbuka=null, $store.toast.push('Selisih disetujui & tercatat di audit (mock)', 'success')">Setujui &amp; Simpan</button>
            </div>
        </div>
    </div>
</div>