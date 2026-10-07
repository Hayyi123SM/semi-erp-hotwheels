@props(['table', 'spaced' => false])

@php
    $sortable = $table->sortableColumns();

    $sorted = $sortable->first(fn ($column) => $table->isSorted($column));
@endphp

{{--
    Below md the table head is gone, so without this the sort controls would
    silently disappear on phones. Plain links keep it working without JavaScript,
    and it reuses sortUrl() so the direction toggle logic stays in one place.

    The caller decides the top margin: this sits alone in the filter strip on a
    table with no page specific filters, and under them when there are some.
--}}
@if ($sortable->isNotEmpty())
    <div @class([
        'flex items-center gap-2 overflow-x-auto md:hidden',
        'mt-3' => $spaced,
    ])>
        <span class="shrink-0 text-label-sm text-text-subtle">Urutkan</span>

        @foreach ($sortable as $column)
            <a href="{{ $table->sortUrl($column) }}"
               @class([
                   'shrink-0 rounded-full border px-3 py-1.5 text-label-md font-medium transition focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                   'border-primary bg-primary-soft text-primary' => $table->isSorted($column),
                   'border-border-subtle bg-surface-lowest text-text-muted hover:bg-canvas hover:text-text-strong' => ! $table->isSorted($column),
               ])
               @if ($table->isSorted($column)) aria-current="true" @endif>
                {{ $column->label }}
                @if ($table->isSorted($column))
                    <svg class="ml-1 inline h-3 w-3 {{ $table->direction() === 'asc' ? 'rotate-180' : '' }}"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 12l-7 7-7-7M12 19V5" />
                    </svg>
                    <span class="sr-only">diurutkan {{ $table->direction() === 'asc' ? 'menaik' : 'menurun' }}</span>
                @endif
            </a>
        @endforeach

        @if ($sorted)
            <a href="{{ $table->sortUrl($sorted) }}"
               class="btn-icon h-8 w-8 shrink-0"
               title="Balik arah pengurutan"
               aria-label="Balik arah pengurutan">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M7 4v16M7 4L3 8M7 4l4 4M17 20V4M17 20l-4-4M17 20l4-4" />
                </svg>
            </a>
        @endif
    </div>
@endif
