@props([
    'options' => [],
    'selected' => null,
])

@php
    $selectedLabel = collect($options)->firstWhere('value', $selected)['label'] ?? null;
@endphp

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-0.5 rounded-lg bg-canvas p-1']) }}
     x-data="{ value: @js($selected) }"
     x-on:selection-change.window="if ($event.detail === null) value = null">
    @foreach ($options as $option)
        <button type="button"
                class="rounded-md px-3 py-1.5 text-label-md transition"
                :class="value === @js($option['value']) ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'"
                @click="value = @js($option['value'])">
            {{ $option['label'] }}
        </button>
    @endforeach
</div>