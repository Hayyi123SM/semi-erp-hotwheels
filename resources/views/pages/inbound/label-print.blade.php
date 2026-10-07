@php
    $paperLayout = $paperLayout ?? null;
@endphp

<x-label.document
    :title="$title ?? 'Cetak Label'"
    :page-size="$paperLayout?->pageSizeCss() ?? $activeTemplate?->pageSizeCss()"
>
    {{--
        Ukuran label yang sedang aktif ditulis di layar, bukan cuma di kode.

        Ini yang baru: sebelumnya halaman ini tidak pernah menampilkan ukuran yang
        dicetak, jadi satu-satunya cara memastikan adalah menghitung kotak di
        kertas hasil cetak. Kalau Owner lupa menekan "Simpan Pengaturan
        Label", tidak ada yang memberi tahu -- persis kejadian yang dulu
        membuat Uji Cetak terasa "tidak mengubah ukuran".
    --}}


    {{-- `LabelPage` sudah membungkus semua label di dalam `.label-sheet`. --}}
    {!! $labels !!}

    {{--
        Panel kendali: satu elemen fixed untuk tombol dan informasi sekaligus.

        Sebelumnya tombol dan informasi adalah dua elemen `position: fixed` yang
        terpisah di sudut berbeda, PLUS blok `<style>` inline berisi dua pasang
        media query yang saling menimpa nilai `bottom`-nya. Di tablet dan HP
        hasilnya panel yang saling menutupi -- informasi menutupi tombol tepat
        saat operator paling butuh menekan cetak.

        Sekarang keduanya anak dari satu bar yang membentang dari kiri ke kanan,
        jadi tidak ada lagi dua sudut yang bisa bertabrakan, dan panel informasi
        memakai `<details>` supaya bisa ditutup. Penutupan tanpa JavaScript bukan
        detail kecil: halaman ini sengaja tidak memuat Alpine atau bundle
        aplikasi, dan tablet operator tidak boleh butuh koneksi untuk mencetak.

        Baris ringkasan di `<summary>` sudah membawa angka yang paling sering
        dicari -- jumlah label per halaman -- supaya panel tetap informatif saat
        tertutup tanpa harus menutupi kertas.
    --}}
    <div class="no-print label-toolbar">
        <div class="label-toolbar__actions">
            <button type="button" onclick="window.print()"
                    class="label-toolbar__btn label-toolbar__btn--primary">
                Cetak {{ $total }} label
            </button>

            {{--
                Cetak langsung (thermal), hanya saat Owner memilihnya.

                Jalur ini menyusun ulang perintah TSPL dari server untuk `jobIds`
                yang sama, lalu mengirimnya lewat WebUSB. Kalau gagal (tanpa
                WebUSB, tanpa printer yang punya interface vendor), `onFailed`
                di `label-thermal.js` jatuh kembali ke `window.print()` -- jadi
                cetakan tidak pernah hilang diam-diam, hanya butuh dialog browser.
            --}}
            @if (($labelPrintMethod ?? 'browser') === 'thermal' && isset($jobIds))
                <button type="button"
                        data-thermal-label
                        data-url="{{ $thermalUrl }}"
                        data-ids="{{ json_encode($jobIds, JSON_UNESCAPED_SLASHES) }}"
                        class="label-toolbar__btn">
                    Cetak {{ $total }} label thermal
                </button>
                @vite(['resources/js/label-thermal.js'])
            @endif

            <a href="{{ $backUrl ?? route('inbound.cetak-label') }}"
               class="label-toolbar__btn label-toolbar__btn--secondary">
                Kembali
            </a>
        </div>

        @php
            $grid = $paperLayout?->grid;
            $hasTestPrintNotice = $isTestPrint ?? false;
        @endphp

        @if ($activeTemplate || $grid || $hasTestPrintNotice)
            <details class="label-toolbar__info">
                <summary>
                    @if ($grid)
                        Info cetak &middot; {{ $grid->labelsPerSheet() }} label per halaman
                    @elseif ($activeTemplate)
                        Info cetak &middot; {{ $activeTemplate->label() }}
                    @else
                        Info cetak
                    @endif
                </summary>

                <div class="label-toolbar__body">
                    @if ($activeTemplate)
                        {{--
                            Ukuran label yang sedang aktif ditulis di layar,
                            bukan cuma di kode. Kalau Owner lupa menyimpan
                            Pengaturan Label, tidak ada yang memberi tahu --
                            persis kejadian yang dulu membuat Uji Cetak terasa
                            "tidak mengubah ukuran".
                        --}}
                        <p class="label-toolbar__note">
                            Ukuran label aktif:
                            <strong>{{ $activeTemplate->label() }}</strong>
                            @if ($hasTestPrintNotice)
                                <span>(dari Pengaturan &rsaquo; Perangkat)</span>
                            @endif
                        </p>
                    @endif

                    @if ($paperLayout)
                        {{--
                            Mode dan ukuran kertas, untuk kedua mode. Pertanyaan
                            operator sebelum menekan cetak selalu "berapa label
                            yang keluar per halaman dan kertasnya seberapa
                            besar", dan jawabannya berbeda antara mode gulungan
                            dan mode stiker.
                        --}}
                        <p class="label-toolbar__note">
                            <strong>{{ $paperLayout->summary() }}</strong>
                            @if ($grid)
                                <br>
                                {{--
                                    Sisa tinggi ikut ditulis karena 8 baris x
                                    15 mm + 7 celah x 2 mm = 134 mm pada kertas
                                    150 mm. Jedanya 16 mm itu ruang yang
                                    sengaja dibiarkan di bawah, bukan label
                                    yang hilang.
                                --}}
                                <span>
                                    Sisa bawah {{ $grid->trailingSlackLabel() }}
                                    &middot; lebar printer {{ $grid->mediaWidthDots() }} dot
                                </span>
                            @endif
                        </p>
                    @endif

                    @if ($hasTestPrintNotice)
                        {{--
                            Uji cetak ikut tampil di layar, bukan cuma di
                            kertas: kalau operator memakai stiker label barang
                            sungguhan, label yang sama persis akan keluar lalu
                            lot ikut berubah tanpa ada job label.
                        --}}
                        <p class="label-toolbar__note label-toolbar__note--warning">
                            <strong>Uji cetak.</strong> Label ini contoh, bukan
                            label barang. Jangan ditempel di rak atau pada lot
                            mana pun.
                        </p>
                    @endif

                    @if ($grid)
                        {{--
                            Skala selain 100% mengalikan seluruh ukuran, jadi
                            celah antar stiker ikut bergeser dan label bisa
                            menimpa stiker tetangga. Instructions-nya satu
                            kalimat supaya operator tidak menebak.
                        --}}
                        <p class="label-toolbar__note label-toolbar__note--warning">
                            <strong>Dialog cetak:</strong> pilih ukuran
                            <strong>Custom</strong> {{ $grid->pageSizeCss() }},
                            <strong>Margins: None</strong>,
                            <strong>Scale: 100%</strong>. Skala selain 100%
                            membuat celah antar stiker bergeser.
                        </p>
                    @endif
                </div>
            </details>
        @endif
    </div>
</x-label.document>
