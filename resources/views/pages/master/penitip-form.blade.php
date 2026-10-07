@php
    $isCreate = ! $consignor->exists;
    $allowImport = $isCreate && $canManage;
    $action = $isCreate ? 'Tambah' : 'Edit';
    // Only an edit has a record to name, and naming it is what tells
    // two of the rows apart once the page is scrolled down or bookmarked.
    $subject = $isCreate ? '' : $consignor->name;
@endphp

<x-ui.page-header
    :title="$action.' Penitip'.($subject !== '' ? ' — '.$subject : '')"
    subtitle="Data dasar penitip. Kolom bertanda * wajib diisi."
    :crumbs="[
        'Master Data',
        ['label' => 'Data Penitip', 'href' => route('master.penitip')],
        $subject !== '' ? $action.' · '.$subject : $action,
    ]"
>
    <x-slot:actions>
        <a href="{{ route('master.penitip') }}" class="btn-secondary">Kembali ke Daftar</a>
    </x-slot:actions>
</x-ui.page-header>

<x-forms.form-tabs :allow-import="$allowImport">
    <x-slot:manual>
        @include('pages.master.partials.penitip-form', [
            'consignor' => $consignor,
            'isCreate' => $isCreate,
            'canManage' => $canManage,
        ])
    </x-slot:manual>

    @if ($allowImport)
        <x-slot:import>
            <x-forms.import-step :action="route('master.import.upload', 'penitip')" :template-url="route('master.import.template', 'penitip')" :list-url="route('master.penitip')" />
        </x-slot:import>
    @endif
</x-forms.form-tabs>