{{--
    The profile dropdown, shared by the admin topbar and the POS header. Reads
    `$currentUser`, which both shells make available before including it.
--}}
<div class="relative flex items-center gap-2 sm:gap-3" x-data="{ profileOpen: false }">
    <div class="hidden text-right sm:block">
        <p class="text-body-md font-semibold leading-tight text-text-strong">{{ $currentUser?->name }}</p>
        <p class="text-label-sm text-text-muted">{{ $currentUser?->role?->label() }}</p>
    </div>
    <button type="button" class="flex h-8 w-8 sm:h-9 sm:w-9 items-center justify-center rounded-full bg-primary text-label-md sm:text-headline-sm font-semibold text-on-primary" @click="profileOpen = !profileOpen" aria-label="Menu profil {{ $currentUser?->name }}">{{ $currentUser?->initials() }}</button>

    <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false" x-transition
         class="absolute right-2 sm:right-4 top-14 sm:top-16 w-52 sm:w-56 rounded-xl border border-border-subtle bg-surface-lowest p-1.5 shadow-lg">
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
