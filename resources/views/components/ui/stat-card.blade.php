@use('App\Support\Format')

@props(['label' => '', 'value' => '', 'delta' => null, 'deltaTone' => 'success', 'icon' => null])

@php
    /*
     * A bare number gets its thousand separators. Anything already composed --
     * "Rp4.215.000", "Rp12,48jt", "OK", someone's name -- is left exactly as the
     * caller wrote it, because none of those are numbers in any arithmetic
     * sense, only in the loose sense of "reads like a figure".
     *
     * This lived at the call sites instead, which meant thirteen chances to
     * forget, and the counts were the ones that came out as 1234.
     *
     * "Already grouped" is spelled out rather than left to `is_numeric()`, which
     * would happily call "1.234" a number and then round it down to 1. A caller
     * who formats before passing the value in gets it back untouched.
     */
    $grouped = is_int($value) || is_float($value)
        || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    $display = $grouped ? Format::number((string) $value) : $value;
@endphp

<div class="card card-pad">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-label-sm uppercase tracking-wide text-text-muted">{{ $label }}</p>
            <p class="mt-1.5 text-currency-display text-text-strong tabular-nums">{{ $display }}</p>
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