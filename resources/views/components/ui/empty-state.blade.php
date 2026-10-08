@props(['title' => 'Data tidak ditemukan', 'description' => 'Belum ada data yang cocok dengan filter saat ini.'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-text-subtle">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9" />
        </svg>
    </div>
    <h3 class="mt-4 text-headline-sm text-text-strong">{{ $title }}</h3>
    <p class="mt-1 max-w-sm text-body-sm text-text-muted">{{ $description }}</p>
    {{ $slot }}
</div>