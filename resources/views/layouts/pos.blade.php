<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    @include('layouts.partials.head')
    <body class="h-full bg-canvas font-sans text-text-strong antialiased">
        <div class="flex min-h-full flex-col" x-data>
            @include('layouts.partials.pos-header')

            @include('layouts.partials.overlays')

            <main class="flex-1 px-3 py-4 sm:px-4 sm:py-5 lg:px-6 lg:py-6">
                <div class="mx-auto max-w-7xl">
                    {!! $slot !!}
                </div>
            </main>

            <footer class="px-3 pb-5 text-label-sm text-text-subtle sm:px-4 sm:pb-6 lg:px-6">
                <div class="mx-auto max-w-7xl text-center">
                    Semi-WMS &amp; POS Konsinyasi Hot Wheels · v0.1 (UI Blueprint)
                </div>
            </footer>
        </div>
    </body>
</html>
