@props(['label' => '', 'name' => '', 'required' => false, 'hint' => ''])

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-label-md text-text-muted">
            {{ $label }}
            @if ($required)
                <span class="text-error-text">*</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint)
        <p class="mt-1 text-label-sm text-text-subtle">{{ $hint }}</p>
    @endif
</div>