@use('App\Services\Label\LabelTemplate')
@use('App\Services\Label\RackCode')

<x-ui.page-header
    title="Cetak Label Rak"
    subtitle="Label penanda rak untuk put-away dan opname. Mencetak label tidak mengubah stok."
    :crumbs="[
        'Master Data',
        ['label' => 'Lokasi Rak', 'href' => route('master.lokasi-rak')],
        'Cetak Label Rak',
    ]"
>
    <x-slot:actions>
        <a href="{{ route('master.lokasi-rak') }}" class="btn-secondary">Kembali ke Daftar</a>
    </x-slot:actions>
</x-ui.page-header>

@if ($errors->any())
    <x-ui.banner tone="error" class="mb-5">{{ $errors->first() }}</x-ui.banner>
@endif

{{--
    Daftar centang, bukan `<select multiple>`: operator berdiri di depan rak
    sambil memegang stiker, dan daftar centang bisa dibaca sekilas. Pencarian
    disaring di sisi klien supaya mengetik tidak memuat ulang halaman dan
    tidak menghapus centang yang sudah dipilih.
--}}
<div class="grid gap-5 lg:grid-cols-3"
     x-data="{
         q: '',
         picked: 0,
         matches(code) {
             return this.q === '' || code.toLowerCase().includes(this.q.toLowerCase());
         },
         visible() {
             return Array.from(this.$root.querySelectorAll('.rack-row')).filter((row) => ! row.hidden);
         },
         count() {
             return this.$root.querySelectorAll('.rack-picker:checked').length;
         },
         filter() {
             this.$root.querySelectorAll('.rack-row').forEach((row) => {
                 row.hidden = ! this.matches(row.dataset.code);
             });
         },
         setAll(on) {
             this.visible().forEach((row) => {
                 row.querySelector('.rack-picker').checked = on;
             });
             this.picked = this.count();
         },
     }"
     x-init="picked = count()">
    <div class="lg:col-span-2">
        <x-ui.section-card title="Pilih Rak">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <input type="search" x-model="q" @input="filter()"
                       class="input-base sm:max-w-xs" placeholder="Cari kode atau zona"
                       aria-label="Cari rak">
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-ghost" @click="setAll(true)">Pilih yang tampil</button>
                    <button type="button" class="btn-ghost" @click="setAll(false)">Kosongkan</button>
                </div>
            </div>

            <p class="mb-2 text-label-sm text-text-subtle">
                <span x-text="picked + ' rak dipilih'"></span>
            </p>

            <div class="max-h-96 overflow-y-auto rounded-lg border border-border-subtle p-2">
                <div class="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($racks as $rack)
                        <label class="rack-row flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-canvas"
                               data-code="{{ RackCode::printable($rack->code) }} {{ $rack->zone }}">
                            <input type="checkbox"
                                   form="cetak-label-rak"
                                   name="rack_ids[]"
                                   value="{{ $rack->id }}"
                                   class="rack-picker h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                                   @checked(in_array($rack->id, array_map('intval', (array) old('rack_ids')), true))
                                   @change="picked = count()">
                            <span class="font-mono text-label-sm text-text-strong">{{ RackCode::printable($rack->code) }}</span>
                            @if ($rack->zone)
                                <span class="ml-auto text-label-sm text-text-subtle">Zona {{ $rack->zone }}</span>
                            @endif
                        </label>
                    @empty
                        <p class="col-span-full px-2 py-6 text-center text-body-sm text-text-subtle">
                            Belum ada rak aktif untuk dicetak.
                        </p>
                    @endforelse
                </div>
            </div>
        </x-ui.section-card>
    </div>

    <div>
        <x-ui.section-card title="Ukuran dan Jumlah">
            {{--
                Form dan checkbox dipisah dengan atribut `form`, bukan bersarang.
                Checkbox di dalam form yang memuat form lain tidak dihitung
                browser, dan itu membuat pilihan rak selalu kosong.
            --}}
            <form id="cetak-label-rak" method="POST" action="{{ route('master.lokasi-rak.print-labels') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="rack-template" class="mb-1 block text-label-sm font-semibold text-text-strong">Ukuran label</label>
                    <select id="rack-template" name="template" class="select-base">
                        @foreach (LabelTemplate::cases() as $template)
                            <option value="{{ $template->value }}" @selected(old('template') === $template->value)>
                                {{ $template->label() }} &mdash; {{ $template->isQrOnly() ? 'QR saja' : ($template->hasRackQr() ? 'dengan QR (discan)' : 'tanpa QR') }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-label-sm text-text-subtle">
                        Label 3x2 tidak punya ruang untuk QR tanpa membuat kode rak terlalu
                        kecil dibaca. Pilih 4x3 kalau labelnya mau discan, atau 1,5x1,5 kalau
                        raknya sempit dan isinya sudah diketahui dari sistem.
                    </p>
                </div>

                <div>
                    <label for="rack-copies" class="mb-1 block text-label-sm font-semibold text-text-strong">Jumlah per rak</label>
                    <input id="rack-copies" type="number" name="copies"
                           value="{{ old('copies', 1) }}" min="1" max="200"
                           class="input-base">
                </div>

                <button type="submit" class="btn-primary w-full">
                    Cetak <span x-text="picked"></span> Label Rak
                </button>
            </form>
        </x-ui.section-card>
    </div>
</div>
