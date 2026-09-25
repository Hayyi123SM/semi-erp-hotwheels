<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Semi ERP Hot Wheels') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body class="h-full bg-canvas font-sans text-text-strong antialiased">
        <div class="min-h-full" x-data="{ sidebarOpen: false }">
            @include('layouts.sidebar')

            <div class="lg:pl-64">
                @include('layouts.topbar')

                <!-- Global toasts -->
                <div class="fixed top-4 right-4 z-[60] flex flex-col gap-2" x-data>
                    <template x-for="t in $store.toast.items" :key="t.id">
                        <div x-show="t" x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-x-4"
                             x-transition:enter-end="opacity-100 translate-x-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                             class="pointer-events-auto flex w-80 items-start gap-3 rounded-lg border bg-surface-lowest p-4 shadow-lg"
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
                            <p class="text-body-sm text-text-strong" x-text="t.message"></p>
                        </div>
                    </template>
                </div>

                <!-- Page Content -->
                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        {!! $slot !!}
                    </div>
                </main>

                <footer class="px-6 pb-6 text-label-sm text-text-subtle lg:px-8">
                    <div class="text-center">
                        Semi-WMS &amp; POS Konsinyasi Hot Wheels · v0.1 (UI Blueprint)
                    </div>
                </footer>
            </div>
        </div>
    </body>
</html>