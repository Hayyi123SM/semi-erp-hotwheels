@props([
    'row' => null,
    'column' => null,
    'value' => null,
    'data' => ['actions' => [], 'when' => null, 'empty' => null],
])

@php
    $actions = collect($data['actions'] ?? [])
        ->filter(function (array $action) use ($row) {
            $when = $action['when'] ?? true;

            return $when instanceof Closure ? (bool) $when($row) : (bool) $when;
        })
        ->values();

    $key = $row?->getKey();
@endphp

<div class="flex flex-wrap justify-end gap-1">
    @forelse ($actions as $index => $action)
        @php
            $method = strtoupper($action['method'] ?? 'GET');
            $url = route($action['route'], [$key]);
            $variant = $action['variant'] ?? 'ghost';
            $label = $action['label'] ?? 'Aksi';
            $classes = match ($variant) {
                'primary' => 'btn-ghost h-9 px-3 text-primary',
                'danger' => 'btn-ghost h-9 px-3 text-error-text',
                default => 'btn-ghost h-9 px-3',
            };
        @endphp

        @if (isset($action['confirm']))
            {{-- Handled by the one shared dialog mounted by x-ui.data-table,
                 instead of nesting a full dialog inside every row.
                 Controllers disagree on the key: four send `confirm_text`,
                 ProductController sends `text`, so accept either. --}}
            <button type="button"
                    class="{{ $classes }}"
                    @click="$dispatch('row-confirm', {
                        title: @js($action['confirm']['title'] ?? ('Hapus '.$label.'?')),
                        description: @js($action['confirm']['description'] ?? ''),
                        action: @js($url),
                        method: @js($method),
                        confirmText: @js($action['confirm']['confirm_text'] ?? $action['confirm']['text'] ?? 'Ya, lanjutkan'),
                    })">{{ $label }}</button>
        @elseif ($method === 'GET')
            <a href="{{ $url }}" class="{{ $classes }}">{{ $label }}</a>
        @else
            <form method="POST" action="{{ $url }}" class="m-0">
                @csrf
                @unless ($method === 'POST')
                    <input type="hidden" name="_method" value="{{ $method }}">
                @endunless
                <button type="submit" class="{{ $classes }}">{{ $label }}</button>
            </form>
        @endif
    @empty
        <span class="px-3 py-2 text-label-sm text-text-subtle">{{ $data['empty'] ?? 'Lihat saja' }}</span>
    @endforelse
</div>
