@props(['label' => '', 'value' => '', 'delta' => null, 'deltaTone' => 'success', 'icon' => null])

<div class="card card-pad">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-label-sm uppercase tracking-wide text-text-muted">{{ $label }}</p>
            <p class="mt-1.5 text-currency-display text-text-strong tabular-nums">{{ $value }}</p>
            @if ($delta)
                <p class="mt-1 inline-flex items-center gap-1 text-label-md text-{{ $deltaTone }}-text">
                    {{ $delta }}
                </p>
            @endif
        </div>
        @if ($icon)
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="{{ $icon }}" />
                </svg>
            </div>
        @endif
    </div>
</div>