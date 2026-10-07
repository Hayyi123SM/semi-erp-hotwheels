@props(['table', 'summary' => null, 'filters' => null])

{{--
    The second row of the toolbar: what the reader has actually applied, next to
    the controls that apply it.

    Rendered from two places — the toolbar on a full page load, and the fragment
    when the table is refreshed in place — because the chips state the current
    query, which means they have to be re-rendered with the rows or they would
    go on describing a result set that is no longer on screen. Sharing the
    markup is the only way those two cannot drift apart.

    Whether this row appears at all is decided by the caller, not here: see
    DataTable::hasStrip().
--}}
@php
    $activeFilters = $table->activeFilterTokens();
    $hasTier = filled($summary ?? null) || filled($filters ?? null) || $activeFilters !== [];
@endphp

{{--
    No listeners here on purpose. This row is re-rendered on every refresh and
    reaches the browser with no form around it, so anything here that reached for
    one would fail the first time a reader touched a control. The form that owns
    these fields is up in the toolbar, and `change` bubbles to it.
--}}
<div class="filter-strip px-4 py-3 sm:px-6">
    @if ($hasTier)
        <div class="flex flex-wrap items-center gap-2">
            @if (filled($summary ?? null))
                <div class="text-label-sm text-text-subtle">{{ $summary }}</div>
            @endif

            {{--
                A dropdown whose first option reads "everything" never shows
                which value is applied, so state the applied filters here,
                before the controls that change them.
            --}}
            @foreach ($activeFilters as $token)
                <a href="{{ $token['url'] }}" class="filter-token" title="Hapus filter {{ $token['label'] }}">
                    <span class="max-w-40 truncate">{{ $token['label'] }}</span>
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12" />
                    </svg>
                    <span class="sr-only">Hapus filter {{ $token['label'] }}</span>
                </a>
            @endforeach

            {{ $filters ?? '' }}
        </div>
    @endif

    {{-- Below md the table head is gone, so sorting needs its own control. --}}
    <x-ui.data-table.sort :table="$table" :spaced="$hasTier" />
</div>
