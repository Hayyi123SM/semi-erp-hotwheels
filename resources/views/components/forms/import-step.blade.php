@props([
    'action' => '',
    'label' => 'Pilih berkas Excel (.xlsx / .xls / .csv)',
    'hint' => 'Baris pertama sebaiknya berisi judul kolom. Maksimal 4 MB.',
    'templateUrl' => null,
    'listUrl' => null,
    'accept' => '.xlsx,.xls,.csv',
    'maxBytes' => 4194304,
])

@use('App\Support\ImportSteps')

{{--
    Area unggah pada langkah pertama alur impor.

    Dua hal di sini yang tidak boleh berubah: `<input type="file">` tetap
    berada di dalam `<label for="import_file">`, dan tetap membawa `name`,
    `required`, serta `accept` milik server. Selama itu bertahan, klik tetap
    membuka dialog picker oleh dirinya sendiri dan tanpa JavaScript formulir
    ini tetap POST seperti biasa -- `importDropzone` hanya menambah jalan
    untuk mengisinya, tidak menggantinya.

    Drag state dipasang lewat `:class` dan bukan kelas yang ditulis di Blade,
    karena yang berubah hanya warnanya. `x-cloak` dipakai pada blok pesan
    supaya pesan galat tidak sempat tampil di frame pertama sebelum Alpine
    sempat membaca state.
--}}
{{-- Stepper diletakkan di dalam komponen ini, bukan di keempat halaman
     pemanggil, supaya tidak ada satu pun yang bisa lupa memasang halaman create
     yang baru. Hanya tahap 1 yang punya tautan: tanpa token belum ada
     pemetaan maupun validasi yang bisa dituju, dan tautan ke sana hanya
     berakhir di 404. --}}
<x-ui.stepper
    :current="ImportSteps::number('upload')"
    :steps="ImportSteps::for(null, $listUrl)"
/>

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="card"
      x-data="importDropzone({ accept: @js($accept), maxBytes: @js($maxBytes) })"
      x-on:dragover.window="if ($event.dataTransfer?.types?.includes('Files')) $event.preventDefault()"
      x-on:drop.window="if ($event.dataTransfer?.types?.includes('Files')) $event.preventDefault()">
    @csrf

    <div class="p-6">
        <label for="import_file"
               class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition"
               :class="dragging ? 'border-primary bg-primary-soft' : 'border-border-subtle bg-canvas hover:border-border-strong'"
               x-on:dragenter.prevent="dragEnter()"
               x-on:dragover="dragOver($event)"
               x-on:dragleave.prevent="dragLeave()"
               x-on:drop.prevent="drop($event)">

            <template x-if="fileName === ''">
                <div class="flex flex-col items-center gap-3">
                    <svg class="h-10 w-10 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span class="text-label-md text-text-muted">{{ $label }}</span>
                    <span class="text-label-sm font-medium text-primary">atau seret berkas ke area ini</span>
                    <span class="text-label-sm text-text-subtle">{{ $hint }}</span>
                </div>
            </template>

            <template x-if="fileName !== ''">
                <div class="flex flex-col items-center gap-2">
                    <svg class="h-10 w-10 text-success-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-label-md text-text-strong" x-text="fileName"></span>
                    <span class="text-label-sm text-text-subtle" x-text="fileSize + ' · klik untuk mengganti'"></span>
                </div>
            </template>

            <input type="file"
                   id="import_file"
                   name="import_file"
                   accept="{{ $accept }}"
                   class="sr-only"
                   required
                   x-ref="input"
                   x-on:change="picked($event)">
        </label>

        @error('import_file')
            <p class="mt-2 text-label-sm text-error-text">{{ $message }}</p>
        @enderror

        <p x-show="error" x-cloak class="mt-2 text-label-sm text-error-text" x-text="error"></p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="text-label-sm text-text-subtle">Kolom yang tidak diinginkan cukup dilewati saat pemetaan.</span>
                @if ($templateUrl)
                    <a href="{{ $templateUrl }}" class="btn-ghost h-9 px-3 text-primary">Unduh Template</a>
                @endif
            </div>
            <button type="submit" class="btn-primary">Lanjut ke Pemetaan</button>
        </div>
    </div>
</form>