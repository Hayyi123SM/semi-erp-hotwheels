@use('App\Support\Format')

@props(['table', 'paginator'])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $from = max(1, $current - 2);
    $to = min($last, $current + 2);
@endphp

<div class="flex flex-col gap-3 border-t border-border-subtle bg-surface-lowest px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
    <div class="flex flex-wrap items-center gap-4">
        @if (count($table->perPageOptions()) > 1)
            {{--
    Its own form, because it is re-rendered on every refresh and the toolbar's
    form is deliberately not part of that answer -- overwriting the field a
    reader is halfway through typing in would be worse than a stale page size.
    Carrying the query along keeps the answer the reader asked for.
--}}
<form method="GET" action="{{ request()->url() }}" class="flex items-center gap-2"
              x-data="dataTableForm" x-on:change="apply()">
                @if ($table->search())
                    <input type="hidden" name="q" value="{{ $table->search() }}">
                @endif
                @if ($table->sort())
                    <input type="hidden" name="sort" value="{{ $table->sort() }}">
                @endif
                @if ($table->direction() === 'desc')
                    <input type="hidden" name="direction" value="desc">
                @endif

                <label class="flex items-center gap-2 text-label-sm text-text-subtle">
                    Tampilkan
                    <select name="per_page" class="select-base h-9 w-auto py-0 pr-8 text-label-md">
                        @foreach ($table->perPageOptions() as $option)
                            <option value="{{ $option }}" @selected($option === $table->pageSize())>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endif

        <p class="text-label-sm text-text-subtle">
            Menampilkan {{ Format::number($paginator->firstItem() ?? 0) }}–{{ Format::number($paginator->lastItem() ?? 0) }}
            dari {{ Format::number($paginator->total()) }} data
        </p>
    </div>

    {{-- Wraps rather than overflows once the page window outgrows a 320px screen. --}}
    <nav class="flex flex-wrap items-center justify-center gap-1" aria-label="Navigasi halaman">
        @if ($paginator->onFirstPage())
            <span class="btn-ghost h-9 w-9 px-0 opacity-40" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn-ghost h-9 w-9 px-0" rel="prev" aria-label="Halaman sebelumnya">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
            </a>
        @endif

        @if ($from > 1)
            <a href="{{ $paginator->url(1) }}" class="page-link">1</a>
            @if ($from > 2)
                <span class="page-link" aria-hidden="true">&hellip;</span>
            @endif
        @endif

        @for ($page = $from; $page <= $to; $page++)
            @if ($page === $current)
                <span class="page-link page-link-active" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="page-link">{{ $page }}</a>
            @endif
        @endfor

        @if ($to < $last)
            @if ($to < $last - 1)
                <span class="page-link" aria-hidden="true">&hellip;</span>
            @endif
            <a href="{{ $paginator->url($last) }}" class="page-link">{{ $last }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn-ghost h-9 w-9 px-0" rel="next" aria-label="Halaman berikutnya">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
            </a>
        @else
            <span class="btn-ghost h-9 w-9 px-0 opacity-40" aria-hidden="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
            </span>
        @endif
    </nav>
</div>
