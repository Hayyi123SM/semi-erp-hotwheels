@use('App\Support\Format')

@php
    $ownership = Format::ownershipType($lot->owner_type);
    $isOwner = auth()->user()?->isOwner() ?? false;
    $facts = [
        'Qty di rak' => Format::number($lot->qty_on_hand),
        'Rak' => $lot->rack?->code ?? Format::EMPTY,
        'Kondisi' => trim(($lot->card_condition?->label() ?? Format::EMPTY).' / '.($lot->blister_condition?->label() ?? '')),
        'Harga jual' => Format::rupiah($lot->list_price),
        'Masuk' => Format::date($lot->created_at),
    ];

    if ($isOwner) {
        $facts['HPP'] = $lot->cost_price === null ? Format::EMPTY : Format::rupiah($lot->cost_price);
        $facts['Penitip'] = $lot->consignor?->name ?? Format::EMPTY;
    }
@endphp

<x-ui.page-header
    title="Kartu Stok"
    :subtitle="$lot->sku.($lot->product?->name ? ' · '.$lot->product->name : '')"
    :crumbs="[
        ['label' => 'Inventory', 'href' => route('inventory.live-stock')],
        ['label' => 'Live Stock', 'href' => route('inventory.live-stock')],
        'Kartu Stok',
    ]"
>
    <x-slot:actions>
        <a href="{{ route('inventory.live-stock') }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
            Kembali ke Live Stock
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-ui.badge-ownership :type="$ownership" :consignor="$lot->owner_code" />

            <x-ui.badge-status :type="Format::statusType($lot->status)">{{ Format::statusLabel($lot->status) }}</x-ui.badge-status>

            <dl class="flex flex-wrap gap-x-8 gap-y-4">
                @foreach ($facts as $label => $value)
                    <div>
                        <dt class="text-label-sm uppercase tracking-wide text-text-muted">{{ $label }}</dt>
                        <dd class="mt-0.5 text-body-md font-medium text-text-strong tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-ui.section-card>

    <x-ui.section-card>
        <x-ui.data-table :table="$table" />
    </x-ui.section-card>
</div>
