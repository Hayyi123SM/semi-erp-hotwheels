@props(['table', 'rows', 'columns'])

{{--
    The rows, and nothing around them.

    This is the region an in-place refresh replaces, so it deliberately does not
    include the toolbar, the view toggle or the shared confirm dialog: the
    reader keeps the search field they are typing into, and a dialog left open
    on one row does not close because the page under it was re-rendered.

    Still Blade on both paths. A client-side renderer would have to reproduce
    the column priorities, the card layout and every cell's formatting, and
    would end up a second, quietly divergent copy of what the columns already
    say.
--}}
@if ($rows->isEmpty())
    <x-ui.empty-state :title="$table->emptyTitle()" :description="$table->emptyDescription()" />
@else
    {{-- md and up, in table mode: the real table. min-w-max stops columns from being squeezed unreadably. --}}
    <div class="data-table-table table-scroll">
        <table class="w-full min-w-max text-left">
            <caption class="sr-only">{{ $table->caption() }}</caption>
            <thead class="thead-dense">
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" class="{{ $column->headClassResolved() }}" aria-sort="{{ $table->ariaSort($column) }}">
                            @if ($table->isSortable($column))
                                <a href="{{ $table->sortUrl($column) }}"
                                   class="inline-flex items-center gap-1 rounded hover:text-text-strong focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none">
                                    {{ $column->label }}
                                    @if ($table->isSorted($column))
                                        <svg class="h-3.5 w-3.5 {{ $table->direction() === 'asc' ? 'rotate-180' : '' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M19 12l-7 7-7-7M12 19V5" />
                                        </svg>
                                    @endif
                                </a>
                            @else
                                {{ $column->label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php($rowUrl = $table->rowUrlFor($row))
                    {{-- `data-row-url` membuat seluruh baris bisa diklik tanpa
                         membungkus isinya dalam <a>: teks seperti nomor nota
                         harus tetap bisa disalin, dan tombol aksi di dalam baris
                         harus tetap menjadi tombol. Yang membaca atribut ini ada
                         di data-table.js, bukan di sini. --}}
                    <tr @class(['row-dense', 'cursor-pointer' => $rowUrl !== null])
                        @if ($rowUrl !== null) data-row-url="{{ $rowUrl }}" @endif>
                        @foreach ($columns as $column)
                            <td class="{{ $column->cellClass() }}">
                                <x-ui.data-table.cell :column="$column" :row="$row" />
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- The same rows as cards: a single column below md, and the grid branch from md up. --}}
    <div class="data-table-cards">
        @foreach ($rows as $row)
            <x-ui.data-table.card :table="$table" :row="$row" />
        @endforeach
    </div>

    <x-ui.pagination :table="$table" :paginator="$rows" />
@endif
