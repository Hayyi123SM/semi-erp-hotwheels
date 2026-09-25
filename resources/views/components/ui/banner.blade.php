@props(['tone' => 'warning', 'icon' => null])

@php
    $tones = [
        'warning' => 'border-warning-border bg-warning-bg text-warning-text',
        'error' => 'border-error-border bg-error-bg text-error-text',
        'success' => 'border-success-border bg-success-bg text-success-text',
        'info' => 'border-info-border bg-info-bg text-info-text',
    ];
    $icons = [
        'warning' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        'error' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'success' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'info' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    ];
@endphp

<div class="flex items-start gap-3 rounded-lg border px-4 py-3 {{ $tones[$tone] }}">
    <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $icon ?? $icons[$tone] }}" />
    </svg>
    <div class="text-body-sm">{{ $slot }}</div>
</div>