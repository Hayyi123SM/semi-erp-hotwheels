@use('App\Enums\ShiftStatus')
@use('App\Support\DeviceId')
@use('App\Support\Format')

<x-ui.page-header
    title="Shift Kasir"
    subtitle="Buka shift sebelum berjualan, tutup shift setelah menghitung uang di laci."
    :crumbs="['POS / Kasir', 'Shift Kasir']"
>
</x-ui.page-header>

{{--
    Dua bentuk dari satu halaman, dipilih oleh keadaan shift dan bukan oleh menu.

    Halaman ini sebelumnya menampilkan shift yang sudah ditutup lengkap dengan
    rekapnya -- jadi ia terlihat sudah jadi, padahal tidak ada satu pun angka di
    dalamnya yang berasal dari database. Sekarang bentuknya ditentukan `$shift`:
    ada shift yang masih berjalan berarti kasir sedang bekerja di depan laci dan
    yang ia butuhkan sekarang adalah rekap shift itu, bukan riwayat shift lama.
--}}
<div class="space-y-6">
    @if ($shift === null)
        {{-- ===================== Shift belum dibuka ===================== --}}
        <x-ui.section-card title="Buka Shift">
            <div class="max-w-xl space-y-5">
                <x-ui.banner tone="info">
                    Kas belum terhitung. Hitung uang di laci dulu, masukkan jumlahnya di bawah,
                    baru barang boleh mulai dijual. Setiap penjualan setelah titik ini ikut
                    dihitung dalam rekap shift ini.
                </x-ui.banner>

                <form method="POST" action="{{ route('pos.shift-kasir.open') }}" class="space-y-5">
                    @csrf

                    {{--
                        Perangkat ditampilkan, bukan diketik.

                        Nilainya dibaca dari session atau header, bukan dari field
                        form, karena form yang boleh menamai perangkat sendiri
                        membuat aturan "satu perangkat satu shift terbuka" bisa
                        dilewati dengan mengetik id lain. Kasir tidak pernah punya
                        alasan untuk mengubahnya, jadi field-nya memang tidak ada.
                    --}}
                    @php
                        $deviceId = DeviceId::current();
                    @endphp

                    <dl class="grid gap-3 rounded border border-border-subtle bg-canvas px-4 py-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-label-md text-text-muted">Kasir</dt>
                            <dd class="text-body-md font-medium text-text-strong">{{ auth()->user()?->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Perangkat</dt>
                            <dd class="text-body-md font-medium text-text-strong">{{ $deviceId ?? 'Tidak terdeteksi' }}</dd>
                            @unless ($deviceId)
                                {{-- Bukan sekadar teks kosong. Perangkat yang tidak terbaca
                                     berarti aturan "satu perangkat satu shift terbuka" tidak
                                     bisa dicegah, dan kasir perlu tahu itu sebelum ia
                                     mulai berjualan -- bukan setelah shift ganda
                                     benar-benar terjadi. --}}
                                <p class="text-label-sm text-warning-text">
                                    Perangkat tidak terbaca, jadi halaman ini tidak bisa mencegah shift ganda di satu mesin.
                                </p>
                            @endunless
                        </div>
                    </dl>

                    <x-ui.field label="Uang pembuka di laci" name="opening_cash" required
                                hint="Nominal yang benar-benar ada di laci sebelum penjualan pertama. Boleh 0.">
                        <x-ui.money-input name="opening_cash" required :value="old('opening_cash', '')" />
                        @error('opening_cash')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                    </x-ui.field>

                    <x-ui.field label="Catatan" name="notes"
                                hint="Isi kalau uang pembuka tidak cocok dengan nominal yang biasa dipakai.">
                        <textarea id="notes" name="notes" rows="2"
                                  class="input-base @error('notes') border-error-border @enderror"
                                  placeholder="Contoh: laci diisi dari Department Store, jadi Rp200.000.">{{ old('notes') }}</textarea>
                        @error('notes')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                    </x-ui.field>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-primary">Buka Shift</button>
                        <a href="{{ route('pos.kasir') }}" class="btn-secondary">Ke Halaman Kasir</a>
                    </div>
                </form>
            </div>
        </x-ui.section-card>
    @else
        {{-- ===================== Shift sedang berjalan ===================== --}}
        <div class="grid gap-5 lg:grid-cols-3">
            <x-ui.stat-card label="Uang pembuka" :value="Format::rupiah($summary->openingCash)" />
            <x-ui.stat-card label="Tunai terjual" :value="Format::rupiah($summary->cashReceived)"
                            :delta="$summary->saleCount.' transaksi · '.Format::rupiah($summary->saleTotal)"
                            delta-tone="info" />
            <x-ui.stat-card label="Seharusnya ada di laci" :value="Format::rupiah($summary->expectedCash)" />
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-ui.section-card title="Tutup Shift">
                <form method="POST" action="{{ route('pos.shift-kasir.close', $shift) }}"
                      x-data="shiftCloseForm({
                          endpoint: '{{ route('pos.shift-kasir.close', $shift) }}',
                          redirectTo: '{{ route('pos.shift-kasir') }}',
                          context: 'pos.close-shift',
                          expectedCash: @js($summary->expectedCash),
                          threshold: @js($cashThreshold),
                          isOwner: @js(auth()->user()?->isOwner() ?? false),
                      })"
                      @submit="window.pin ? submit($event) : null"
                      class="space-y-5">
                    @csrf

                    {{-- Disimpan di DOM supaya form tetap bisa dikirim tanpa
                         Alpine. `shift-close-form.js` tetap menulis token dari
                         state-nya sendiri, karena `x-model` baru sampai ke sini
                         setelah flush. --}}
                    <input type="hidden" name="pin_token" x-model="token" value="">

                    <dl class="grid gap-3 rounded border border-border-subtle bg-canvas px-4 py-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-label-md text-text-muted">Shift dibuka</dt>
                            <dd class="text-body-md font-medium text-text-strong">{{ Format::datetime($shift->opened_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Perangkat</dt>
                            <dd class="text-body-md font-medium text-text-strong">{{ $shift->device_id ?? 'Tidak terdeteksi' }}</dd>
                        </div>
                    </dl>

                    {{--
                        `x-money` memindahkan `name` ke input tersembunyi di
                        sebelahnya dan mengisi yang tersembunyi itu dengan angka
                        polos, jadi yang sampai ke validasi tetap `1600000` bukan
                        `1.600.000`. `x-model` membaca field yang sama untuk
                        pratinjau selisihnya di bawah, dan `shift-close-form.js`
                        membuang karakter selain digit -- jadi keduanya melihat angka
                        yang sama meski fieldnya sudah dikelompokkan.
                    --}}
                    <x-ui.field label="Uang penutup di laci" name="closing_cash" required
                                hint="Nominal yang benar-benar ada di laci sekarang, termasuk kembalian.">
                        <input type="text" name="closing_cash"
                               inputmode="numeric" autocomplete="off"
                               x-money="money"
                               x-model="closing"
                               value="{{ old('closing_cash', '') }}"
                               class="input-base tabular-nums @error('closing_cash') border-error-border @enderror"
                               required>
                        @error('closing_cash')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                    </x-ui.field>

                    {{--
                        Pratinjau selisih, hanya untuk dibaca kasir.

                        Hitungannya diulang di sini, bukan dikirim ulang dari
                        server untuk setiap ketikan: server hanya menjawab sekali
                        lagi setelah form dikirim, sedangkan kasir yang sedang
                        menghitung uang perlu tahu sekarang apakah angkanya cocok,
                        bukan setelah menekan tombol.

                        Yang menentukan apakah PIN Owner diminta tetap server, dari
                        rekap yang dihitung ulang dari database. Kalau pratinjau ini
                        yang menentukan, mengetik angka yang kebetulan cocok akan
                        melewati persetujuan yang seharusnya diminta -- dan itu
                        persis yang harus dicegah.
                    --}}
                    <div x-show="difference !== null" x-cloak>
                        <div x-show="difference === 0">
                            <x-ui.banner tone="success">
                                <span x-text="'Uang di laci cocok dengan rekap: ' + format.rupiah(expectedCash) + '.'"></span>
                            </x-ui.banner>
                        </div>
                        <div x-show="difference !== 0" x-cloak>
                            <x-ui.banner tone="warning">
                                <span x-text="
                                    'Selisih ' + format.rupiah(Math.abs(difference)) + ': uang di laci '
                                    + (difference < 0 ? 'kurang' : 'lebih')
                                    + ' dari yang seharusnya. Angka ini akan tercatat atas namamu.'
                                "></span>
                            </x-ui.banner>
                        </div>
                        <div x-show="needsOwnerPin()" x-cloak class="mt-2">
                            <x-ui.banner tone="warning">
                                <span x-text="
                                    'Selisih ini melewati ambang ' + format.rupiah(threshold)
                                    + ', jadi akan diminta PIN Owner saat menutup shift.'
                                "></span>
                            </x-ui.banner>
                        </div>
                    </div>

                    <x-ui.field label="Catatan" name="notes"
                                hint="Isi kalau ada selisih, supaya selisihnya punya penjelasan.">
                        <textarea id="notes" name="notes" rows="2"
                                  class="input-base @error('notes') border-error-border @enderror"
                                  placeholder="Contoh: kembalian Rp5.000 untuk nota yang dibatalkan.">{{ old('notes') }}</textarea>
                        @error('notes')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                    </x-ui.field>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-primary" :disabled="!canSubmit()">
                            <span x-show="!busy">Tutup Shift</span>
                            <span x-show="busy" x-cloak>Menyimpan...</span>
                        </button>
                        <a href="{{ route('pos.kasir') }}" class="btn-secondary">Lanjut ke Kasir</a>
                    </div>

                    <div x-show="error !== ''" x-cloak>
                        <x-ui.banner tone="error"><span x-text="error"></span></x-ui.banner>
                    </div>
                </form>
            </x-ui.section-card>

            <x-ui.section-card title="Rekap per Metode">
                <div class="flex items-center justify-between">
                    <p class="text-label-sm text-text-muted">{{ $summary->saleCount }} transaksi</p>
                    <p class="text-label-sm text-text-subtle tabular-nums">{{ Format::rupiah($summary->saleTotal) }}</p>
                </div>

                {{-- Metode yang nol ikut ditampilkan. Kasir yang melihat
                     "QRIS Rp0" tahu tidak ada pembayaran QRIS; kasir yang tidak
                     melihat QRIS sama sekali tidak tahu apa yang dilewati, dan saat
                     menutup shift dengan uang kurang ia akan mencari penjelasan di
                     tempat yang salah. --}}
                <ul class="mt-4 divide-y divide-border-subtle">
                    @foreach ($summary->methods as $row)
                        <li class="flex items-center justify-between py-3">
                            <span class="text-body-md {{ $row['total'] > 0 ? 'text-text-strong' : 'text-text-muted' }}">{{ $row['label'] }}</span>
                            <span class="text-label-sm text-text-muted tabular-nums">{{ $row['count'] }} nota</span>
                            <span class="text-body-md font-semibold tabular-nums {{ $row['total'] > 0 ? 'text-text-strong' : 'text-text-subtle' }}">
                                {{ Format::rupiah($row['total']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 border-t border-border-subtle pt-4">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <dt class="text-label-md text-text-muted">Uang pembuka</dt>
                            <dd class="text-body-md font-semibold text-text-strong tabular-nums">{{ Format::rupiah($summary->openingCash) }}</dd>
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Tunai terjual</dt>
                            <dd class="text-body-md font-semibold text-text-strong tabular-nums">{{ Format::rupiah($summary->cashReceived) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-label-sm text-text-subtle">
                        Hanya penjualan yang sudah dibayar yang dihitung. Transaksi void dan yang
                        masih menunggu keputusan manusia tidak masuk rekap kas.
                    </p>
                </div>
            </x-ui.section-card>
        </div>
    @endif

    {{-- ===================== Riwayat shift ===================== --}}
    @if ($recentShifts->isNotEmpty())
        <x-ui.section-card title="Shift Terakhir" :pad="false">
            <div class="table-scroll">
                <table class="w-full min-w-[860px] text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="px-6 py-3 font-semibold">Kasir</th>
                            <th class="px-6 py-3 font-semibold">Perangkat</th>
                            <th class="px-6 py-3 font-semibold">Dibuka</th>
                            <th class="px-6 py-3 font-semibold">Ditutup</th>
                            <th class="px-6 py-3 text-right font-semibold">Pembuka</th>
                            <th class="px-6 py-3 text-right font-semibold">Penutup</th>
                            <th class="px-6 py-3 text-right font-semibold">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        @foreach ($recentShifts as $row)
                            <tr class="transition hover:bg-canvas">
                                <td class="px-6 py-3">
                                    <p class="text-body-md text-text-strong">{{ $row->user?->name ?? 'Tidak diketahui' }}</p>
                                    @if ($row->status === ShiftStatus::Open)
                                        <x-ui.badge-status type="info" dot>BERJALAN</x-ui.badge-status>
                                    @endif
                                </td>
                                <td class="px-6 py-3 font-mono text-label-sm text-text-muted">{{ $row->device_id ?? '—' }}</td>
                                <td class="px-6 py-3 text-label-sm text-text-muted">{{ Format::datetime($row->opened_at) }}</td>
                                <td class="px-6 py-3 text-label-sm text-text-muted">{{ Format::datetime($row->closed_at) }}</td>
                                <td class="px-6 py-3 text-right tabular-nums text-label-sm text-text-muted">{{ Format::rupiah($row->opening_cash) }}</td>
                                <td class="px-6 py-3 text-right tabular-nums text-label-sm text-text-muted">
                                    {{ $row->closing_cash === null ? '—' : Format::rupiah($row->closing_cash) }}
                                </td>
                                <td class="px-6 py-3 text-right tabular-nums text-label-sm">
                                    @if ($row->cash_diff === null)
                                        <span class="text-text-subtle">—</span>
                                    @elseif ($row->cash_diff === 0)
                                        <span class="text-success-text">Rp0</span>
                                    @else
                                        {{-- Selisih yang sudah diotorisasi Owner ditandai
                                             berbeda dari yang tidak. Membedakan keduanya
                                             membuat laporan kas bisa memisahkan "sudah
                                             ditandatangani" dari "belum dijelaskan"
                                             tanpa harus membuka audit log. --}}
                                        <span class="font-medium text-warning-text">{{ Format::rupiah($row->cash_diff) }}</span>
                                        @if ($row->cash_diff_approved_by !== null)
                                            <x-ui.badge-status type="warning">disetujui Owner</x-ui.badge-status>
                                        @elseif ($row->closed_by !== null && $row->closed_by !== $row->user_id)
                                            <x-ui.badge-status type="neutral">ditutup {{ $row->closedBy?->name ?? 'kasir lain' }}</x-ui.badge-status>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.section-card>
    @endif
</div>
