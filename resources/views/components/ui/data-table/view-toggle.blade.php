{{--
    Table <-> grid switch, matching the x-ui.segmented look.

    It dispatches instead of assigning because the toolbar and this control each
    carry their own x-data, and an inherited property is awkward to write to
    across that gap. The event bubbles straight to the data table root.

    Hidden below md: a phone only ever has the card list, so the choice would
    have nothing to switch between.
--}}
@php
    $options = [
        'table' => 'Tabel',
        'grid' => 'Grid',
    ];
    $icons = [
        'table' => 'M3 10h18M3 4h18v16H3zM9 4v16',
        'grid' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
    ];
@endphp

<div class="hidden h-11 items-center gap-0.5 rounded-lg bg-canvas p-1 md:inline-flex" role="group" aria-label="Tampilan data">
    @foreach ($options as $value => $label)
        <button type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-md px-3 text-label-md transition"
                :class="view === @js($value) ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'"
                :aria-pressed="(view === @js($value)).toString()"
                @click="$dispatch('table-view', @js($value))">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="{{ $icons[$value] }}"/>
            </svg>
            {{ $label }}
        </button>
    @endforeach
</div>
