@props(['searchPlaceholder' => 'Cari...'])

<div {{ $attributes->merge(['class' => 'mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between']) }}>
    <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
        <div class="relative w-full sm:max-w-xs">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
            </svg>
            <input type="search" placeholder="{{ $searchPlaceholder }}"
                   class="input-base pl-10" data-allow-focus>
        </div>
        @isset($filters)
            <div class="flex flex-wrap items-center gap-2">
                {{ $filters }}
            </div>
        @endisset
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>