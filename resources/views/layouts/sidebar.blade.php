@php
    $active = request()->route()?->getName();

    $navigation = [
        [
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10',
        ],
        [
            'group' => 'Master Data',
            'items' => [
                ['label' => 'Data Penitip', 'route' => 'master.penitip', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
                ['label' => 'Katalog Produk', 'route' => 'master.katalog-produk', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10'],
                ['label' => 'Lokasi Rak', 'route' => 'master.lokasi-rak', 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
            ],
        ],
        [
            'group' => 'Inbound',
            'items' => [
                ['label' => 'Stock In Pribadi', 'route' => 'inbound.stock-in-pribadi', 'icon' => 'M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z'],
                ['label' => 'Consignment In', 'route' => 'inbound.consignment-in', 'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z M12 12v4m0 0l-2-2m2 2l2-2'],
                ['label' => 'Cetak / Re-print Label', 'route' => 'inbound.cetak-label', 'icon' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z'],
            ],
        ],
        [
            'group' => 'Inventory',
            'items' => [
                ['label' => 'Live Stock', 'route' => 'inventory.live-stock', 'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
                ['label' => 'Karantina', 'route' => 'inventory.karantina', 'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
                ['label' => 'Stok Opname', 'route' => 'inventory.stok-opname', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                ['label' => 'Retur Penitip (RTV)', 'route' => 'inventory.retur-rtv', 'icon' => 'M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z'],
            ],
        ],
        [
            'group' => 'POS / Kasir',
            'items' => [
                ['label' => 'Kasir', 'route' => 'pos.kasir', 'icon' => 'M3 10h18M7 15h2m4 0h2M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z'],
                ['label' => 'Riwayat Transaksi', 'route' => 'pos.riwayat', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
                ['label' => 'Shift Kasir', 'route' => 'pos.shift-kasir', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ],
        ],
        [
            'group' => 'Reports & Analisis',
            'items' => [
                ['label' => 'Consignor Settlement', 'route' => 'report.settlement', 'icon' => 'M9 7h6m-6 4h6m-6 4h6M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z'],
                ['label' => 'Profit Margin vs Fee', 'route' => 'report.margin', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['label' => 'Laporan Penjualan & Stok', 'route' => 'report.laporan', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['label' => 'Audit Log', 'route' => 'report.audit-log', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ],
        ],
        [
            'group' => 'Pengaturan',
            'items' => [
                ['label' => 'Pengguna & Role', 'route' => 'setting.pengguna', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
                ['label' => 'Perangkat', 'route' => 'setting.perangkat', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
                ['label' => 'Template WhatsApp', 'route' => 'setting.wa-template', 'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
                ['label' => 'Parameter Sistem', 'route' => 'setting.parameter', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
            ],
        ],
    ];

    $isActive = function ($route) use ($active) {
        return $active === $route;
    };
@endphp

<!-- Mobile overlay -->
<div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
     class="fixed inset-0 z-30 bg-text-strong/40 lg:hidden"></div>

<aside x-data="{ isDesktop: window.matchMedia('(min-width: 1024px)').matches }"
       x-show="isDesktop || sidebarOpen" x-cloak
       x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="-translate-x-full"
       x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-150"
       x-transition:leave-start="translate-x-0"
       x-transition:leave-end="-translate-x-full"
       class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-border-subtle bg-surface-lowest lg:translate-x-0 lg:transition-none">
    <div class="flex h-16 items-center justify-between border-b border-border-subtle px-5">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-on-primary">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 13l4 4L19 7"/>
                </svg>
            </span>
            <span class="text-headline-sm font-semibold text-text-strong">SemiERP<wbr>HotWheels</span>
        </a>
        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted hover:bg-canvas lg:hidden" @click="sidebarOpen = false" aria-label="Tutup menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
        </button>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
        @foreach ($navigation as $section)
            @if (isset($section['items']))
                <div>
                    <p class="px-3 pb-2 text-label-sm uppercase tracking-wider text-text-subtle">{{ $section['group'] }}</p>
                    <ul class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            <li>
                                <a href="{{ route($item['route']) }}"
                                   @class([
                                       'group flex items-center gap-3 rounded-lg px-3 py-2 text-body-md font-medium transition',
                                       'bg-primary-soft text-primary' => $isActive($item['route']),
                                       'text-text-muted hover:bg-canvas hover:text-text-strong' => !$isActive($item['route']),
                                   ])>
                                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="{{ $item['icon'] }}"/>
                                    </svg>
                                    {{ $item['label'] }}
                                    @if ($active === 'inventory.karantina' && $item['route'] === 'inventory.karantina')
                                        <span class="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-karantina-bg px-1.5 text-label-sm text-karantina-text">3</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div>
                    <a href="{{ route($section['route']) }}"
                       @class([
                           'group flex items-center gap-3 rounded-lg px-3 py-2 text-body-md font-medium transition',
                           'bg-primary-soft text-primary' => $isActive($section['route']),
                           'text-text-muted hover:bg-canvas hover:text-text-strong' => !$isActive($section['route']),
                       ])>
                        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $section['icon'] }}"/>
                        </svg>
                        {{ $section['label'] }}
                    </a>
                </div>
            @endif
        @endforeach
    </nav>

    <div class="border-t border-border-subtle p-4">
        <div class="flex items-center gap-2 rounded-lg bg-canvas px-3 py-2.5">
            <span class="relative flex h-2.5 w-2.5">
                <span class="absolute inline-flex h-full w-full rounded-full bg-success-text opacity-50"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-success-text"></span>
            </span>
            <div class="text-body-sm">
                <p class="font-semibold text-text-strong">Tersinkron</p>
                <p class="text-label-sm text-text-muted">Shift Reguler 1 · Ahmad Fauzi</p>
            </div>
        </div>
    </div>
</aside>