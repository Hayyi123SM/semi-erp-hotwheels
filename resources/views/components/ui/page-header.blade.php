@props(['title' => '', 'subtitle' => '', 'crumbs' => [], 'actions' => null])

<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div class="min-w-0">
        @if ($crumbs)
            <nav class="mb-1.5 flex flex-wrap items-center gap-1.5 text-label-sm text-text-subtle" aria-label="Breadcrumb">
                @foreach ($crumbs as $crumb)
                    @php
                        // A crumb is a plain string when it only names a place, or
                        // ['label' => ..., 'href' => ...] when that place is a real
                        // page. Group headings have no page of their own and must
                        // keep the plain form, so they never become a link to
                        // nowhere. Blade comments are not stripped inside @php, so
                        // this one has to be a PHP comment.
                        $node = is_array($crumb) ? $crumb : ['label' => $crumb];
                        $label = $node['label'] ?? '';
                        $href = $node['href'] ?? null;
                    @endphp

                    @if ($loop->last)
                        {{-- The current page is not a destination. Capped because it is
                             the only crumb that can carry user-supplied text. --}}
                        <span aria-current="page" class="min-w-0 max-w-[16rem] truncate text-text-muted sm:max-w-[24rem]">{{ $label }}</span>
                    @elseif ($href)
                        <a href="{{ $href }}" class="rounded transition hover:text-text-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">{{ $label }}</a>
                    @else
                        <span>{{ $label }}</span>
                    @endif

                    @if (!$loop->last)
                        <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                    @endif
                @endforeach
            </nav>
        @endif
        <h1 class="text-headline-lg text-text-strong">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-body-sm text-text-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>