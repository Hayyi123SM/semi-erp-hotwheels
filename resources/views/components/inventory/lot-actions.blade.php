@props(['row' => null, 'column' => null, 'value' => null, 'data' => []])

{{--
     Tiga aksi dengan dua cara yang berbeda, dan perbedaannya penting.

     "Kartu" adalah tujuan: satu baris riwayat yang sudah ada halamannya, jadi
     tautan biasa -- bisa dibuka di tab baru, bisa disalin, bisa ditekan Enter
     oleh pembaca layar.

     "Kurangi" dan "Pindah" adalah awal dari suatu tindakan: keduanya
     membutuhkan masukan yang belum diketahui, jadi keduanya membuka dialog di
     halaman ini. URL-nya sudah dihitung server dan dibawa bersama peristiwa
     itu, sehingga template di bawah tidak perlu tahu bentuk route -- satu
     baris yang berubah di routes/web.php tidak akan membuat tombol ini
     diam-diam mengirim ke alamat yang salah.

     "Kurangi" hanya muncul untuk lot yang masih tersedia dan berisi unit.
     Lot kosong atau yang sudah keluar dari gudang tidak punya stok untuk
     dikoreksi, dan menampilkan tombolnya hanya mengundang galat.
--}}
<div class="flex flex-wrap justify-end gap-1">
    <a href="{{ route('inventory.kartu-stok', $row) }}" class="btn-ghost h-9 px-3 text-primary">Kartu</a>

    @if ($row?->isSellable())
        <button type="button"
                class="btn-ghost h-9 px-3 text-error-text"
                @click="$dispatch('live-stock:reduce', {
                    url: @js(route('inventory.live-stock.kurangi', $row)),
                    sku: @js($row->sku),
                    expectedQty: @js($row->qty_on_hand),
                })">
            Kurangi
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
