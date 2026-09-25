<x-ui.page-header
    title="Dashboard"
    subtitle="Ringkasan operasional & performa hari ini · Kamis, 24 Sep 2026"
    :crumbs="['Home', 'Dashboard']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Tombol cetak disiapkan untuk pre-print (opsional)', 'info')">
            Cetak
        </button>
        <a href="{{ route('pos.kasir') }}" class="btn-primary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M7 15h2m4 0h2M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>
            Buka Kasir
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.banner tone="warning">
        <span class="font-semibold">Karantina aging kritis.</span>
        {{ $quarantineOpen }} kasus menunggu verifikasi (1 kasus &gt; 7 hari).
        <a href="{{ route('inventory.karantina') }}" class="font-semibold underline">Buka Karantina →</a>
    </x-ui.banner>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card
            label="Penjualan Hari Ini"
            value="Rp4.215.000"
            delta="+18,4% dari kemarin"
            delta-tone="success"
            icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
        />
        <x-ui.stat-card
            label="Estimasi Margin Hari Ini"
            value="Rp892.400"
            delta="+9,1% dari kemarin"
            delta-tone="success"
            icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
        />
        <x-ui.stat-card
            label="Saldo Titipan Siap Cair"
            value="Rp2.512.000"
            delta="3 penitip aktif"
            delta-tone="info"
            icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
        />
        <x-ui.stat-card
            label="Unit Stok Tersedia"
            value="412"
            delta="8 unit terlaris"
            delta-tone="warning"
            icon="M5 13l4 4L19 7"
        />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Recent activity (7 col) -->
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
                    title="Belum ada aktivitas hari ini"
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

        <!-- Rack capacity + top consignor (5 col) -->
        <div class="space-y-6">
            <div class="card card-pad">
                <h2 class="text-headline-sm text-text-strong">Kapasitas Rak</h2>
                <ul class="mt-4 space-y-3.5">
                    @foreach ($racks as $rack)
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
                    @endforeach
                </ul>
            </div>

            <div class="card card-pad">
                <div class="flex items-center justify-between">
                    <h2 class="text-headline-sm text-text-strong">Top Consignor</h2>
                    <a href="{{ route('report.settlement') }}" class="text-label-md font-semibold text-primary hover:underline">Settlement →</a>
                </div>
                <ul class="mt-4 space-y-3">
                    @foreach ($consignors as $index => $consignor)
                        <li class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-label-md font-semibold text-text-muted">{{ $loop->iteration }}</span>
                                <div>
                                    <p class="text-body-sm font-medium text-text-strong">{{ $consignor['name'] }}</p>
                                    <p class="font-mono text-label-sm text-text-subtle">{{ $consignor['code'] }}</p>
                                </div>
                            </div>
                            <span class="text-body-md font-semibold text-text-strong tabular-nums">{{ \App\Support\MockData::rupiah($consignor['balance']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>