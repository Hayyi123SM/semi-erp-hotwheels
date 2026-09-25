<x-ui.page-header
    title="Perangkat"
    subtitle="Konfigurasi printer termal, laci kas, endpoint synchronizer & koneksi offline."
    :crumbs="['Pengaturan', 'Perangkat']"
>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.section-card title="Printer Label (WMS-01)">
            <div class="space-y-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">D-Label D4 · USB (203 DPI)</p>
                        <p class="text-label-sm text-text-muted">Rak A · 3×2 cm default</p>
                    </div>
                    <x-ui.badge-status type="success" dot>ONLINE</x-ui.badge-status>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Ukuran Label Default" name="dev_label">
                        <select id="dev_label" class="input-base">
                            <option>3×2 cm</option>
                            <option>4×3 cm</option>
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Densitas Cetak" name="dev_dpi">
                        <select id="dev_dpi" class="input-base">
                            <option>203 DPI</option>
                            <option>300 DPI</option>
                        </select>
                    </x-ui.field>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="btn-primary" @click="$store.toast.push('Tes cetak terkirim (mock)', 'success')">Test Print</button>
                    <button type="button" class="btn-secondary" @click="$store.toast.push('Kalibrasi label selesai (mock)', 'info')">Kalibrasi Label</button>
                </div>
            </div>
        </x-ui.section-card>

        <x-ui.section-card title="Printer Struk (POS-01)">
            <div class="space-y-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">Struk 80mm · Ethernet</p>
                        <p class="text-label-sm text-text-muted">Terakhir online 13:40 WIB</p>
                    </div>
                    <x-ui.badge-status type="error">OFFLINE</x-ui.badge-status>
                </div>
                <x-ui.banner tone="warning">
                    Printer struk offline <b>2 jam</b>. Transaksi tetap berjalan (antrean lokal tersimpan).
                </x-ui.banner>
                <div class="flex items-center justify-between rounded-lg border border-border-subtle bg-canvas px-4 py-3">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">Laci Kas (Cash Drawer)</p>
                        <p class="text-label-sm text-text-muted">Trigger pada transaksi TUNAI</p>
                    </div>
                    <div class="inline-flex items-center gap-2" x-data="{ casher: true }">
                        <button type="button"
                                class="relative h-6 w-11 rounded-full transition"
                                :class="casher ? 'bg-primary' : 'bg-border-strong'"
                                @click="casher = !casher">
                            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-surface-lowest shadow transition" :class="casher ? 'left-[22px]' : 'left-0.5'"></span>
                        </button>
                        <span class="text-label-md text-text-muted" x-text="casher ? 'Aktif' : 'Nonaktif'"></span>
                    </div>
                </div>
            </div>
        </x-ui.section-card>
    </div>

    <x-ui.section-card title="Sync Offline (Pending Queue)">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-info-bg text-info-text">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h5M20 20v-5h-5m-9.5-5.5A9 9 0 0019.5 19M4.66 15A9 9 0 017 4.66a9 9 0 007.34.5"/></svg>
                </span>
                <div>
                    <p class="text-body-md font-semibold text-text-strong">Endpoint / perangkat pengumpul</p>
                    <p class="text-label-sm text-text-muted">POS-CP2 · terakhir sync 13:55 · 2 nota antrean</p>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary" @click="$store.toast.push('Sync sekarang (mock)', 'success')">Sync Sekarang</button>
            </div>
        </div>
    </x-ui.section-card>
</div>