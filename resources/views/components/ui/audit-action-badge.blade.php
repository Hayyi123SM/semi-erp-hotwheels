@props(['row' => null, 'value' => null])

{{--
    Badge aksi audit, khusus untuk laporan ini.

    Bukan komponen mandiri: `x-ui.badge-status` sudah menangani warna, ikon,
    dan bentuk badge. Yang belum ada adalah keputusan "warna apa untuk kode
    aksi ini", dan itu milik enum, bukan milik template. `badge-status`
    dipanggil tanpa slot supaya label diambil dari baris yang sama -- dua
    sumber label, satu di controller dan satu di view, akan berbeda begitu
    salah ketik.

    Kode aksi yang tidak dikenal juga lewat sini, dengan warna netral: baris
    itu tetap terbaca, tanpa diperlakukan seperti bahaya.
--}}

@if ($row)
    <x-ui.badge-status :type="$row->action_type">{{ $row->action_label }}</x-ui.badge-status>
@else
    <span class="text-text-subtle">{{ \App\Support\Format::EMPTY }}</span>
@endif