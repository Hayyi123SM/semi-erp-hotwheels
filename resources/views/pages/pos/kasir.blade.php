{{--
    `receipt.css` sengaja TIDAK dipush dari halaman ini. `page()` merender view
    halaman lebih dulu sebelum layout, dan `Factory::flushStateIfDoneRendering()`
    mengosongkan stack tepat setelah render inner selesai -- push mana pun dari
    sini tidak pernah sampai ke `@stack('head')` layout, dan pratinjau struk di
    dialog kasir tampil sebagai teks tanpa format. Gaya itu sekarang dimuat
    langsung oleh `layouts/app.blade.php`.
--}}

<div class="min-h-full"
     x-data="posCart()"
     data-lookup-url="{{ route('pos.produk.cari') }}"
     data-checkout-url="{{ route('pos.transaksi.store') }}"
     @keydown.window.f2.prevent="openPicker()"
     @keydown.window.f8.prevent="pay()"
     @keydown.window.escape.prevent="clear()">

    {{--
        POS keeps the 62/38 split from `lg` up. Below that the right column is
        not a column: at 768px the sidebar is an icon rail (4.5rem), `main` takes
        its own padding, and the 38fr share of what's left is 246px -- 198px after
        the rail's own padding. The total is 36px bold, so `Rp10.500.000` needs
        ~230px and the quick-cash buttons got 60px each for a label needing ~85px.
        Both overflowed, and the five-column cart table got 402px to do it in.

        So below `lg` it is one column and the settlement panel becomes a
        full-width bar under the cart. The cart then has 648px of table, and the
        pay button stays reachable without scrolling -- which is the whole point
        of not stacking it above the cart instead.
    --}}
    <div class="grid gap-0 lg:h-full lg:grid-cols-[minmax(0,62fr)_minmax(0,38fr)]">
        <!-- Left work zone -->
        <div class="bg-canvas p-4 sm:p-6 lg:min-h-0 lg:overflow-y-auto">
            <div class="mx-auto max-w-4xl">
                {{--
        Nama kasir dan nama shift datang dari shift yang benar-benar terbuka.
        Menulisnya sebagai teks di sini membuat halaman menampilkan shift yang
        berbeda dari yang dipakai menjualan, dan saat tutup shift angkanya tidak
        akan cocok dengan yang terlihat di kasir.
    --}}
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-headline-md text-text-strong">
                            {{-- `shifts` tidak punya kolom nama, hanya siapa yang
                                 membukanya dan kapan. "Shift 12" berarti id, yang
                                 terbaca seperti nomor akun oleh kasir dan
                                 bermakna apa-apa baginya. --}}
                            Kasir
                            @if ($shift)
                                <span class="font-mono text-body-md font-normal text-text-muted">
                                    #{{ $shift->id }}
                                </span>
                            @endif
                        </h1>
                        <p class="text-label-sm text-text-muted">
                            {{ auth()->user()->name }} · {{ $deviceId ?? 'perangkat ini' }}
                            @if ($shift?->opened_at)
                                · dibuka {{ \App\Support\Format::datetime($shift->opened_at) }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($shift)
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label-sm bg-success-bg text-success-text">
                                <span class="h-2 w-2 rounded-full bg-success-text"></span> SHIFT TERBUKA
                            </span>
                            <a href="{{ route('pos.shift-kasir') }}" class="btn-ghost h-10 px-3">Shift</a>
                        @else
                            {{-- Menjualan tanpa shift akan menghasilkan transaksi yang
                                 tidak masuk rekap kas mana pun, jadi halaman ini
                                 menyuruh kasir membukanya lebih dulu, bukan
                                 menampilkan keranjang yang nanti tidak bisa
                                 direkonsiliasi. --}}
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label-sm bg-warning-bg text-warning-text">
                                <span class="h-2 w-2 rounded-full bg-warning-text"></span> SHIFT BELUM DIBUKA
                            </span>
                            <a href="{{ route('pos.shift-kasir') }}" class="btn-primary h-10 px-3">Buka Shift</a>
                        @endif
                    </div>
                </div>

                {{-- Scan / cari.
                     Satu kendali, bukan dua. Kolomnya menerima kode dari scanner dan
                     juga menerima yang diketik orang; tombol di sebelahnya membuka
                     daftar barang. Ditempelkan jadi satu grup yang berbagi satu
                     border karena keduanya satu tugas -- "masukkan barang ke keranjang"
                     -- dan dua kotak dengan dua garis di tengahnya terbaca sebagai
                     dua tugas yang harus dipilih di antara lain.

                     Tombolnya membaca `scanField.value` langsung, bukan state
                     bayangan: `x-scan` mengosongkan kolom itu begitu kode masuk, jadi
                     salinannya sudah basi tepat saat tombol ditekan. --}}
                <div class="mt-4" x-ref="scanZone">
                    <div class="flex">
                        <div class="relative min-w-0 flex-1">
                            <input
                                type="text"
                                x-ref="scanField"
                                placeholder="Pindai barcode atau ketik SKU [F2]"
                                {{-- `x-scan` memanggil ekspresi ini dengan kode yang dipindai sebagai
                                     argumen, bukan membaca `$el.value`. Scanner mengetik
                                     cepat lalu Enter; `$el.value` bisa sudah berisi
                                     karakter yang diketik orang sesudahnya. --}}
                                x-scan="addBySku($event)"
                                class="scan-input w-full rounded-r-none border-r-0 pr-11"
                            >
                            <span class="pointer-events-none absolute right-3 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded bg-primary-soft text-label-sm font-bold text-primary">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M3 5v14M8 5v14M12 5v14M17 5v14M21 5v14"/>
                                </svg>
                            </span>
                        </div>

                        {{-- Tombolnya di dalam zona `[data-allow-focus]`, kalau tidak
                             `x-scan` akan merebut fokus kembali 100 ms setelah
                             diklik dan kasir tidak akan pernah bisa mengetik. --}}
                        <button type="button"
                                class="btn-primary h-14 shrink-0 rounded-l-none border-l border-white/25 px-4 sm:px-5"
                                data-allow-focus
                                aria-label="Cari produk"
                                @click="openPicker()">
                            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"/>
                                <path d="M21 21l-4.35-4.35"/>
                            </svg>
                            <span class="hidden sm:inline">Cari Produk</span>
                        </button>
                    </div>

                    <p class="mt-2 text-label-sm text-text-subtle" x-show="lastError" x-text="lastError" x-cloak></p>
                </div>

                <!-- Basket -->
                <div class="mt-5 card overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Item</th>
                                <th class="px-4 py-3 text-center font-semibold">Qty</th>
                                <th class="px-4 py-3 text-right font-semibold">Harga</th>
                                <th class="px-4 py-3 text-right font-semibold">Jumlah</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            <template x-for="item in items" :key="item.sku">
                                <tr class="h-12 border-b border-border-subtle transition hover:bg-canvas">
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2.5">
                                            <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-label-md"
                                                  :class="item.ownership === 'TITIP'
                                                      ? 'border-titip-border bg-titip-bg text-titip-text'
                                                      : 'border-pribadi-border bg-pribadi-bg text-pribadi-text'">
                                                <span x-text="item.ownership"></span>
                                            </span>
                                            <div>
                                                <p class="text-body-md font-medium text-text-strong" x-text="item.name"></p>
                                                <p class="font-mono text-label-sm text-text-subtle">
                                                    <span x-text="item.sku"></span>
                                                    <span x-show="item.consignor" x-text="' · ' + item.consignor"></span>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center justify-center gap-1">
                                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-text-muted hover:bg-canvas" @click="decrement(item.sku)" :aria-label="'Kurangi ' + item.name">−</button>
                                            <span class="w-8 text-center text-body-md font-semibold tabular-nums" x-text="item.qty"></span>
                                            <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-text-muted hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-40"
                                                    @click="increment(item.sku)"
                                                    :disabled="!item.canIncrease"
                                                    :aria-label="'Tambah ' + item.name"
                                                    :title="item.canIncrease ? 'Tambah' : 'Stok tinggal ' + item.stock">+</button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-right text-body-md tabular-nums" x-text="format.rupiah(item.price)"></td>
                                    <td class="px-4 py-2 text-right text-body-md font-semibold tabular-nums" x-text="format.rupiah(item.price * item.qty)"></td>
                                    <td class="px-4 py-2 text-right">
                                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded text-error-text hover:bg-error-bg" @click="removeItem(item.sku)" aria-label="Hapus item">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="items.length === 0" x-cloak>
                                <td colspan="5">
                                    <x-ui.empty-state
                                        title="Keranjang kosong"
                                        description="Pindai barcode atau ketik SKU di atas. Satu produk jadi satu baris di sini."
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-label-sm text-text-subtle">
                    <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">F2</span> Cari produk
                    · <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">F8</span> Bayar
                    · <span class="mr-1 rounded bg-canvas px-1.5 py-0.5 font-mono">Esc</span> Kosongkan
                </p>
            </div>
        </div>

        {{--
            Right settlement zone.

            Two layouts, one markup, and the split is at `lg` for the reason in
            the comment on the grid above.

            From `lg`: a vertical column, with the pay button pushed to the
            bottom by `mt-auto`. Below `lg`: a horizontal bar under the cart, so
            the total, the methods, the tender, and the pay button all land in
            one screen's worth of height without needing a scroll to finish a
            sale.
        --}}
        <div class="flex flex-col gap-5 border-t border-border-subtle bg-surface-lowest p-4 sm:p-5 lg:min-h-0 lg:gap-0 lg:border-l lg:border-t-0 lg:p-6">

            {{-- Total. Above `lg` this sits alone at the top of the column; below
                 it shares the first row with the payment methods. --}}
            <div class="lg:mt-0">
                <p class="text-label-sm uppercase tracking-wide text-text-muted">Total Belanja</p>
                <p class="mt-1 text-display-total font-bold text-text-strong tabular-nums"
                   x-text="format.rupiah(subtotal)">Rp0</p>
            </div>

            {{-- Payment methods. A single row of three on every width: the labels
                 are `Tunai`, `QRIS`, `EDC`, so even at the narrowest width here
                 they fit, and a wrapped second row of one method would read as a
                 layout fault. --}}
            <div class="lg:mt-6">
                <p class="mb-2 text-label-md text-text-muted">Metode Pembayaran</p>
                <div class="grid grid-cols-3 gap-2">
                    {{-- Dari enum, bukan daftar yang diketik di sini. Daftar
                         tulisan tangan di view pernah menawarkan `KARTU`, yang
                         tidak ada di `PaymentMethod` sama sekali: kasir bisa
                         memilihnya dan tidak ada tempat yang mengawasinya. --}}
                    @foreach (\App\Enums\PaymentMethod::pos() as $method)
                        <button type="button"
                                class="rounded-lg border px-3 py-2 text-body-sm font-semibold transition"
                                :class="paymentMethod === @js($method->value)
                                    ? 'border-primary bg-primary text-on-primary'
                                    : 'border-border-subtle bg-canvas text-text-muted hover:border-border-strong'"
                                @click="paymentMethod = @js($method->value)">
                            {{ $method->label() }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{--
                Cash received.

                `x-data="{ showNumpad: true }"` yang pernah membungkus bagian ini
                sudah dihapus: `showNumpad` tidak dibaca di mana pun pada halaman,
                jadi ia hanya membagi rantai resolusi scope tanpa memberi apa pun.
                Scope yang bersih adalah yang membuat `x-money-model` di bawah
                bisa menemukan `tender` seperti seharusnya.
            --}}
            <div class="lg:mt-6">
                <div class="flex items-center justify-between">
                    <p class="mb-2 text-label-md text-text-muted">Uang Diterima</p>
                    <span class="text-label-sm text-text-muted">Quick cash:</span>
                </div>
                <div class="grid grid-cols-2 gap-2 lg:grid-cols-3">
                    @foreach ([50000, 100000, 150000] as $denom)
                        <button type="button"
                                class="h-14 rounded-lg border border-border-subtle bg-canvas text-body-md font-semibold text-text-strong transition hover:border-primary hover:bg-primary-soft active:scale-[0.98]"
                                @click="quickTender({{ $denom }})">
                            {{ \App\Support\Format::rupiah($denom) }}
                        </button>
                    @endforeach
                    <button type="button"
                            class="col-span-2 h-14 rounded-lg border border-border-subtle bg-canvas text-body-md font-semibold text-text-strong transition hover:bg-canvas/70 disabled:cursor-not-allowed disabled:opacity-40 lg:col-span-3"
                            :disabled="subtotal === 0"
                            @click="quickTender(subtotal)">
                        Uang Pas (exact)
                    </button>
                </div>
                {{-- Dikelompokkan seperti uang di seluruh halaman lain: kasir menulis
                     `100.000`, bukan `100000`. `x-money-model`, bukan `x-money` +
                     `x-model` -- kalau keduanya dipakai, yang menulis kolom ini ada
                     dua, dan keduanya diam-diam menimpa yang lain. --}}
                <input type="text"
                       inputmode="numeric"
                       autocomplete="off"
                       x-money-model="tender"
                       aria-label="Uang diterima"
                       class="input-base mt-3 text-right text-headline-md font-semibold tabular-nums"
                       placeholder="Masukkan nominal...">
            </div>

            <div class="rounded-lg bg-canvas p-4 lg:mt-4">
                <p class="text-label-sm text-text-muted">Kembalian</p>
                <p class="text-currency-display font-bold text-success-text tabular-nums"
                   x-text="format.rupiah(change)">Rp0</p>
            </div>

            {{--
                Bayar & cetak struk.

                Di tengah-tengah baris, tanpa `mt-auto`: di bawah `lg` barisnya
                tinggi sendiri, dan tombol yang menempel ke dasar justru membuat
                kasir mencari tombol bayar, bukan melihatnya.

                "Riwayat Transaksi" yang pernah di atas sini sudah dihapus, bukan
                dipindah. Sidebar sudah punya entri itu (`layouts/sidebar.blade.php`),
                jadi di halaman kasir ia cuma duplikat -- dan tepat di atas tombol
                bayar ia jadi satu permukaan lagi yang bisa tidak sengaja terkena.
            --}}
            <div class="lg:mt-auto lg:pt-6">
                {{--
                    Label hanya menjanjikan yang benar-benar terjadi.

                    Tombol ini bernama "Bayar", bukan "Bayar & Cetak Struk",
                    karena cetak struk bukan bagian dari menekan tombol:
                    setelah server mengiyakan, layar membuka dialog yang berisi
                    pratinjau struk dengan "Cetak Struk" dan "Selesai", dan yang
                    mencetak adalah kasir yang memilih -- bukan tombol bayar
                    yang menyala sendiri. Label yang menjanjikan cetakan padahal
                    belum ada pintunya pernah membuat kasir menekan tombol
                    berulang sambil menunggu kertas; sekarang pintunya ada di
                    dialog, dan labelnya tetap jujur.

                    `disabled` hanya untuk dua keadaan yang tidak bisa dijawab
                    dengan toast -- uang kurang dan permintaan yang sedang jalan.
                    Keranjang kosong sengaja dibiarkan bisa ditekan: tombol mati
                    tidak bisa menjelaskan apa pun, sedangkan "Keranjang kosong"
                    bisa.
                --}}
                <button type="button"
                        class="btn-primary w-full h-14 text-body-md"
                        :disabled="paying || (paymentMethod === 'TUNAI' && tenderAmount < subtotal && items.length > 0)"
                        :title="paymentMethod === 'TUNAI' && tenderAmount < subtotal && items.length > 0
                            ? `Uang diterima ${format.rupiah(tenderAmount)} kurang dari total ${format.rupiah(subtotal)}.`
                            : null"
                        :aria-busy="paying"
                        @click="pay()">
                    <span x-text="paying ? 'Menyimpan...' : 'Bayar'">Bayar</span>
                    <span class="rounded bg-on-primary/20 px-2 py-0.5 font-mono text-label-sm"
                          x-show="!paying">F8</span>
                </button>
            </div>
        </div>
    </div>

    {{--
        Panel pencarian produk.

        Berisi `<template>`, bukan komponen yang langsung dirender. `notify.templateModal()`
        membawanya ke dalam popup SweetAlert2, menjalankan `initTree()` di dalamnya
        supaya Alpine hidup di dalamnya, dan melepaskannya lagi saat ditutup.

        Alasan `<template>` dan bukan `x-ui.drawer`: panel ini butuh ruang lebar
        untuk daftar hasil dan input cari yang terfokus, dan `drawer` tidak pernah
        dipakai di halaman mana pun di aplikasi ini -- tidak ada yang bisa
        mereferensikannya. `templateModal` sudah dipakai tiga kali
        (`seri-panel`, `pin-dialog`), jadi polanya ada dan teruji.
    --}}
    {{--
        Badan dialog "SKU tidak ditemukan".

        Isinya ditulis Blade, bukan dirangkai sebagai string di dalam click handler,
        supaya tautan karantina benar-benar tautan: bisa dibuka di tab baru, punya
        `href` yang bisa dibaca, dan tetap hidup kalau JavaScript gagal memuat.
       --}}
    <template id="pos-unknown-sku">
        {{-- `unknown` bukan milik scope keranjang: badan dialog dibangun
             `initTree()` sebagai pohon Alpine sendiri, jadi ia menerima kodenya
             lewat event window, sama seperti picker. --}}
        <div class="space-y-4 text-left" x-data="posUnknownDialog()">
            <div class="rounded-lg border border-border-subtle bg-canvas px-4 py-3">
                <p class="text-label-sm text-text-muted">Kode yang dipindai</p>
                <p class="mt-1 break-all font-mono text-body-lg font-semibold text-text-strong"
                   x-text="code"></p>
            </div>
            @if (auth()->user()?->isOwner())
                <p class="text-body-sm text-text-muted">
                    Periksa lagi kapitalisasinya, atau pindahkan barang ini ke karantina
                    sampai ada yang berhak mengidentifikasi.
                </p>
                <a href="{{ route('inventory.karantina') }}" class="btn-secondary w-full">
                    Buka Halaman Karantina
                </a>
            @else
                {{-- Karantina Owner-only, jadi Staff diarahkan meminta Owner alih-alih
                     diberi tautan yang akan berakhir 403. --}}
                <p class="text-body-sm text-text-muted">
                    Periksa lagi kapitalisasinya, atau minta Owner memindahkan barang ini
                    ke karantina sampai ada yang berhak mengidentifikasi.
                </p>
            @endif
        </div>
    </template>

    <template id="pos-product-picker">
        <div x-data="productPicker({ url: @js(route('pos.produk.cari')) })" class="text-left">
            <label for="pos-picker-search" class="mb-2 block text-label-md font-semibold text-text-muted">
                Cari SKU atau nama produk
            </label>
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/>
                    <path d="M21 21l-4.35-4.35"/>
                </svg>
                <input
                    id="pos-picker-search"
                    type="text"
                    x-ref="search"
                    x-model="term"
                    @input="searchSoon()"
                    {{-- Enter punya dua arti tergantung ada atau tidaknya yang
                         disorot: begitu sorotan bergerak, Enter berarti "pilih ini",
                         dan tanpa sorotan Enter masih berarti "cari". Panah atas dan
                         bawah hanya bergerak kalau ada hasil -- daftar kosong itu
                         jawaban server, bukan pilihan. --}}
                    @keydown.enter.prevent="submit()"
                    @keydown.arrow-down.prevent="move(1)"
                    @keydown.arrow-up.prevent="move(-1)"
                    class="input-base w-full pl-9"
                    placeholder="mis. Skyline atau CN01-HW-001"
                    autocomplete="off"
                >
            </div>

            <p class="mt-2 text-label-sm text-text-subtle" x-show="term.trim().length > 0 && !minLengthReached" x-cloak>
                Ketik minimal 2 huruf.
            </p>

            {{-- Card ⇄ tabel. Dua tampilan untuk hal yang sama, bukan dua data:
                 satu daftar yang sama dalam dua bentuk. --}}
            <div class="mt-4 flex items-center justify-between gap-3" x-show="minLengthReached" x-cloak>
                <p class="text-label-sm text-text-muted" aria-live="polite">
                    <span x-show="loading">Mencari…</span>
                    <span x-show="!loading && hasResults" x-text="format.number(items.length) + ' barang ditemukan'"></span>
                    <span x-show="!loading && hasResults" class="hidden lg:inline"> · <span class="font-medium">↑↓</span> pilih, <span class="font-medium">Enter</span> masukkan</span>
                </p>
                <div class="inline-flex items-center gap-0.5 rounded-lg bg-canvas p-1">
                    <button type="button"
                            class="rounded-md px-2.5 py-1 text-label-md transition"
                            :class="view === 'card' ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'"
                            @click="view = 'card'"
                            :aria-pressed="view === 'card'">
                        Kartu
                    </button>
                    <button type="button"
                            class="rounded-md px-2.5 py-1 text-label-md transition"
                            :class="view === 'table' ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'"
                            @click="view = 'table'"
                            :aria-pressed="view === 'table'">
                        Tabel
                    </button>
                </div>
            </div>

            {{-- Placeholder-shaped placeholder: highlighter during loading so the
                 list does not collapse to nothing between one keystroke and the
                 next, which reads as a result that was empty. --}}
            <div class="mt-4 grid max-h-96 gap-2 overflow-hidden sm:grid-cols-2" x-show="loading" x-cloak aria-hidden="true">
                <template x-for="n in 4" :key="n">
                    <div class="animate-pulse rounded-lg border border-border-subtle bg-canvas p-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="h-4 w-16 rounded bg-border-subtle"></div>
                            <div class="h-4 w-14 rounded bg-border-subtle"></div>
                        </div>
                        <div class="mt-2 h-4 w-3/4 rounded bg-border-subtle"></div>
                        <div class="mt-2 h-3 w-1/2 rounded bg-border-subtle"></div>
                    </div>
                </template>
            </div>

            <p class="mt-4 rounded-lg border border-error-border bg-error-bg px-4 py-2.5 text-body-sm text-error-text"
               x-show="error" x-text="error" x-cloak></p>

            <p class="mt-4 rounded-lg border border-border-subtle bg-canvas px-4 py-2.5 text-body-sm text-text-muted"
               x-show="!loading && !error && searched && !hasResults" x-cloak>
                Tidak ada barang yang cocok dengan
                <span class="font-mono font-semibold text-text-strong" x-text="term.trim()"></span>.
            </p>

            <p class="mt-4 rounded-lg border border-warning-border bg-warning-bg px-4 py-2.5 text-body-sm text-warning-text"
               x-show="!loading && !error && searched && hasResults && !items.some((i) => i.sellable)" x-cloak>
                Semua hasil tidak bisa dijual. Periksa lagi penataannya.
            </p>

            {{-- Kartu. Satu kartu per lot, bukan satu per nama produk: dua lot
                 dengan nama yang sama adalah dua barang yang berbeda, dan
                 menggabungkannya membuat kasir tidak bisa memilih yang benar. --}}
            <div class="mt-4 grid max-h-96 gap-2 overflow-y-auto sm:grid-cols-2" x-show="view === 'card' && hasResults" x-cloak>
                <template x-for="(item, index) in items" :key="item.sku">
                    <button type="button"
                            class="rounded-lg border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-60"
                            :class="[
                                item.sellable ? 'bg-canvas' : 'bg-canvas/60',
                                index === activeIndex
                                    ? 'border-primary ring-2 ring-primary/30'
                                    : 'border-border-subtle hover:border-primary',
                            ]"
                            :disabled="!item.sellable"
                            @click="choose(item)">
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-label-md"
                                  :class="item.ownership === 'TITIP'
                                      ? 'border-titip-border bg-titip-bg text-titip-text'
                                      : 'border-pribadi-border bg-pribadi-bg text-pribadi-text'">
                                <span x-text="item.ownership"></span>
                            </span>
                            <span class="text-body-sm font-semibold tabular-nums text-text-strong" x-text="format.rupiah(item.price)"></span>
                        </div>
                        {{-- Seri dan rak sudah dikirim endpoint sejak awal, tapi tidak
                             pernah ditampilkan. keduanya yang paling sering
                             membedakan dua barang yang namanya mirip, dan meraba
                             rak lebih cepat daripada membaca spec. --}}
                        <p class="mt-2 text-body-md font-medium text-text-strong" x-text="item.name"></p>
                        <div class="mt-1 space-y-0.5 text-label-sm text-text-muted">
                            <div x-show="item.series || item.color || item.year">
                                <span x-show="item.series">Seri: <span class="text-text-strong" x-text="item.series"></span></span>
                                <span x-show="item.color"> · Warna: <span class="text-text-strong" x-text="item.color"></span></span>
                                <span x-show="item.year"> · Tahun: <span class="text-text-strong" x-text="item.year"></span></span>
                            </div>
                        </div>
                        <p class="font-mono text-label-sm text-text-subtle">
                            <span x-text="item.sku"></span>
                            <span x-show="item.rack" x-text="' · ' + item.rack"></span>
                            <span x-show="item.consignor" x-text="' · ' + item.consignor"></span>
                        </p>
                        <p class="mt-1 text-label-sm">
                            <span x-text="'Sisa ' + item.stock" :class="item.stock > 0 ? 'text-text-muted' : 'text-error-text'"></span>
                            <span x-show="item.pendingLabels > 0" class="text-warning-text"
                                  x-text="' · label belum keluar (' + item.pendingLabels + ')'"></span>
                            <span x-show="!item.sellable" class="text-error-text" x-text="' · ' + format.text(item.status)"></span>
                        </p>
                    </button>
                </template>
            </div>

            {{-- Tabel, untuk kasir yang sudah tahu persis barang yang dicari dan
                 hanya ingin membandingkan beberapa baris. --}}
            <div class="mt-4 max-h-96 overflow-auto" x-show="view === 'table' && hasResults" x-cloak>
                <table class="w-full min-w-max text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="px-3 py-2 font-semibold">Produk</th>
                            <th class="px-3 py-2 text-center font-semibold">Milik</th>
                            <th class="px-3 py-2 text-center font-semibold">Sisa</th>
                            <th class="px-3 py-2 text-right font-semibold">Harga</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        <template x-for="(item, index) in items" :key="item.sku">
                            {{-- Barisnya sendiri yang diklik, bukan hanya tombolnya:
                                 di mode tabel kasir membandingkan baris, dan
                                 membidik tombol kecil di ujung baris justru memperlambat
                                 hal yang paling sering dilakukan. --}}
                            <tr class="transition"
                                :class="[
                                    item.sellable ? 'cursor-pointer hover:bg-canvas' : 'cursor-default opacity-60',
                                    index === activeIndex ? 'bg-primary-soft' : '',
                                ]"
                                @click="item.sellable && choose(item)">
                                <td class="px-3 py-2">
                                    <p class="text-body-md font-medium text-text-strong" x-text="item.name"></p>
                                    <p class="text-label-sm text-text-muted" x-show="item.series" x-text="item.series"></p>
                                    <p class="font-mono text-label-sm text-text-subtle">
                                        <span x-text="item.sku"></span>
                                        <span x-show="item.rack" x-text="' · ' + item.rack"></span>
                                    </p>
                                </td>
                                <td class="px-3 py-2 text-center text-label-md text-text-muted">
                                    <span x-text="item.ownership"></span>
                                    <span x-show="item.pendingLabels > 0" class="block text-warning-text"
                                          x-text="'label tertunda'"></span>
                                    <span x-show="!item.sellable" class="block text-error-text"
                                          x-text="format.text(item.status)"></span>
                                </td>
                                <td class="px-3 py-2 text-center text-body-md tabular-nums" x-text="item.stock"></td>
                                <td class="px-3 py-2 text-right text-body-md font-semibold tabular-nums" x-text="format.rupiah(item.price)"></td>
                                {{-- Label saja, bukan tombol: barisnya yang jadi target
                                     dan dua target untuk satu aksi berarti satu klik
                                     yang terhitung dua kali. --}}
                                <td class="px-3 py-2 text-right">
                                    <span class="text-label-md font-semibold"
                                          :class="item.sellable ? 'text-primary' : 'text-text-subtle'"
                                          x-text="item.sellable ? 'Pilih' : 'Tidak bisa'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>
</div>