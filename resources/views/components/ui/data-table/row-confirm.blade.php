{{--
    The one form the table's confirmable row actions submit through.

    Row actions used to nest a full confirm dialog per confirmable action, which
    put 20 `position: fixed` overlays on a ten-row page. They now dispatch a
    `row-confirm` event, and the scope that answers it asks the shared dialog
    helper; if the answer is yes, this form posts on their behalf.

    Kept as markup rather than something the script assembles, so the CSRF token
    is one Blade rendered. Only needed when the table actually has a delegated
    row-actions column.
--}}
@props(['table'])

@php
    $hasRowActions = $table->visibleColumns()
        ->contains(fn ($column) => $column->resolvedComponent() === 'ui.row-actions');
@endphp

@if ($hasRowActions)
    {{-- `contents` so the wrapper contributes no box of its own: the row layout
         is whatever the caller already set, and this is only here to give the
         form a scope to answer from. --}}
    <div class="contents" x-data="rowConfirm">
        <form method="POST" x-ref="form" class="hidden" aria-hidden="true" tabindex="-1">
            @csrf
            <input type="hidden" name="_method" x-ref="method" value="">
        </form>
    </div>
@endif
