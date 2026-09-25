@props(['title' => '', 'actions' => null, 'pad' => true])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title)
        <div class="flex items-center justify-between border-b border-border-subtle px-6 py-4">
            <h2 class="text-headline-sm text-text-strong">{{ $title }}</h2>
            @if ($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
    @endif
    <div @class(['p-6' => $pad])>
        {{ $slot }}
    </div>
</div>