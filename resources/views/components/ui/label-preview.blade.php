@props([
    'sku' => 'OW00-HW-001',
    'model' => '97 Mazda RX-7',
    'condition' => 'Mint',
    'price' => 145000,
    'showPrice' => true,
    'showCondition' => true,
    'showRack' => true,
    'rack' => 'A-01-03',
])

<div {{ $attributes->merge(['class' => 'w-36 shrink-0 rounded-sm border border-border-strong bg-surface-lowest p-2']) }}>
    <!-- QR placeholder (thermal) -->
    <div class="mb-1.5 grid h-12 w-12 grid-cols-6 overflow-hidden rounded-[2px]" aria-hidden="true">
        @for ($i = 0; $i < 36; $i++)
            <span class="block {{ ($i % 5 === 0 || ($i + 3) % 7 === 0) ? 'bg-text-strong' : 'bg-transparent' }}"></span>
        @endfor
    </div>
    <p class="font-mono text-[9px] leading-[11px] text-text-strong">{{ $sku }}</p>
    <p class="mt-0.5 truncate text-[8px] leading-[10px] text-text-muted" title="{{ $model }}">{{ $model }}</p>
    <div class="mt-1 flex flex-wrap gap-x-1.5 gap-y-0.5 border-t border-dotted border-border-strong pt-1 text-[8px] leading-[9px] text-text-subtle">
        @if ($showCondition)
            <span>{{ $condition }}</span>
        @endif
        @if ($showRack)
            <span>{{ $rack }}</span>
        @endif
        @if ($showPrice)
            <span class="ml-auto font-semibold text-text-strong tabular-nums">{{ \App\Support\MockData::rupiah($price) }}</span>
        @endif
    </div>
</div>