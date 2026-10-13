{{--
    POS header component: responsive for mobile/tablet/desktop.
    - On mobile: logo + status dots + actions stay on top; tabs scroll horizontally below if needed via flex-wrap order.
    - On >= sm: single row layout, tabs inline with logo, status as pills.
--}}
@php
    $currentUser = auth()->user();
    $homeRoute = $currentUser?->homeRoute() ?? 'home';

    $posTabs = [
        ['label' => 'Kasir', 'route' => 'pos.kasir', 'active' => request()->routeIs('pos.kasir')],
        ['label' => 'Riwayat', 'route' => 'pos.riwayat', 'active' => request()->routeIs('pos.riwayat') || request()->routeIs('pos.nota')],
        ['label' => 'Shift Kasir', 'route' => 'pos.shift-kasir', 'active' => request()->routeIs('pos.shift-kasir*')],
    ];
@endphp

<header class="sticky top-0 z-20 border-b border-border-subtle bg-surface-lowest/95 backdrop-blur">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-3 py-2 sm:flex-nowrap sm:gap-3 sm:px-4 sm:py-0 sm:h-14 lg:px-6"
         x-data="{ online: window.navigator.onLine }"
         x-init="
             const update = () => { online = navigator.onLine };
             window.addEventListener('online', update);
             window.addEventListener('offline', update);
             update();
         ">
        {{-- Left: Logo + Tabs --}}
        <div class="flex min-w-0 items-center gap-2 sm:gap-3 order-1">
            <a href="{{ route($homeRoute) }}" class="flex min-w-0 flex-none items-center gap-2.5" title="SemiERP HotWheels">
                <img class="w-7 sm:w-8 flex-none" src="/assets/images/167_LOGO.png" alt="167 Diecast" />
            </a>

            <nav class="-mx-1 flex items-center gap-0.5 overflow-x-auto overscroll-x-contain scrollbar-none"
                 style="mask-image: linear-gradient(to right, transparent 0, black 16px, black calc(100% - 16px), transparent 100%)"
                 aria-label="Navigasi POS">
                @foreach ($posTabs as $tab)
                    <a href="{{ route($tab['route']) }}"
                       @class([
                           'group flex items-center whitespace-nowrap rounded-lg px-2 py-1.5 text-label-md sm:px-3 sm:py-2 sm:text-body-sm font-medium transition focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                           'bg-primary-soft text-primary' => $tab['active'],
                           'text-text-muted hover:bg-canvas hover:text-text-strong' => ! $tab['active'],
                       ])
                       @if ($tab['active']) aria-current="page" @endif>
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>

        {{-- Right: Status + Admin + User Menu --}}
        <div class="flex items-center gap-2 sm:gap-3 order-2">
            {{-- Online/Offline - compact on mobile, pill on sm+ --}}
            <span class="inline-flex sm:hidden items-center justify-center rounded-full p-1" x-show="online" title="ONLINE">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-success-text opacity-60"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-success-text"></span>
                </span>
            </span>
            <span class="hidden items-center gap-1.5 rounded-full px-2 py-0.5 text-label-sm sm:inline-flex" x-show="online">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-success-text opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-success-text"></span>
                </span>
                <span class="font-semibold text-success-text">ONLINE</span>
            </span>

            <span class="inline-flex sm:hidden items-center justify-center rounded-full bg-error-bg p-1" x-show="!online" title="OFFLINE">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-error-text opacity-60"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-error-text"></span>
                </span>
            </span>
            <span class="hidden items-center gap-1.5 rounded-full bg-error-bg px-2 py-0.5 text-label-sm text-error-text sm:inline-flex" x-show="!online">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-error-text opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-error-text"></span>
                </span>
                <span class="font-semibold">OFFLINE</span>
            </span>

            @if ($currentUser?->isOwner())
                <a href="{{ route($currentUser?->homeRoute() ?? 'home') }}" class="btn-ghost h-8 sm:h-9 px-2 sm:px-3" title="Kembali ke halaman admin" aria-label="Kembali ke halaman admin">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10"/>
                    </svg>
                    <span class="hidden sm:inline">Admin</span>
                </a>
            @endif

            @include('layouts.partials.user-menu')
        </div>
    </div>
</header>
