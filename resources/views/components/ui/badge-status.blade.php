@props(['type' => 'success', 'dot' => false])

@php
    $config = [
        'success' => ['bg-success-bg text-success-text border-success-border', 'M5 13l4 4L19 7'],
        'error' => ['bg-error-bg text-error-text border-error-border', 'M6 18L18 6M6 6l12 12'],
        'warning' => ['bg-warning-bg text-warning-text border-warning-border', 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'],
        'info' => ['bg-info-bg text-info-text border-info-border', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        // Netral: untuk yang belum deserves warna, bukan untuk yang netral. Kode
        // aksi yang tidak dikenal memakainya, supaya baris itu tidak terlihat
        // seperti peringatan. Ikonnya titik, bukan tanda seru: tidak ada yang
        // perlu diabaikan di situ.
        'neutral' => ['bg-canvas text-text-muted border-border-subtle', 'M12 12h.01'],
    ][$type] ?? $config['info'];

    [$classes, $icon] = $config;
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-label-md {{ $classes }}">
    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $icon }}" />
    </svg>
    {{ $slot }}
</span>