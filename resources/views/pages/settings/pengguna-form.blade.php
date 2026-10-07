@php
    $isCreate = ! $user->exists;
    $allowImport = $isCreate;
    $action = $isCreate ? 'Tambah' : 'Edit';
    // Only an edit has a record to name, and naming it is what tells
    // two of the rows apart once the page is scrolled down or bookmarked.
    $subject = $isCreate ? '' : $user->name;
@endphp

<x-ui.page-header
    :title="$action.' Pengguna'.($subject !== '' ? ' — '.$subject : '')"
    subtitle="Akun untuk masuk ke aplikasi. Email opsional untuk fitur lupa kata sandi."
    :crumbs="[
        'Pengaturan',
        ['label' => 'Pengguna & Role', 'href' => route('setting.pengguna')],
        $subject !== '' ? $action.' · '.$subject : $action,
    ]"
>
    <x-slot:actions>
        <a href="{{ route('setting.pengguna') }}" class="btn-secondary">Kembali ke Daftar</a>
    </x-slot:actions>
</x-ui.page-header>

<x-forms.form-tabs :allow-import="$allowImport">
    <x-slot:manual>
        @include('pages.settings.partials.pengguna-form', [
            'user' => $user,
            'isCreate' => $isCreate,
        ])
    </x-slot:manual>

    @if ($allowImport)
        <x-slot:import>
            <x-forms.import-step :action="route('master.import.upload', 'pengguna')" :template-url="route('master.import.template', 'pengguna')" :list-url="route('setting.pengguna')" label="Pilih berkas pengguna (.xlsx / .xls / .csv)" hint="Kolom password wajib. PIN boleh kosong." />
        </x-slot:import>
    @endif
</x-forms.form-tabs>