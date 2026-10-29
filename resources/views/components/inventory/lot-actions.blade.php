@props(['row' => null, 'column' => null, 'value' => null, 'data' => []])

{{--
    Tiga aksi dengan dua cara yang berbeda, dan perbedaannya penting.

    "Kartu" adalah tujuan: satu baris riwayat yang sudah ada halamannya, jadi
    tautan biasa -- bisa dibuka di tab baru, bisa disalin, bisa ditekan Enter
    oleh pembaca layar.

    "Kurangi", "Tambah", dan "Pindah" adalah awal dari suatu tindakan: ketiganya
    membutuhkan masukan yang belum diketahui, jadi ketiganya membuka dialog di
    halaman ini. URL-nya sudah dihitung server dan dibawa bersama peristiwa
    itu, sehingga template di bawah tidak perlu tahu bentuk route -- satu
    baris yang berubah di routes/web.php tidak akan membuat tombol ini
    diam-diam mengirim ke alamat yang salah.

    "Kurangi" hanya muncul untuk lot yang masih tersedia dan berisi unit.
    "Tambah" muncul untuk lot tersedia maupun lot habis, karena lot kosong
    masih bisa dipulihkan bila ternyata stoknya salah dihitung. Lot yang sudah
    keluar dari gudang tidak menampilkan keduanya.
--}}
<div class="flex flex-wrap justify-end gap-1">
    <a href="{{ route('inventory.kartu-stok', $row) }}" class="btn-ghost h-9 px-3 text-primary">Kartu</a>

    @if ($row?->canBeReduced())
        <button type="button"
                class="btn-ghost h-9 px-3 text-error-text"
                @click="$dispatch('live-stock:adjust', {
                    url: @js(route('inventory.live-stock.koreksi', $row)),
                    sku: @js($row->sku),
                    expectedQty: @js($row->qty_on_hand),
                    direction: 'kurangi',
                })">
            Kurangi
        </button>
    @endif

    @if ($row?->canBeIncreased())
        <button type="button"
                class="btn-ghost h-9 px-3 text-success-text"
                @click="$dispatch('live-stock:adjust', {
                    url: @js(route('inventory.live-stock.koreksi', $row)),
                    sku: @js($row->sku),
                    expectedQty: @js($row->qty_on_hand),
                    direction: 'tambah',
                })">
            Tambah
        </button>
    @endif

    @if ($data['canTransfer'] ?? false)
        <button type="button"
                class="btn-ghost h-9 px-3"
                @click="$dispatch('live-stock:transfer', {
                    url: @js(route('inventory.live-stock.rak', $row)),
                    sku: @js($row->sku),
                    from: @js($row->rack?->code),
                })">
            Pindah
        </button>
    @endif
</div>
