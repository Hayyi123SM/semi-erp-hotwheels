@props(['title' => '', 'description' => '', 'size' => 'md', 'closeOnOverlay' => true])

@php
    $sizes = [
        'sm' => 'max-w-md',
        'md' => 'max-w-lg',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
    ];
@endphp

<div x-data="{ open: false }">
    {{ $trigger }}

    <div x-show="open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true"
         @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-text-strong/40" @if ($closeOnOverlay) @click="open = false" @endif></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full {{ $sizes[$size] }} transform transition duration-200 ease-out"
                 x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95">
                <div class="card shadow-xl">
                    <div class="flex items-start justify-between border-b border-border-subtle px-6 py-4">
                        <div>
                            <h3 class="text-headline-sm text-text-strong">{{ $title }}</h3>
                            @if ($description)
                                <p class="mt-0.5 text-body-sm text-text-muted">{{ $description }}</p>
                            @endif
                        </div>
                        <button type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-lg text-text-subtle transition hover:bg-canvas hover:text-text-strong"
                                @click="open = false" aria-label="Tutup">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="px-6 py-5">
                        {{ $panel }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>