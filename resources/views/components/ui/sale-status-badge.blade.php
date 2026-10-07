@props(['row' => null, 'value' => null])

{{--
    Badge status nota POS.

    Bukan komponen mandiri: `x-ui.badge-status` sudah menangani warna, ikon, dan
    bentuk badge. Yang belum ada adalah keputusan "warna apa untuk kode status
    ini", dan itu milik enum `SaleStatus`, bukan milik template. Kalau dipetakan
    di sini, `Voided` dan `SyncConflict` akan punya warna berbeda dari semua
    tempat lain yang memakainya.

    Status yang butuh penjelasan -- bentrok sinkron dan ketentuan basi -- mendapat
    satu baris kecil di bawah badge. Keduanya sudah tercatat dan uangnya benar;
    yang belum jelas cuma apakah angka di nota itu masih sah. Tanpa keterangan itu
    kasir akan melihat dua badge kuning tanpa tahu harus telefon siapa.
--}}

@if ($row)
    <div class="flex flex-col items-start gap-1">
        <x-ui.badge-status :type="$row->status->type()">{{ $row->status->label() }}</x-ui.badge-status>

        @if ($row->flagSummary())
            <span class="text-label-sm text-text-subtle">{{ $row->flagSummary() }}</span>
        @endif
    </div>
@else
    <span class="text-text-subtle">{{ \App\Support\Format::EMPTY }}</span>
@endif
