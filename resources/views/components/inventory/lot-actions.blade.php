@props(['row' => null, 'column' => null, 'value' => null, 'data' => []])

{{--
     Dua aksi dengan dua cara yang berbeda, dan perbedaannya penting.

     "Kartu" adalah tujuan: satu baris riwayat yang sudah ada halamannya, jadi
     tautan biasa -- bisa dibuka di tab baru, bisa disalin, bisa ditekan Enter
     oleh pembaca layar.

     "Pindah" adalah awal dari suatu tindakan: ia membutuhkan rak tujuan yang
     belum diketahui, jadi ia membuka dialog di halaman ini. URL patch-nya sudah
     dihitung server dan dibawa bersama peristiwa itu, sehingga template di
     bawah tidak perlu tahu bentuk route -- satu baris yang berubah di
     routes/web.php tidak akan membuat tombol ini diam-diam mengirim ke alamat
     yang salah.
--}}
<div class="flex justify-end gap-1">
    <a href="{{ route('inventory.kartu-stok', $row) }}" class="btn-ghost h-9 px-3 text-primary">Kartu</a>

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
