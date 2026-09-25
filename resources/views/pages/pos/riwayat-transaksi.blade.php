<x-ui.page-header
    title="Riwayat Transaksi"
    subtitle="Nota per shift, kasir & metode. Void/refund memerlukan PIN Owner."
    :crumbs="['POS / Kasir', 'Riwayat Transaksi']"
>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Transaksi Hari Ini" value="{{ count($transactions) }}" delta="7 nota" delta-tone="info" />
        <x-ui.stat-card label="Total Penjualan" value="Rp4.215.000" delta="+18,4%" delta-tone="success" icon="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
        <x-ui.stat-card label="Refund / Void" value="2" delta="1 void · 1 refund" delta-tone="warning" />
        <x-ui.stat-card label="Pending Sync" value="0" delta="Semua tersinkron" delta-tone="success" />
    </div>

    <x-ui.section-card>
        <x-ui.toolbar search-placeholder="Cari nota POS-...">
            <x-slot:filters>
                <select class="input-base h-11 w-auto">
                    <option>Semua Shift</option>
                    <option>Reguler Shift 1</option>
                    <option>Reguler Shift 2</option>
                </select>
                <select class="input-base h-11 w-auto">
                    <option>Semua Metode</option>
                    <option>TUNAI</option>
                    <option>QRIS</option>
                    <option>KARTU</option>
                </select>
                <select class="input-base h-11 w-auto">
                    <option>Semua Status</option>
                    <option>LUNAS</option>
                    <option>REFUND</option>
                    <option>VOID</option>
                </select>
            </x-slot:filters>
        </x-ui.toolbar>

        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">Nota</th>
                    <th class="px-6 py-3 font-semibold">Waktu</th>
                    <th class="px-6 py-3 font-semibold">Kasir</th>
                    <th class="px-6 py-3 font-semibold">Metode</th>
                    <th class="px-6 py-3 text-center font-semibold">Item</th>
                    <th class="px-6 py-3 text-right font-semibold">Total</th>
                    <th class="px-6 py-3 text-right font-semibold">Status</th>
                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                @foreach ($transactions as $tx)
                    <tr class="row-dense transition hover:bg-canvas">
                        <td class="px-6 py-3 font-mono text-sku text-text-strong">{{ $tx['nota'] }}</td>
                        <td class="px-6 py-3 text-body-sm text-text-muted tabular-nums">{{ $tx['time'] }}</td>
                        <td class="px-6 py-3 text-body-sm text-text-strong">{{ $tx['casher'] }}</td>
                        <td class="px-6 py-3 text-body-sm text-text-muted">{{ $tx['method'] }}</td>
                        <td class="px-6 py-3 text-center text-body-md tabular-nums">{{ $tx['items'] }}</td>
                        <td class="px-6 py-3 text-right text-body-md font-semibold tabular-nums">{{ \App\Support\MockData::rupiah($tx['total']) }}</td>
                        <td class="px-6 py-3 text-right">
                            @if ($tx['status'] === 'LUNAS')
                                <x-ui.badge-status type="success">LUNAS</x-ui.badge-status>
                            @elseif ($tx['status'] === 'REFUND')
                                <x-ui.badge-status type="warning">REFUND</x-ui.badge-status>
                            @else
                                <x-ui.badge-status type="error">VOID</x-ui.badge-status>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn-ghost h-9 px-3"
                                        @click="$store.toast.push('Detail nota dibuka: {{ $tx['nota'] }}', 'info')">Detail</button>
                                @if ($tx['status'] === 'LUNAS')
                                    <button type="button" class="btn-ghost h-9 px-3 text-error-text"
                                            @click="$store.toast.push('Void memerlukan PIN Owner (mock)', 'warning')">Void</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.section-card>
</div>