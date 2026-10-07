@props(['table', 'row'])

@php
    $card = $table->cardColumns();
    $title = $card->get('title');
    $badges = $card->get('badge', collect());
    $price = $card->get('price', collect())->first();
    $subtitle = $card->get('subtitle', collect());
    $meta = $card->get('meta', collect());
    $footer = $card->get('footer', collect());
    $rowUrl = $table->rowUrlFor($row);
@endphp

{{--
    The mobile stand-in for a table row. It reuses x-ui.data-table.cell so a
    column renders identically in both modes, which keeps formatter, delegation
    and RBAC behaviour from drifting between breakpoints.

    `data-row-url` mirrors the row on the table above: the card is the same row
    at a different width, so it has to open the same place.
--}}
<article @class(['data-table-card', 'cursor-pointer' => $rowUrl !== null])
         @if ($rowUrl !== null) data-row-url="{{ $rowUrl }}" @endif>
    @if ($title || $price)
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                @if ($title)
                    <div class="text-body-md font-semibold text-text-strong">
                        <x-ui.data-table.cell :column="$title->first()" :row="$row" />
                    </div>
                @endif

                @foreach ($subtitle as $column)
                    <div class="mt-0.5 text-body-sm text-text-muted">
                        <x-ui.data-table.cell :column="$column" :row="$row" />
                    </div>
                @endforeach
            </div>

            @if ($price)
                <div class="shrink-0 text-right text-body-md font-semibold tabular-nums text-text-strong">
                    <x-ui.data-table.cell :column="$price" :row="$row" />
                </div>
            @endif
        </div>
    @endif

    @if ($badges->isNotEmpty())
        <div class="flex flex-wrap items-center gap-1.5">
            @foreach ($badges as $column)
                <x-ui.data-table.cell :column="$column" :row="$row" />
            @endforeach
        </div>
    @endif

    @if ($meta->isNotEmpty())
        {{--
            Two columns on a 320px screen leaves ~130px per value, which is where
            a hard truncate used to cut a name off mid-word. The label keeps its
            single line; the value is left to wrap, and cell.blade.php clamps
            whatever still runs long.
        --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
            @foreach ($meta as $column)
                @php
                    $metaValue = $column->resolvedValue($row);
                    $metaTooltip = is_string($metaValue) && mb_strlen($metaValue) > 24 ? 'title="'.e($metaValue).'"' : '';
                @endphp
                <div class="min-w-0">
                    <dt class="truncate text-label-sm text-text-subtle">{{ $column->label }}</dt>
                    <dd class="text-body-sm text-text-strong" {!! $metaTooltip !!}>
                        <x-ui.data-table.cell :column="$column" :row="$row" />
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($footer->isNotEmpty())
        <div class="mt-0.5 flex items-center justify-end gap-1 border-t border-border-subtle pt-2.5">
            @foreach ($footer as $column)
                <x-ui.data-table.cell :column="$column" :row="$row" />
            @endforeach
        </div>
    @endif
</article>
