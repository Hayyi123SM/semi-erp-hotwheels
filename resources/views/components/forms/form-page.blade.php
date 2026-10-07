@props(['title' => '', 'subtitle' => '', 'crumbs' => [], 'backRoute' => null, 'backLabel' => 'Batal'])

<x-ui.page-header :title="$title" :subtitle="$subtitle" :crumbs="$crumbs">
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-ui.page-header>

{{ $slot }}

@if ($backRoute)
    <div class="mt-6">
        <a href="{{ $backRoute }}" class="btn-secondary">{{ $backLabel }}</a>
    </div>
@endif