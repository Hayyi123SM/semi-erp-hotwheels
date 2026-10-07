<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Semi ERP Hot Wheels') }}</title>

        {{-- Seed the saved table/grid preference before first paint, so a
             returning grid user never watches the table render and get replaced. --}}
        <script>
            try {
                var view = window.localStorage.getItem(@js(\App\Support\DataTable\ViewPreference::storageKey()));

                if (view === 'grid' || view === 'table') {
                    document.documentElement.dataset.tableView = view;
                }
            } catch (e) {
                // Blocked storage: the CSS default of Table still applies.
            }
        </script>

        {{-- Whatever a redirect left in the session, handed to the client as data.
             A JSON script tag rather than an inline call: nothing in it is
             executable, and the app reads it before Alpine's first paint so the
             message is part of it. --}}
        @if (session()->has('toast'))
            <script type="application/json" id="flash-toast">@json(session('toast'))</script>
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body class="h-full bg-canvas font-sans text-text-strong antialiased">
        <div class="min-h-full" x-data="sidebarLayout">
            @include('layouts.sidebar')

            <div class="md:pl-[var(--sidebar-current)]">
                @include('layouts.topbar')

                <!-- Global toasts. Anchored to both edges below sm so a 320px
                     viewport cannot push them off screen, and pushed below the
                     topbar so the z-index no longer buries the header.
                     aria-live because these are the only announcement of a
                     change the reader made on another page, and the container
                     holds the polite region so a new message reads rather than
                     interrupts. -->
                <div class="fixed inset-x-4 top-20 z-[60] flex flex-col gap-2 sm:inset-x-auto sm:right-4 sm:top-4 sm:w-80"
                     x-data role="status" aria-live="polite" aria-atomic="false">
                    <template x-for="t in $store.toast.items" :key="t.id">
                        <div x-show="t" x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-x-4"
                             x-transition:enter-end="opacity-100 translate-x-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                             @mouseenter="$store.toast.pause(t.id)"
                             @mouseleave="$store.toast.resume(t.id)"
                             class="pointer-events-auto flex w-full items-start gap-3 rounded-lg border bg-surface-lowest p-4 shadow-lg sm:w-80"
                             :class="{
                                'border-success-border bg-success-bg': t.type === 'success',
                                'border-error-border bg-error-bg': t.type === 'error',
                                'border-warning-border bg-warning-bg': t.type === 'warning',
                                'border-info-border bg-info-bg': t.type === 'info',
                            }">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success-bg text-label-md text-success-text"
                                  :class="{
                                    'bg-success-bg text-success-text': t.type === 'success',
                                    'bg-error-bg text-error-text': t.type === 'error',
                                    'bg-warning-bg text-warning-text': t.type === 'warning',
                                    'bg-info-bg text-info-text': t.type === 'info',
                                  }"
                                  x-text="t.icon"></span>
                            <p class="min-w-0 flex-1 text-body-sm text-text-strong" x-text="t.message"></p>
                            {{-- A message that says itself is the common case, but not
                                 the only one: a failure worth reading twice, or one the
                                 reader wants to keep while they go and do something
                                 about it. The clock is stopped by `dismiss`, so
                                 pressing this leaves no timer behind to fire against
                                 an id that is already gone. --}}
                            <button type="button"
                                    class="-mr-1 -mt-1 ml-auto flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-text-subtle transition hover:bg-canvas hover:text-text-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                    @click="$store.toast.dismiss(t.id)"
                                    :aria-label="'Tutup notifikasi: ' + t.message">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Unsaved changes. Its own island rather than a wrapper around
                     the page, because the links it has to intercept — the sidebar,
                     the breadcrumb, the form's own back button — sit outside any
                     form, and the listeners belong to the document, not to one.
                     The warning it raises is the shared dialog, so there is no
                     dialog markup here to fall out of step with the script. -->
                <div x-data="unsavedChanges"></div>

                {{-- Owner PIN. A template rather than markup here, because the
                     body only exists while the dialog is open: `notify.modal`
                     walks the subtree on open and tears it down on close, so the
                     listener and the scope are rebuilt per attempt instead of
                     stacking up over a shift's worth of reprints.

                     Kept in the layout for the same reason the toast container is
                     kept in the layout, and not in a page: the request is
                     identical wherever it comes from, and one template that is
                     always present is the only arrangement under which a caller
                     can use it without first checking that its own page ships
                     one. `window.pin` opens it, and the scope inside is only ever
                     read by that helper. --}}
                <template id="pin-dialog">
                    <form data-pin-form x-data="pinDialogForm" class="space-y-1" novalidate @submit.prevent>
                        <label for="pin-dialog-input" class="block text-label-md text-text-muted">
                            PIN Owner
                        </label>
                        <input id="pin-dialog-input" x-ref="input" x-model="pin" @input="onInput($event)"
                               type="password" inputmode="numeric" maxlength="6" autocomplete="off"
                               class="input-base text-center font-mono text-label-lg tracking-[0.5em]"
                               placeholder="••••••" required>
                        <p x-show="error !== ''" x-cloak x-text="error"
                           class="mt-1.5 text-label-sm text-error-text" role="alert"></p>
                        <p class="mt-1 text-label-sm text-text-subtle">
                            Pin ini hanya berlaku untuk aksi ini, selama 5 menit.
                        </p>
                    </form>
                </template>

                <!-- Page Content -->
                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        {!! $slot !!}
                    </div>
                </main>

                <footer class="px-4 pb-6 text-label-sm text-text-subtle sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl text-center">
                        Semi-WMS &amp; POS Konsinyasi Hot Wheels · v0.1 (UI Blueprint)
                    </div>
                </footer>
            </div>
        </div>
    </body>
</html>