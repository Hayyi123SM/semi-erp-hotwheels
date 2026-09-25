<x-ui.page-header
    title="Shift Kasir"
    subtitle="Buka & tutup shift, rekonsiliasi kas, dan sinkronisasi daring."
    :crumbs="['POS / Kasir', 'Shift Kasir']"
>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 lg:grid-cols-2">
        <div class="card card-pad">
            <h2 class="text-headline-sm text-text-strong">Shift Reguler 2 · Dewi Lestari</h2>
            <p class="mt-1 text-label-sm text-text-muted">Buka 12:00 WIB · POS-CP2</p>

            <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <dt class="text-label-md text-text-muted">Kas Awal</dt>
                    <dd class="text-headline-md font-semibold tabular-nums">Rp500.000</dd>
                </div>
                <div>
                    <dt class="text-label-md text-text-muted">Kas Akhir (riil)</dt>
                    <dd class="text-headline-md font-semibold tabular-nums">Rp1.735.500</dd>
                </div>
                <div>
                    <dt class="text-label-md text-text-muted">Kas Sistem</dt>
                    <dd class="text-headline-md font-semibold tabular-nums">Rp1.732.000</dd>
                </div>
                <div>
                    <dt class="text-label-md text-text-muted">Selisih</dt>
                    <dd class="text-headline-md font-semibold tabular-nums text-warning-text">+Rp3.500</dd>
                </div>
            </dl>
        </div>

        <div class="card card-pad">
            <div class="flex items-center justify-between">
                <h2 class="text-headline-sm text-text-strong">Rekap per Metode</h2>
                <span class="text-label-sm text-text-subtle">7 nota · Rp4.215.000</span>
            </div>
            <ul class="mt-5 divide-y divide-border-subtle">
                @foreach ([
                    ['m' => 'TUNAI', 'n' => 4, 'total' => 2410000],
                    ['m' => 'QRIS', 'n' => 2, 'total' => 1210000],
                    ['m' => 'KARTU', 'n' => 1, 'total' => 595000],
                ] as $row)
                    <li class="flex items-center justify-between py-3">
                        <span class="text-body-md text-text-strong">{{ $row['m'] }}</span>
                        <span class="text-label-sm text-text-muted tabular-nums">{{ $row['n'] }} nota</span>
                        <span class="text-body-md font-semibold tabular-nums">{{ \App\Support\MockData::rupiah($row['total']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <x-ui.section-card title="Warning &amp; Aksi">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <x-ui.banner tone="warning" class="lg:flex-1">
                <span class="font-semibold">Pending sync:</span>
                2 nota belum tersinkron (offline window 13:40–13:55). Akan ter-upload otomatis.
            </x-ui.banner>
            <div class="flex shrink-0 gap-2">
                <button type="button" class="btn-primary" @click="$store.toast.push('Shift ditutup &amp; rekap dikirim ke Owner (mock)', 'success')">
                    Tutup Shift
                </button>
            </div>
        </div>
    </x-ui.section-card>
</div>