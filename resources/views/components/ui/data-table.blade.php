@props(['table'])

@php
    use App\Support\DataTable\Fragment;
    use App\Support\DataTable\ViewPreference;

    $columns = $table->visibleColumns();
    $rows = $table->rows();
@endphp

@if (Fragment::requested(request()))
    {{--
        An in-place refresh. The page around this component still renders, and
        the client picks the two marked regions out of it; see
        data-table/fragment.blade.php.
    --}}
    <x-ui.data-table.fragment :table="$table" :rows="$rows" :columns="$columns"
                              :summary="$summary ?? null" :filters="$filters ?? null" />
@else
    {{--
        The two branches below are shown or hidden by CSS on `data-table-view`, not
        by Alpine: only this element needs Alpine, to own the preference the
        view-toggle dispatches into.

        `dataTable` is the table view preference plus the in-place refresh, in one
        scope, because a single element can only carry one x-data.
    --}}
    <div x-data="dataTable(@js(ViewPreference::storageKey()))"
         @table-view="setView($event.detail)"
         @submit="onSubmit($event)"
         @click="onClick($event)"
         {{ $attributes->merge(['class' => 'card overflow-hidden']) }}>
        <x-ui.data-table.toolbar :table="$table">
            @isset($summary)
                <x-slot:summary>{{ $summary }}</x-slot:summary>
            @endisset
            @isset($filters)
                <x-slot:filters>{{ $filters }}</x-slot:filters>
            @endisset
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
        </x-ui.data-table.toolbar>

        {{-- Outside the swapped region, so a failure never erases its own cause. --}}
        <template x-if="error">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-error-border bg-error-bg px-4 py-3 sm:px-6"
                 role="alert">
                <p class="flex items-center gap-2 text-body-sm text-error-text">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <span x-text="error"></span>
                </p>
                <button type="button" class="btn-secondary h-9" @click="retry()">Coba Lagi</button>
            </div>
        </template>

        <div class="relative">
            <div x-show="busy" x-cloak class="absolute inset-x-0 top-0 z-10 h-0.5 overflow-hidden"
                 role="progressbar" aria-label="Memuat data">
                <div class="h-full w-1/3 animate-pulse bg-primary"></div>
            </div>

            {{--
                Kept in step with the toolbar by the client, and dimmed rather
                than emptied while it works, so the rows do not collapse and
                expand under the reader on every keystroke.
            --}}
            <div data-results :aria-busy="busy ? 'true' : 'false'"
                 :class="busy ? 'pointer-events-none opacity-60 transition-opacity' : ''">
                <x-ui.data-table.results :table="$table" :rows="$rows" :columns="$columns" />
            </div>
        </div>

        {{-- One dialog for every row action on this table. --}}
        <x-ui.data-table.row-confirm :table="$table" />
    </div>
@endif
