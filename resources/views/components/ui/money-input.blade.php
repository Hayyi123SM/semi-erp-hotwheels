@props([
    'name' => null,
    'value' => null,
    // `money` is a grouped amount; `rate` is a percentage, which takes a comma
    // for its decimals and no grouping at all.
    'mode' => 'money',
    'id' => null,
    // The dotted path `old()` and the error bag both use, for a field whose HTML
    // name is bracketed -- `defaults[scheme_rate]` is not a key `Arr::get` can
    // walk, so the two have to be told apart.
    'key' => null,
    'placeholder' => null,
    'required' => false,
    'hint' => null,
])

{{--
    An amount field, grouped as it is typed.

    `x-ui.field` renders its label with `for="{{ $name }}"`, which quietly does
    nothing when the input it points at has no id of its own. Deriving the id
    from the name here is what keeps the label attached, and it is why this is a
    component rather than a snippet pasted into five forms.

    The value rendered is the raw one the server sent, and it is the field's own
    `name` that ships. The mask adds the grouping, the hidden mirror and the
    plain digits in the browser -- so a form whose scripts never arrive submits
    `65000` and works exactly as it did, and the grouping is a courtesy to the
    reader rather than a step the request depends on.

    A rate is put through `Format::rate()` here rather than by its caller, and
    that is the point: it covers the value from the database (`20.00`) and the
    value flashed back by a failed submit (`12.5`) with one call, instead of
    leaving each caller to remember. Both arrive with a decimal point where the
    mask expects a comma, and a mask that reads digits and a comma would turn
    `20.00` into `2000` -- a hundred times the share, in the one field that has
    to be right about somebody's money.
--}}
@php
    $shown = old($key ??= $name, $value);
    $shown = $mode === 'rate' ? \App\Support\Format::rate($shown) : $shown;
@endphp

<input
    type="text"
    inputmode="numeric"
    autocomplete="off"
    x-money="{{ $mode }}"
    @if ($id ??= $name)
        id="{{ $id }}"
    @endif
    @if ($name)
        name="{{ $name }}"
    @endif
    value="{{ $shown }}"
    @if ($placeholder)
        placeholder="{{ $placeholder }}"
    @endif
    @if ($required)
        required
    @endif
    {{ $attributes->merge(['class' => 'input-base tabular-nums '.($errors->has($key) ? 'border-error-border' : '')]) }}
>
