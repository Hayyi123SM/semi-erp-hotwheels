<header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-border-subtle bg-surface-lowest/95 px-4 backdrop-blur sm:px-6 lg:px-8">
    <div class="flex min-w-0 items-center gap-3">
        {{-- The tablet rail is always on screen, so only mobile needs the drawer trigger. --}}
        <button type="button" x-ref="menuToggle" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-text-muted hover:bg-canvas md:hidden"
                @click="toggleDrawer()" :aria-expanded="open.toString()" aria-controls="app-sidebar" aria-label="Buka menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>

        <div class="hidden items-center gap-2 md:flex">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-label-sm" x-show="$store.network.online">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-success-text opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-success-text"></span>
                </span>
                <span class="font-semibold text-success-text">ONLINE</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-error-bg px-2.5 py-0.5 text-label-sm text-error-text" x-show="!$store.network.online">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-error-text opacity-60"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-error-text"></span>
                </span>
                <span class="font-semibold">OFFLINE</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-warning-bg px-2.5 py-0.5 text-label-sm text-warning-text" x-show="$store.network.pendingSync > 0">
                <span x-text="'Antrean sync: ' + $store.network.pendingSync"></span>
            </span>
            @if ($currentUser?->isOwner() && $quarantine['count'] > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-info-bg px-2.5 py-0.5 text-label-sm text-info-text" title="Tertua {{ $quarantine['oldestDays'] }} hari">Karantina: {{ $quarantine['count'] }}</span>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-3">
        <span class="hidden text-label-sm italic text-text-subtle xl:block">{{ $store['store.name'] }} · {{ $store['store.branch'] }}</span>

        <div class="relative" x-data="{ notifOpen: false }">
            <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-lg text-text-muted transition hover:bg-canvas hover:text-text-strong"
                    @click="notifOpen = !notifOpen"
                    :aria-expanded="notifOpen.toString()" aria-controls="notification-panel" aria-label="Notifikasi">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                @if ($notifications['count'] > 0)
                    <span class="absolute right-1.5 top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-error-text text-label-sm text-on-primary">{{ $notifications['count'] }}</span>
                @endif
            </button>

            <div id="notification-panel" x-show="notifOpen" x-cloak @click.outside="notifOpen = false" @keydown.escape.window="notifOpen = false" x-transition
                 role="list" aria-label="Notifikasi"
                 class="absolute right-0 top-14 z-50 max-h-[70vh] w-96 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border border-border-subtle bg-surface-lowest shadow-lg">
                <header class="sticky top-0 flex items-center justify-between border-b border-border-subtle bg-surface-lowest px-4 py-3">
                    <h2 class="text-body-md font-semibold text-text-strong">Notifikasi</h2>
                    <span class="text-label-sm text-text-muted">{{ $notifications['count'] }} menunggu tindakan</span>
                </header>

                @forelse ($notifications['items'] as $notification)
                    @php $dot = match ($notification['tone']) { 'error' => 'bg-error-text', 'warning' => 'bg-warning-text', default => 'bg-info-text' }; @endphp
                    <a href="{{ $notification['href'] }}" class="group flex items-start gap-3 px-4 py-3 transition hover:bg-canvas" role="listitem">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-body-md font-semibold text-text-strong">{{ $notification['title'] }}</span>
                            <span class="block text-body-sm text-text-muted">{{ $notification['message'] }}</span>
                        </span>
                        <span class="mt-0.5 shrink-0 text-label-sm text-primary group-hover:underline">Buka →</span>
                    </a>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-body-sm text-text-muted">Semua beres. Tidak ada yang perlu ditinjau.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="relative flex items-center gap-3 border-l border-border-subtle pl-3" x-data="{ profileOpen: false }">
            <div class="hidden text-right sm:block">
                <p class="text-body-md font-semibold leading-tight text-text-strong">{{ $currentUser?->name }}</p>
                <p class="text-label-sm text-text-muted">{{ $currentUser?->role?->label() }}</p>
            </div>
            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-headline-sm font-semibold text-on-primary" @click="profileOpen = !profileOpen" aria-label="Menu profil {{ $currentUser?->name }}">{{ $currentUser?->initials() }}</button>

            <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false" x-transition
                 class="absolute right-4 top-16 w-56 rounded-xl border border-border-subtle bg-surface-lowest p-1.5 shadow-lg">
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-body-md text-text-muted hover:bg-canvas hover:text-text-strong">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Profil Saya
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-body-md text-error-text hover:bg-error-bg">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>