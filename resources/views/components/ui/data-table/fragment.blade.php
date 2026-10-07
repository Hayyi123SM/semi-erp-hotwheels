@props(['table', 'rows', 'columns', 'summary' => null, 'filters' => null])

{{--
    The payload of an in-place table refresh.

    Two regions travel together, because they are two halves of one answer: the
    chips in the toolbar state the current query, and the rows are that query's
    result. Refreshing the rows alone would leave the toolbar describing a result
    set that is no longer on screen.

    Marked rather than bare so the client can pick both apart out of whatever the
    page wrapped them in. The strip block also carries whether the row exists at
    all, so lifting the last filter off a table with no other reason for a second
    row removes the row instead of leaving a tinted band behind.
--}}
@php
    $hasStrip = $table->hasStrip(filled($summary ?? null), filled($filters ?? null));
@endphp

<div data-fragment="strip" data-present="{{ $hasStrip ? '1' : '0' }}">
    @if ($hasStrip)
        <x-ui.data-table.strip :table="$table" :summary="$summary" :filters="$filters" />
    @endif
</div>

<div data-fragment="results">
    <x-ui.data-table.results :table="$table" :rows="$rows" :columns="$columns" />
</div>
