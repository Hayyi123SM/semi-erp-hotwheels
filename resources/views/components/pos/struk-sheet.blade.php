@use('App\Support\Format')

{{--
    Satu sumber isi struk POS untuk tiga medium sekaligus.

    Halaman kasir menanamkan HTML ini ke dalam dialog "bayar selesai" sebagai
    pratinjau; halaman `pos/struk-cetak` merendernya di dalam dokumen cetak;
    dan `PosStrukRenderer` menyalin layoutnya baris demi baris ke byte ESC/POS.
    Isi ditulis sekali di sini supaya angka di layar dan angka di kertas tidak
    pernah bisa berbeda arah -- alasan yang sama seperti `consignment-receipt`.
--}}
<div class="receipt-sheet {{ $sheet->paper()->isThermal() ? 'receipt-sheet--thermal' : 'receipt-sheet--a4' }} bg-white border border-slate-200 shadow-sm print:border-0 print:shadow-none {{ $embedded ?? false ? 'receipt-sheet--embedded' : '' }}"
     style="width: {{ $sheet->page()['width'] }}; max-width: 100%;">

    <p class="receipt-store">{{ $sheet->storeName() }}</p>
    <p class="receipt-title">Nota Kasir</p>

    <hr class="receipt-rule">

    {{-- Kepala dokumen. Urutannya mengikuti nota kertas yang dipakai kasir
         membandingkan layar dengan kertas: nomor, waktu, siapa, shift mana. --}}
    <div class="receipt-rows">
        <div class="receipt-row">
            <span class="receipt-row__key">No. nota</span>
            <span class="receipt-row__value">{{ $sheet->docNo() }}</span>
        </div>
        <div class="receipt-row">
            <span class="receipt-row__key">Waktu</span>
            <span class="receipt-row__value">{{ $sheet->soldOn() }}</span>
        </div>
        <div class="receipt-row">
            <span class="receipt-row__key">Kasir</span>
            <span class="receipt-row__value">{{ $sheet->cashierName() }}</span>
        </div>
        <div class="receipt-row">
            <span class="receipt-row__key">Shift</span>
            <span class="receipt-row__value">{{ $sheet->shiftNo() }}</span>
        </div>
    </div>

    <hr class="receipt-rule">

    {{-- Barang. Satu baris per SKU dengan nama di bawah kode, lalu qty × harga
         di kiri dan total baris di kanan -- bentuk yang sama dengan nota. --}}
    <ul class="receipt-lines">
        @forelse ($sheet->lines() as $line)
            <li class="receipt-line">
                <span class="receipt-line__sku">{{ $line['sku'] }}</span>
                <span class="receipt-line__name">{{ $line['name'] }}</span>
                <span class="receipt-line__meta">
                    <span class="receipt-line__qty">
                        {{ $line['qty'] }} × {{ Format::rupiah($line['price']) }}
                    </span>
                    <span>{{ Format::rupiah($line['line_total']) }}</span>
                </span>
            </li>
        @empty
            <li class="receipt-line">
                <span class="receipt-row__key">Tidak ada barang tercatat pada nota ini.</span>
            </li>
        @endforelse
    </ul>

    {{-- Ringkasan uang. Diskon hanya muncul kalau ada; kalau bukan nol,
         ditulis dengan tanda kurang persis seperti kondisi di struk kertas. --}}
    <div class="receipt-totals">
        <div class="receipt-row">
            <span class="receipt-row__key">Subtotal</span>
            <span class="receipt-row__value">{{ Format::rupiah($sheet->subtotal()) }}</span>
        </div>
        @if ($sheet->discountTotal() > 0)
            <div class="receipt-row">
                <span class="receipt-row__key">Diskon</span>
                <span class="receipt-row__value">-{{ Format::rupiah($sheet->discountTotal()) }}</span>
            </div>
        @endif
        <div class="receipt-row receipt-row--total">
            <span class="receipt-row__key">Total</span>
            <span class="receipt-row__value">{{ Format::rupiah($sheet->total()) }}</span>
        </div>
    </div>

    <hr class="receipt-rule">

    {{-- Pembayaran. Referensi QRIS/EDC (kalau ada) di kiri, nominal di kanan. --}}
    <ul class="receipt-lines">
        @foreach ($sheet->payments() as $payment)
            <li class="receipt-line">
                <span class="receipt-line__sku">{{ $payment['method'] }}</span>
                <span class="receipt-line__meta">
                    <span>
                        @if ($payment['reference'] !== null && trim($payment['reference']) !== '')
                            Ref {{ $payment['reference'] }}
                        @endif
                    </span>
                    <span>{{ Format::rupiah($payment['amount']) }}</span>
                </span>
            </li>
        @endforeach
    </ul>

    {{-- `changeDue` null hanya untuk cetakan ulang yang sudah tidak tahu uang
         yang dipegang kasir. Layar kasir selalu punya angkanya. --}}
    @if ($sheet->changeDue !== null)
        <div class="receipt-row receipt-row--change">
            <span class="receipt-row__key">Kembalian</span>
            <span class="receipt-row__value">{{ Format::rupiah($sheet->changeDue) }}</span>
        </div>
    @endif

    <p class="receipt-footnote">Terima kasih atas kunjungan Anda.</p>

    <p class="receipt-printed">
        Dicetak: {{ $sheet->printedAt() }} oleh {{ $sheet->printedByName() }}
    </p>
</div>