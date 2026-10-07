@use('App\Services\Inventory\ReprintLimit')
@use('App\Services\Pos\PosSettings')
@use('App\Support\Format')

<x-ui.page-header
    title="Parameter Sistem"
    subtitle="Angka yang dibaca server saat kasir bekerja, bukan catatan yang tidak dibaca siapa pun."
    :crumbs="['Pengaturan', 'Parameter Sistem']"
>
</x-ui.page-header>

{{--
    Halaman ini sebelumnya penuh kontrol yang tidak melakukan apa pun: input tanpa
    `name`, tombol simpan yang hanya memunculkan toast, dan toggle yang hidup di
    state Alpine lalu hilang saat halaman dimuat ulang. Semuanya terlihat bisa
    diubah dan semuanya terlihat tersimpan.

    Sekarang aturannya satu: setiap kontrol di halaman ini adalah nilai yang
    dibaca server. Kalau belum ada kodenya yang membacanya, barisnya ditampilkan
    sebagai nilai tetap yang bisa dibaca -- bukan sebagai input. Form yang terlihat
    bisa diubah tapi tidak mengubah apa pun adalah cara paling mahal untuk membuat
    orang percaya pengaturan sudah berlaku.
--}}
<div class="space-y-6">
    <x-ui.section-card title="Kasir (POS)">
        @can('owner-only')
            <form method="POST" action="{{ route('setting.parameter.update') }}" class="space-y-5">
                @csrf
                @method('PUT')

                {{--
                    Tiga nilai, satu form.

                    Dipisah dari form yang lain di halaman Pengaturan karena ketiganya
                    dibaca di saat yang sama: kasir memotong harga, kasir menutup
                    shift, kasir mencetak struk. Jadi mengubah satu di sini
                    bersamaan dengan yang lain tetap menghasilkan satu keadaan yang
                    bisa dibaca -- bukan tiga keadaan yang bisa berbeda dan tidak ada
                    yang tahu mana yang berlaku.
                --}}
                <div class="grid gap-5 lg:grid-cols-2">
                    {{-- `mode="rate"`, bukan uang: persentase dibaca dengan koma
                         untuk desimalnya, dan `Format::rate()` diterjemahkan ke
                         bentuk itu di sini. Field uang akan mengubah `12,5`
                         menjadi `125`. --}}
                    <x-ui.field label="Batas diskon kasir" name="staff_discount_limit_percent" required
                                hint="Potongan harga maksimal (%) yang boleh diberikan kasir tanpa PIN Owner. 0 berarti kasir tidak boleh memberi diskon sama sekali.">
                        <x-ui.money-input name="staff_discount_limit_percent" mode="rate" required
                                          :value="$posSettings['staff_discount_limit_percent']"
                                          :min="0" :max="PosSettings::MAX_DISCOUNT_PERCENT" />
                        @error('staff_discount_limit_percent')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                        <p class="mt-1 text-label-sm text-text-subtle">
                            Bawaannya {{ PosSettings::DEFAULT_STAFF_DISCOUNT_LIMIT_PERCENT }}%.
                        </p>
                    </x-ui.field>

                    <x-ui.field label="Ambang selisih kas" name="cash_difference_threshold" required
                                hint="Selisih antara uang di laci dan rekap penjualan yang perlu persetujuan Owner. 0 berarti setiap tutup shift perlu persetujuan.">
                        <x-ui.money-input name="cash_difference_threshold" required
                                          :value="$posSettings['cash_difference_threshold']" />
                        @error('cash_difference_threshold')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                        <p class="mt-1 text-label-sm text-text-subtle">
                            Bawaannya {{ Format::rupiah(PosSettings::DEFAULT_CASH_DIFFERENCE_THRESHOLD) }}.
                            Uang yang kurang sebanyak yang lebih tidak dibedakan: keduanya
                            sama-sama berarti uang yang tidak ada di laci.
                        </p>
                    </x-ui.field>

                    <div class="rounded-lg border border-border-subtle bg-canvas px-4 py-3">
                        <p class="text-label-md text-text-muted">Kertas struk dokumen</p>
                        <p class="text-body-md font-medium text-text-strong">{{ $paper->label() }}</p>
                        <p class="mt-1 text-label-sm text-text-subtle">
                            Kertas kini diatur global di halaman
                            <a href="{{ route('setting.perangkat') }}" class="font-medium text-text-link underline">Perangkat</a>
                            &mdash; dipakai bukti terima titipan dan struk kasir.
                        </p>
                    </div>
                </div>

                <x-ui.banner tone="info">
                    Berlaku untuk shift yang dibuka <strong>setelah</strong> disimpan. Shift yang
                    sedang berjalan sudah memakai angka yang berlaku ketika shift itu dibuka,
                    jadi tutup shift yang sedang dilakukan tidak akan berubah di tengah jalan.
                </x-ui.banner>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary">Simpan Parameter POS</button>
                </div>
            </form>
        @else
            {{-- Staff boleh tahu batas mana yang berlaku: bukan untuk mengubahnya,
                 tapi untuk tidak mencari di tempat lain saat kasir bertanya kenapa
                 diskonnya ditolak. --}}
            <dl class="grid gap-5 lg:grid-cols-3">
                <div>
                    <dt class="text-label-md text-text-muted">Batas diskon kasir</dt>
                    <dd class="text-headline-sm font-semibold text-text-strong tabular-nums">{{ $posSettings['staff_discount_limit_percent'] }}%</dd>
                    <p class="text-label-sm text-text-subtle">Di atas ini perlu PIN Owner.</p>
                </div>
                <div>
                    <dt class="text-label-md text-text-muted">Ambang selisih kas</dt>
                    <dd class="text-headline-sm font-semibold text-text-strong tabular-nums">{{ Format::rupiah($posSettings['cash_difference_threshold']) }}</dd>
                    <p class="text-label-sm text-text-subtle">Di atas ini perlu PIN Owner.</p>
                </div>
                <div>
                    <dt class="text-label-md text-text-muted">Kertas struk dokumen</dt>
                    <dd class="text-headline-sm font-semibold text-text-strong">{{ $paper->label() }}</dd>
                    <p class="text-label-sm text-text-subtle">Diatur dari halaman Perangkat.</p>
                </div>
            </dl>
            <p class="text-label-sm text-text-subtle">Hanya Owner yang bisa mengubah pengaturan ini.</p>
        @endcan
    </x-ui.section-card>

    {{--
        Nilai tetap yang dibaca kode, ditampilkan apa adanya.

        Nilai re-print di sini dibaca dari `ReprintLimit`, bukan ditulis ulang
        sebagai angka di view. Kalau constants-nya berubah dan view ini masih
        menampilkan 3, halaman pengaturan jadi sumber informasi yang salah --
        dan itu persis halaman yang dipakai orang untuk memastikan aturan mana
        yang sedang berlaku.
    --}}
    <x-ui.section-card title="Kebijakan Operasional">
        <x-slot:actions>
            <span class="text-label-sm text-text-subtle">dibaca dari kode, belum bisa diubah dari layar</span>
        </x-slot:actions>
        <ul class="divide-y divide-border-subtle">
            <li class="flex items-center justify-between gap-4 py-3 first:pt-0">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Batas re-print label per lot per hari</p>
                    <p class="text-label-sm text-text-muted">Melebihi batas memerlukan PIN Owner. Dihitung dari percobaan cetak, bukan jumlah label.</p>
                </div>
                <p class="shrink-0 text-headline-sm font-semibold tabular-nums text-text-strong">
                    {{ ReprintLimit::DAILY_STAFF_LIMIT }}&times;
                </p>
            </li>
            <li class="flex items-center justify-between gap-4 py-3">
                <div>
                    <p class="text-body-md font-medium text-text-strong">SLA karantina</p>
                    <p class="text-label-sm text-text-muted">Umur karantina sebelum ditandai perlu pemeriksaan Owner.</p>
                </div>
                <x-ui.badge-status type="neutral">belum ada pengaturan</x-ui.badge-status>
            </li>
            <li class="flex items-center justify-between gap-4 py-3">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Umur SKU sebelum otomatis masuk daftar RTV</p>
                    <p class="text-label-sm text-text-muted">Titipan yang tidak terjual selama segini lama dipindahkan ke retur.</p>
                </div>
                <x-ui.badge-status type="neutral">belum ada pengaturan</x-ui.badge-status>
            </li>
            <li class="flex items-center justify-between gap-4 py-3 last:pb-0">
                <div>
                    <p class="text-body-md font-medium text-text-strong">Ambiguitas minimum barcode</p>
                    <p class="text-label-sm text-text-muted">Skor pencocokan di bawah ini dianggap ambigu, bukan tidak cocok.</p>
                </div>
                <x-ui.badge-status type="neutral">belum ada pengaturan</x-ui.badge-status>
            </li>
        </ul>
    </x-ui.section-card>
</div>
