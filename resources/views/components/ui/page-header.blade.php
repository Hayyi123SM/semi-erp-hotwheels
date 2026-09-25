@props(['title' => '', 'subtitle' => '', 'crumbs' => []])

<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div class="min-w-0">
        @if ($crumbs)
            <nav class="mb-1.5 flex flex-wrap items-center gap-1.5 text-label-sm text-text-subtle" aria-label="Breadcrumb">
                @foreach ($crumbs as $crumb)
                    <span>{{ $crumb }}</span>
                    @if (!$loop->last)
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg>
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