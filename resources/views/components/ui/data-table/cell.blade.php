@use('App\Support\Format')

@props(['column', 'row'])

@php
    $value = $column->resolvedValue($row);
    $delegate = $column->resolvedComponent();

    /*
     * A tooltip is only worth rendering when the value *is* the display: a
     * formatted one would put "150000" on a cell reading Rp150.000, which is
     * worse than no tooltip. Free text qualifies, and so does a custom render
     * whose value is the long field, but only once it is long enough that the
     * second line will really be cut — otherwise the hover would just expose an
     * internal enum code.
     */
    $showTooltip = $column->format === null
        && is_string($value)
        && mb_strlen($value) > 24;

    $tooltip = $showTooltip ? 'title="'.e($value).'"' : '';
@endphp

@if ($delegate)
    <x-dynamic-component :component="$delegate" :row="$row" :column="$column" :value="$value" :data="$column->componentData" />
@elseif ($column->render)
    <span class="cell-text" {!! $tooltip !!}>{!! ($column->render)($row, $column) !!}</span>
@elseif ($column->format === 'status')
    <x-ui.badge-status :type="Format::statusType($value)">{{ Format::statusLabel($value) }}</x-ui.badge-status>
@elseif ($column->format === 'ownership')
    <x-ui.badge-ownership :type="Format::ownershipType($value)" :consignor="data_get($row, 'owner_code')" />
@elseif ($column->format === 'rupiah')
    <span class="cell-text"><span class="font-semibold text-text-strong">{{ Format::rupiah($value) }}</span></span>
@elseif ($column->format === 'number')
    <span class="cell-text text-text-strong">{{ Format::number($value) }}</span>
@elseif ($column->format === 'date')
    <span class="cell-text text-text-muted">{{ Format::date($value) }}</span>
@elseif ($column->format === 'datetime')
    <span class="cell-text text-text-muted">{{ Format::datetime($value) }}</span>
@elseif ($column->format === 'enum')
    <span class="cell-text text-text-muted">{{ Format::enum($value) }}</span>
@elseif ($value === null || $value === '')
    <span class="text-text-subtle">{{ Format::EMPTY }}</span>
@else
    <span class="cell-text text-text-strong" {!! $tooltip !!}>{{ Format::text(is_scalar($value) ? (string) $value : null) }}</span>
@endif
