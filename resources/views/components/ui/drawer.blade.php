@props(['title' => '', 'subtitle' => '', 'position' => 'right', 'trigger' => null, 'panel' => null])

@php
    $enterFrom = $position === 'left' ? '-translate-x-full' : 'translate-x-full';
@endphp

<div x-data="{ open: false }">
    {{ $trigger }}

    <div x-show="open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-text-strong/40" @click="open = false"></div>
        <div class="{{ $position === 'left' ? 'absolute inset-y-0 left-0' : 'absolute inset-y-0 right-0' }} flex w-full max-w-lg flex-col bg-surface-lowest shadow-xl"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="{{ $enterFrom }}"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="{{ $enterFrom }}">
            <div class="flex shrink-0 items-start justify-between gap-3 border-b border-border-subtle px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <h3 class="text-headline-sm text-text-strong">{{ $title }}</h3>
                    @if ($subtitle)
                        <p class="mt-0.5 text-label-sm text-text-muted">{{ $subtitle }}</p>
                    @endif
                </div>
                <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-text-subtle transition hover:bg-canvas hover:text-text-strong" @click="open = false" aria-label="Tutup">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto overscroll-contain px-4 py-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:px-6">
                {{ $panel }}
            </div>
        </div>
    </div>
</div>