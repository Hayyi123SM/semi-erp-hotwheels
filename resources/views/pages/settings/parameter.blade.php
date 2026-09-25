<x-ui.page-header
    title="Parameter Sistem"
    subtitle="Parameter operasional global: label, skema, karantina & PWA."
    :crumbs="['Pengaturan', 'Parameter Sistem']"
>
</x-ui.page-header>

<div class="space-y-6" x-data="{}">
    <x-ui.section-card title="Kebijakan Operasional">
        <div class="space-y-5">
            @foreach ([
                ['l' => 'Batas re-print label per SKU/hari', 'v' => 3, 'd' => 'Melebihi batas memerlukan PIN Owner.'],
                ['l' => 'SLA karantina (hari)', 'v' => 3, 'd' => 'Lampaui → badge ⚠ aging. 7 hari → 🔴 eskalasi Owner.'],
                ['l' => 'Aging threshold otomatis RTV (hari)', 'v' => 60, 'd' => 'SKU titipan tersapu tanpa transaksi otomatis masuk daftar RTV.'],
                ['l' => 'Ambiguitas minimum barcode (persen)', 'v' => 70, 'd' => 'Match score di bawah threshold dianggap ambigu (BR-11).'],
            ] as $param)
                <div class="flex items-center justify-between gap-4 border-b border-border-subtle pb-4 last:border-0 last:pb-0">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">{{ $param['l'] }}</p>
                        <p class="text-label-sm text-text-muted">{{ $param['d'] }}</p>
                    </div>
                    <input type="number" class="input-base w-24 text-center tabular-nums" value="{{ $param['v'] }}">
                </div>
            @endforeach
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Ekonomi &amp; Preferensi">
        <div class="space-y-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Timezone &amp; Lokal</p>
                    <p class="text-label-sm text-text-muted">Format Rp + tanggal id-ID (WIB).</p>
                </div>
                <select class="input-base w-44">
                    <option>Asia/Jakarta (WIB)</option>
                    <option>Asia/Makassar (WITA)</option>
                </select>
            </div>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Normalisasi SKU Internal WMS</p>
                    <p class="text-label-sm text-text-muted">Terapkan format {OWNER}-{SERI}-{URUT} saat scan pabrik.</p>
                </div>
                <div class="inline-flex items-center gap-2" x-data="{ on: true }">
                    <button type="button"
                            class="relative h-6 w-11 rounded-full transition"
                            :class="on ? 'bg-primary' : 'bg-border-strong'"
                            @click="on = !on">
                        <span class="absolute top-0.5 h-5 w-5 rounded-full bg-surface-lowest shadow transition" :class="on ? 'left-[22px]' : 'left-0.5'"></span>
                    </button>
                    <span class="text-label-md text-text-muted" x-text="on ? 'Aktif' : 'Nonaktif'"></span>
                </div>
            </div>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Sound Beep Scanner</p>
                    <p class="text-label-sm text-text-muted">Konfirmasi sukses scan via WebAudio.</p>
                </div>
                <div class="inline-flex items-center gap-2" x-data="{ on: true }">
                    <button type="button"
                            class="relative h-6 w-11 rounded-full transition"
                            :class="on ? 'bg-primary' : 'bg-border-strong'"
                            @click="on = !on">
                        <span class="absolute top-0.5 h-5 w-5 rounded-full bg-surface-lowest shadow transition" :class="on ? 'left-[22px]' : 'left-0.5'"></span>
                    </button>
                    <span class="text-label-md text-text-muted" x-text="on ? 'Aktif' : 'Nonaktif'"></span>
                </div>
            </div>
        </div>
    </x-ui.section-card>

    <div class="flex justify-end">
        <button type="button" class="btn-primary" @click="$store.toast.push('Parameter tersimpan (mock)', 'success')">Simpan Parameter</button>
    </div>
</div>