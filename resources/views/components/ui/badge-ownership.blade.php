@props(['type' => 'PRIBADI', 'consignor' => null])

@php
    $config = match ($type) {
        'TITIP' => [
            'classes' => 'bg-titip-bg text-titip-text border-titip-border',
            'label' => 'TITIP' . ($consignor ? " · {$consignor}" : ''),
            'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
        ],
        'KARANTINA' => [
            'classes' => 'bg-karantina-bg text-karantina-text border-karantina-border',
            'label' => 'KARANTINA',
            'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        ],
        default => [
            'classes' => 'bg-pribadi-bg text-pribadi-text border-pribadi-border',
            'label' => 'PRIBADI',
            'icon' => 'M5 13l4 4L19 7',
        ],
    };
@endphp

<span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-label-md {{ $config['classes'] }}">
    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $config['icon'] }}" />
    </svg>
    {{ $config['label'] }}
</span>