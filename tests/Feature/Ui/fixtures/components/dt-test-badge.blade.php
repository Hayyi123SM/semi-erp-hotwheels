@props(['row' => null, 'column' => null, 'value' => null, 'data' => []])

@php($type = $data['type'] ?? 'info')

<span class="fixture-badge bg-{{ $type }}-bg text-{{ $type }}-text" data-key="{{ $column->key }}">
    {{ $data['label'] ?? 'Tanpa label' }}: {{ $value }}
</span>
