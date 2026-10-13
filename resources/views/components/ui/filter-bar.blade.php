{{--
    Baris filter milik halaman, bukan milik tabel.

    Halaman laporan menyaring seluruh isinya -- kartu KPI, ringkasan, dan tabel
    -- dari satu rentang tanggal. Filter seperti itu tidak boleh tinggal di
    dalam form tabel: submit tabel mengganti potongan fragment, sedangkan
    rentang tanggal harus memuat ulang seluruh halaman. Komponen ini memegang
    form GET-nya sendiri supaya pemanggil cukup menaruh kontrolnya di slot,
    termasuk input tersembunyi milik halaman (mis. dimensi margin).

    Kontrol di dalamnya semuanya h-11 lewat `input-base`/`btn-secondary`, dan
    barisnya rata bawah, jadi sederet input bertinggi sama duduk pada satu
    garis tanpa saling mendorong.
--}}
@props(['action', 'resetUrl' => null, 'active' => false, 'label' => null])

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'card']) }}>
    <div class="flex flex-wrap items-end gap-3 px-4 py-3 sm:gap-4 sm:px-6 sm:py-4">
        @if ($label)
            {{-- Sejajar tengah dengan input h-11 di sebelahnya, bukan menempel
                 ke dasar baris seperti label yang punya kontrol sendiri. --}}
            <p class="pb-3 text-label-sm font-medium uppercase tracking-wide text-text-subtle">{{ $label }}</p>
        @endif

        {{ $slot }}

        <div class="ml-auto flex items-center gap-3">
            <button type="submit" class="btn-secondary">Terapkan</button>

            @if ($resetUrl && $active)
                <a href="{{ $resetUrl }}" class="text-label-md text-text-subtle transition hover:text-text-strong">Reset</a>
            @endif
        </div>
    </div>
</form>
