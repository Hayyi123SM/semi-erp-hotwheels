<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cetak Label' }}</title>
    {{-- Hanya stylesheet label. Halaman ini sengaja tidak memuat app.css:
         yang dicetak hanya labelnya, dan seluruh layout aplikasi tidak boleh
         ikut terbawa ke kertas. --}}
    @vite(['resources/css/label.css'])
    @if (($pageSize ?? null))
        {{--
            Ukuran kertas untuk dialog cetak, dalam CSS.

            Sumbernya satu nilai, bukan dua angka yang dihitung pemanggil: mode
            gulungan memakai ukuran label sebagai ukuran halaman, mode stiker
            memakai ukuran media kertas. Dua sumber angka membuat halaman ini bisa
            punya `@page` 3x2 cm sementara isinya grid 100x150 mm -- dialog
            cetak lalu menawarkan kertas 3x2 cm untuk 48 stiker, dan yang terjadi
            di printer bukan yang tertulis di badge.

            Tanpa aturan ini, browser memakai ukuran kertas yang terakhir dipilih
            pengguna (biasanya A4) dan isu "ukuran tidak sesuai" tidak pernah
            muncul: label 3x2 cm tetap tercetak 3x2 cm, hanya posisinya meleset
            dan operator tidak bisa mengukur gauge printer dari hasilnya.

            `margin: 0` wajib: margin bawaan browser membuat label bergeser, dan
            pemotong thermal memotong tepat di tepi.
        --}}
        <style>
            @page {
                size: {{ $pageSize }};
                margin: 0;
            }
        </style>
    @endif
</head>
<body>
    {{ $slot }}
</body>
</html>
