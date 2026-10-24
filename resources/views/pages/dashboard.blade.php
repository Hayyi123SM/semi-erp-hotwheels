@use('App\Support\Format')

<x-ui.page-header
    title="Dashboard"
    subtitle="Ringkasan operasional &amp; performa hari ini · {{ $today }}"
    :crumbs="['Home', 'Dashboard']"
>
    <x-slot:actions>
        <a href="{{ route('pos.kasir') }}" class="btn-primary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M7 15h2m4 0h2M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>
            Buka Kasir
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    @if ($warnings['karantina'] || $warnings['belumBerlabel'])
        <x-ui.banner tone="{{ $warnings['karantina'] ? 'error' : 'warning' }}">
            <span class="font-semibold">Perlu perhatian.</span>
            @if ($warnings['karantina'])
                {{ $warnings['karantina'] }} kasus karantina menunggu verifikasi
                @if ($warnings['karantinaAging'] > 0)
                    (tertua {{ $warnings['karantinaAging'] }} hari)
                @endif
                · <a href="{{ route('inventory.karantina') }}" class="font-semibold underline">Buka Karantina →</a>
            @endif
            @if ($warnings['karantina'] && $warnings['belumBerlabel'])
                ·
            @endif
            @if ($warnings['belumBerlabel'])
                {{ $warnings['belumBerlabel'] }} label menunggu konfirmasi cetak ·
                <a href="{{ route('inbound.cetak-label') }}" class="font-semibold underline">Buka Antrean Label →</a>
            @endif
        </x-ui.banner>
    @endif

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card
            label="Penjualan Hari Ini"
            value="{{ Format::rupiah($cards['penjualanHariIni']) }}"
            :delta="$cards['pertumbuhanPenjualan'] === null
                ? ($cards['notaHariIni'].' nota · belum ada data kemarin')
                : ($cards['pertumbuhanPenjualan'] >= 0 ? '+' : '').$cards['pertumbuhanPenjualan'].'% dari kemarin'"
            :delta-tone="$cards['pertumbuhanPenjualan'] !== null && $cards['pertumbuhanPenjualan'] < 0 ? 'error' : 'success'"
            icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
        />

        @if ($isOwner)
            <x-ui.stat-card
                label="Estimasi Margin Hari Ini"
                value="{{ Format::rupiah($cards['marginHariIni']) }}"
                delta="laba pribadi + fee titipan"
                delta-tone="success"
                icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
            />
            <x-ui.stat-card
                label="Saldo Titipan"
                value="{{ Format::rupiah($cards['saldoTitipan']) }}"
                :delta="$cards['penitipBerhutang'].' penitip dengan sisa hak'"
                delta-tone="info"
                icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
            />
            <x-ui.stat-card
                label="Pengeluaran Hari Ini"
                value="{{ Format::rupiah($cards['pengeluaranHariIni'] ?? 0) }}"
                delta="catatan terpisah untuk Owner"
                delta-tone="warning"
                icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
            <x-ui.stat-card
                label="Laba Bersih Estimasi Hari Ini"
                value="{{ Format::rupiah($cards['labaBersihEstimasiHariIni'] ?? 0) }}"
                delta="Margin Toko - Pengeluaran Operasional"
                :delta-tone="($cards['labaBersihEstimasiHariIni'] ?? 0) >= 0 ? 'success' : 'error'"
                icon="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
            />
            <x-ui.stat-card
                label="Pengeluaran Bulan Ini"
                value="{{ Format::rupiah($cards['pengeluaranBulanIni'] ?? 0) }}"
                delta="akumulasi bulan berjalan"
                delta-tone="warning"
                icon="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
            />
            <x-ui.stat-card
                label="Laba Bersih Estimasi Bulan Ini"
                value="{{ Format::rupiah($cards['labaBersihEstimasiBulanIni'] ?? 0) }}"
                delta="Margin Toko Bulanan - Pengeluaran Operasional"
                :delta-tone="($cards['labaBersihEstimasiBulanIni'] ?? 0) >= 0 ? 'success' : 'error'"
                icon="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
            />
        @else
            <x-ui.stat-card
                label="Nota Hari Ini"
                value="{{ $cards['notaHariIni'] }}"
                delta="transaksi yang sudah lunas"
                delta-tone="success"
                icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
            />
            <x-ui.stat-card
                label="Stok Menipis"
                value="{{ $cards['stokMenipis'] }}"
                delta="SKU tinggal ≤ 1 unit"
                :delta-tone="$cards['stokMenipis'] ? 'warning' : 'success'"
                icon="M5 13l4 4L19 7"
            />
        @endif

        <x-ui.stat-card
            label="Unit Stok Tersedia"
            value="{{ $cards['unitStok'] }}"
            :delta="'live-stock · '.$cards['stokMenipis'].' menipis'"
            :delta-tone="$cards['stokMenipis'] ? 'warning' : 'info'"
            icon="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"
        />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 card">
            <div class="flex items-center justify-between border-b border-border-subtle px-6 py-4">
                <div>
                    <h2 class="text-headline-sm text-text-strong">Aktivitas Terkini</h2>
                    <p class="text-label-sm text-text-muted">Kasir memantau transaksi sebelum menyelesaikan shift.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-canvas px-2.5 py-1 text-label-sm text-text-muted">
                    <span class="h-1.5 w-1.5 rounded-full bg-success-text"></span> Live
                </span>
            </div>

            @if (count($activities) === 0)
                <x-ui.empty-state
                    title="Belum ada aktivitas"
                    description="Scan transaksi atau proses stock in untuk mulai mengisi log operasional."
                />
            @else
                <ul class="divide-y divide-border-subtle">
                    @foreach ($activities as $activity)
                        <li class="flex items-start gap-4 px-6 py-3.5 transition hover:bg-canvas">
                            <span class="w-20 shrink-0 pt-0.5 text-label-sm text-text-subtle tabular-nums">{{ $activity['time'] }}</span>
                            <span class="flex-1 text-body-sm text-text-strong">{{ $activity['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card card-pad">
                <h2 class="text-headline-sm text-text-strong">Kapasitas Rak</h2>
                <ul class="mt-4 space-y-3.5">
                    @forelse ($racks as $rack)
                        <li>
                            <div class="mb-1 flex items-center justify-between text-body-sm">
                                <span class="font-medium text-text-strong">{{ $rack['code'] }}</span>
                                <span class="text-text-muted tabular-nums">{{ $rack['items'] }}/{{ $rack['capacity'] }} · {{ $rack['usage'] }}%</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-canvas">
                                <div class="h-2 rounded-full transition-all duration-500
                                    {{ $rack['usage'] >= 90 ? 'bg-error-text' : ($rack['usage'] >= 75 ? 'bg-warning-text' : 'bg-primary') }}"
                                     style="width: {{ $rack['usage'] }}%"></div>
                            </div>
                        </li>
                    @empty
                        <li class="text-body-sm text-text-muted">Belum ada rak aktif.</li>
                    @endforelse
                </ul>
            </div>

            @if ($isOwner)
                <div class="card card-pad">
                    <div class="flex items-center justify-between">
                        <h2 class="text-headline-sm text-text-strong">Top Consignor</h2>
                        <a href="{{ route('report.settlement') }}" class="text-label-md font-semibold text-primary hover:underline">Settlement →</a>
                    </div>
                    <ul class="mt-4 space-y-3">
                        @forelse ($consignors as $consignor)
                            <li class="flex items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-canvas text-label-md font-semibold text-text-muted">{{ $loop->iteration }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-body-sm font-medium text-text-strong">{{ $consignor['name'] }}</p>
                                        <p class="font-mono text-label-sm text-text-subtle">{{ $consignor['code'] }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 text-body-md font-semibold text-text-strong tabular-nums">{{ Format::rupiah($consignor['balance']) }}</span>
                            </li>
                        @empty
                            <li class="text-body-sm text-text-muted">Semua hak penitip sudah lunas.</li>
                        @endforelse
                    </ul>
                </div>
            @endif

            <div class="card card-pad">
                <div class="flex items-center justify-between">
                    <h2 class="text-headline-sm text-text-strong">Stok Opname</h2>
                    <a href="{{ route('inventory.stok-opname') }}" class="text-label-md font-semibold text-primary hover:underline">Lihat →</a>
                </div>
                <ul class="mt-4 divide-y divide-border-subtle">
                    @forelse ($opnames as $opname)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-label-sm text-text-strong">{{ $opname['no'] }}</p>
                                <p class="text-label-sm text-text-muted">{{ $opname['scope'] }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-label-sm font-semibold tabular-nums">{{ $opname['diffQty'] }} selisih</p>
                                <p class="text-label-sm text-primary">{{ $opname['status'] }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="text-body-sm text-text-muted">Belum ada sesi opname.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>