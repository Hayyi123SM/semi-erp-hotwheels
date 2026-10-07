<x-ui.page-header
    title="Consignor Settlement"
    subtitle="Rekonsiliasi penjualan barang titipan & pembayaran hak penitip."
    :crumbs="['Reports & Analisis', 'Consignor Settlement']"
>
    <x-slot:actions>
        <button type="button" class="btn-primary" @click="$store.toast.push('Settlement baru dibentuk dari penjualan terpilih (mock)', 'success')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
            Buat Settlement
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Draft Settlement" value="1" delta="CN01 · 12 SKU" delta-tone="info" />
        <x-ui.stat-card label="Menunggu Pembayaran" value="Rp1.248.000" delta="2 penitip" delta-tone="warning" />
        <x-ui.stat-card label="Dibayar (30 hari)" value="Rp5.360.000" delta="+Rp412.000 minggu ini" delta-tone="success" icon="M5 13l4 4L19 7" />
        <x-ui.stat-card label="Carry-over" value="Rp96.000" delta="1 nota refund" delta-tone="error" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.section-card title="Statement CN01 · Budi Santoso">
                <x-slot:actions>
                    <x-ui.badge-status type="info">DRAFT</x-ui.badge-status>
                </x-slot:actions>

                <div class="mb-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-canvas p-4">
                        <p class="text-label-md text-text-muted">Periode</p>
                        <p class="text-body-md font-semibold">01 – 24 Sep 2026</p>
                    </div>
                    <div class="rounded-lg bg-canvas p-4">
                        <p class="text-label-md text-text-muted">Total Terjual</p>
                        <p class="text-body-md font-semibold tabular-nums">Rp2.300.000</p>
                    </div>
                    <div class="rounded-lg bg-canvas p-4">
                        <p class="text-label-md text-text-muted">Net Payable (20%)</p>
                        <p class="text-body-md font-semibold tabular-nums">Rp1.840.000</p>
                    </div>
                </div>

                <div class="table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Nota / Tanggal</th>
                                <th class="px-4 py-3 font-semibold">SKU</th>
                                <th class="px-4 py-3 text-right font-semibold">Harga Jual</th>
                                <th class="px-4 py-3 text-right font-semibold">Fee (20%)</th>
                                <th class="px-4 py-3 text-right font-semibold">Hak Penitip</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach ([
                                ['n' => 'POS-CP2-2026-08913 · 22 Sep', 's' => 'CN01-HW-001', 'jual' => 220000, 'fee' => 44000, 'hak' => 176000],
                                ['n' => 'POS-CP2-2026-08911 · 22 Sep', 's' => 'CN01-HW-002', 'jual' => 185000, 'fee' => 37000, 'hak' => 148000],
                                ['n' => 'POS-CP2-2026-08910 · 22 Sep', 's' => 'CN01-HW-001', 'jual' => 220000, 'fee' => 44000, 'hak' => 176000],
                                ['n' => 'POS-CP2-2026-08908 · 21 Sep', 's' => 'CN01-HW-002', 'jual' => 185000, 'fee' => 37000, 'hak' => 148000],
                            ] as $st)
                                <tr class="row-dense">
                                    <td class="px-4 py-3 text-body-sm text-text-muted tabular-nums">{{ $st['n'] }}</td>
                                    <td class="px-4 py-3 font-mono text-sku text-text-strong">{{ $st['s'] }}</td>
                                    <td class="px-4 py-3 text-right text-body-md tabular-nums">{{ \App\Support\MockData::rupiah($st['jual']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md text-text-muted tabular-nums">{{ \App\Support\MockData::rupiah($st['fee']) }}</td>
                                    <td class="px-4 py-3 text-right text-body-md font-semibold text-titip-text tabular-nums">{{ \App\Support\MockData::rupiah($st['hak']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-canvas">
                                <td colspan="4" class="px-4 py-4 text-right text-label-md font-semibold text-text-muted">TOTAL NET PAYABLE</td>
                                <td class="px-4 py-4 text-right text-headline-md font-bold text-titip-text tabular-nums">Rp1.840.000</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="$store.toast.push('Statement PDF dibuat (mock)', 'info')">Unduh PDF</button>
                    <button type="button" class="btn-secondary" @click="$store.toast.push('Statement dikirim via WhatsApp (mock)', 'success')">Kirim WA</button>
                    <button type="button" class="btn-primary" @click="$store.toast.push('Settlement dikonfirmasi & siap dibayar (mock)', 'success')">Konfirmasi &amp; Cairkan</button>
                </div>
            </x-ui.section-card>

            <x-ui.section-card title="Pembayaran Parsial &amp; Riwayat">
                <ul class="divide-y divide-border-subtle">
                    @foreach ([
                        ['d' => '18 Sep 2026', 'n' => 'TRF-BCA-8821', 'v' => 1200000, 's' => 'Completed'],
                        ['d' => '04 Sep 2026', 'n' => 'TRF-BCA-8470', 'v' => 940000, 's' => 'Completed'],
                        ['d' => '21 Agu 2026', 'n' => 'TRF-BCA-8092', 'v' => 800000, 's' => 'Completed'],
                    ] as $pay)
                        <li class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-body-sm text-text-strong">{{ $pay['d'] }}</p>
                                <p class="font-mono text-label-sm text-text-subtle">{{ $pay['n'] }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-body-md font-semibold tabular-nums">{{ \App\Support\MockData::rupiah($pay['v']) }}</p>
                                <x-ui.badge-status type="success">{{ $pay['s'] }}</x-ui.badge-status>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.section-card>
        </div>

        <div class="space-y-6">
            <x-ui.section-card title="Status Transaksi">
                <ol class="space-y-3">
                    @foreach ([
                        ['s' => 'DRAFT', 'd' => 'Statement disiapkan', 'a' => true],
                        ['s' => 'SENT', 'd' => 'Dikirim ke penitip', 'a' => true],
                        ['s' => 'PAYING', 'd' => 'Pembayaran berjalan', 'a' => false],
                        ['s' => 'CLOSED', 'd' => 'Selesai & tercatat', 'a' => false],
                    ] as $st)
                        <li class="flex items-center gap-3">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full text-label-sm font-bold
                                {{ $st['a'] ? 'bg-primary text-on-primary' : 'bg-canvas text-text-subtle' }}">
                                @if($st['a']) ✓ @else {{ $loop->iteration }} @endif
                            </span>
                            <div>
                                <p class="text-body-md font-semibold text-text-strong">{{ $st['s'] }}</p>
                                <p class="text-label-sm text-text-muted">{{ $st['d'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.section-card>

            <x-ui.section-card title="Carry-over &amp; Adjustment">
                <ul class="divide-y divide-border-subtle">
                    <li class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-body-sm text-text-strong">Refund POS-CP2-2026-08912</p>
                            <p class="text-label-sm text-text-muted">CN01 · −Rp44.000 (auto)</p>
                        </div>
                        <x-ui.badge-status type="warning">Refund</x-ui.badge-status>
                    </li>
                </ul>
            </x-ui.section-card>

            <x-ui.banner tone="info">
                <span class="font-semibold">Invarian BR-05:</span>
                <b>bruto = fee + hak penitip</b> selalu terverifikasi sebelum DRAFT → SENT.
            </x-ui.banner>
        </div>
    </div>
</div>