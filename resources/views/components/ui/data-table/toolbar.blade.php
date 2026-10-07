@props(['table'])

{{--
    Two rows, one form.

    The first row is the part every list shares: search on the left, the fixed
    set of controls on the right. The second row is whatever the page adds for
    itself. Keeping them apart means the default row can stay identical on
    every page, and a page specific filter is never mistaken for a table
    control.

    One form spans both, so search and filters submit together. The padding
    lives on the rows rather than on the form, which is what lets the second
    row tint all the way to the card edges while both rows still share one
    left edge.

    `data-table-form` is how the in-place refresh tells this form apart from the
    confirm dialog's, which is also inside the table but is not a list query.

    The listeners sit on the form rather than on the controls. The second row is
    re-rendered on every refresh and arrives without a form around it, so a
    handler there that went looking for one would fail the first time a reader
    touched a filter. A page can also add a control of its own without this
    component learning about it.
--}}
<form method="GET" action="{{ request()->url() }}" data-table-form
      x-data="dataTableForm" x-on:change="apply()"
      class="border-b border-border-subtle bg-surface-lowest">
    @if ($table->sort())
        <input type="hidden" name="sort" value="{{ $table->sort() }}">
    @endif
    @if ($table->direction() === 'desc')
        <input type="hidden" name="direction" value="desc">
    @endif
    @if ($table->pageSize() !== $table->perPageOptions()[0])
        <input type="hidden" name="per_page" value="{{ $table->pageSize() }}">
    @endif

    <div class="flex flex-col gap-3 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
        @if ($table->isSearchable())
            <div class="relative w-full sm:max-w-xs">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <input type="search" name="q" value="{{ $table->search() }}"
                       placeholder="{{ $table->placeholder() }}" autocomplete="off" class="input-base pl-10"
                       aria-label="{{ $table->placeholder() }}"
                       x-on:input="search($event.target.value)">
            </div>
        @else
            <div class="hidden lg:block"></div>
        @endif

        {{-- Every control in here is h-11, so the row has one height. --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $table->resetUrl() }}" class="btn-icon" title="Reset filter &amp; muat ulang" aria-label="Reset filter dan muat ulang">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 4v6h6M20 20v-6h-6M5.5 15a7 7 0 0011.95 2.95L20 15M3.5 9l2.55-2.95A7 7 0 0118 9" />
                </svg>
            </a>

            @if ($table->exportUrl())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="btn-secondary" @click="open = !open" :aria-expanded="open.toString()">
                        Ekspor
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 9l-7 7-7-7M12 16V4" />
                        </svg>
                    </button>
                    <div x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-36 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-border-subtle bg-surface-lowest py-1 shadow-lg">
                        {{-- A download is a navigation the refresh must not swallow. --}}
                        <a href="{{ $table->exportUrlFor('csv') }}" data-full-navigation class="block px-4 py-2 text-body-sm text-text-muted transition hover:bg-canvas hover:text-text-strong">CSV</a>
                        <a href="{{ $table->exportUrlFor('xlsx') }}" data-full-navigation class="block px-4 py-2 text-body-sm text-text-muted transition hover:bg-canvas hover:text-text-strong">Excel (XLSX)</a>
                    </div>
                </div>
            @endif

            @unless ($table->isEmpty())
                {{-- Nothing to switch between while the result set is empty. --}}
                <x-ui.data-table.view-toggle />
            @endunless

            @isset($actions)
                {{ $actions }}
            @endisset

            @if ($table->createUrl())
                <a href="{{ $table->createUrl() }}" class="btn-primary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 4v16m8-8H4" />
                    </svg>
                    {{ $table->createLabel() }}
                </a>
            @endif
        </div>
    </div>

    @php
        // A slot a page did not pass is an undefined variable, not null, so it
        // has to be coalesced before anything looks at it.
        $hasStrip = $table->hasStrip(filled($summary ?? null), filled($filters ?? null));
    @endphp

    {{--
        The slot is rendered even when it is empty, because an in-place refresh
        has to be able to add the row and take it away again: lifting the last
        filter off a table with no other reason for a second row should leave no
        tinted band behind. An empty, hidden slot costs nothing — the border on
        the form is the card's bottom edge either way.
    --}}
    <div data-strip-slot @if (! $hasStrip) hidden @endif>
        @if ($hasStrip)
            <x-ui.data-table.strip :table="$table" :summary="$summary ?? null" :filters="$filters ?? null" />
        @endif
    </div>
</form>
