@php
    /**
     * Data default untuk pratinjau skema, dikirim ke Alpine sekali di awal.
     *
     * Baris yang kosong tidak dikirim sebagai array JS yang dirangkai tangan, tapi
     * sebagai peta yang dibaca `inboundGrid`. Peta ini hanya untuk ditampilkan --
     * yang menentukan tetap server, yang menghitung ulang saat commit.
     */
    $consignorTerms = $consignors->mapWithKeys(fn ($consignor) => [
        $consignor->id => [
            'name' => $consignor->name,
            'schemeType' => $consignor->scheme_type?->value,
            'scheme_rate' => $consignor->scheme_rate !== null ? (float) $consignor->scheme_rate : null,
            'scheme_amount' => $consignor->scheme_amount,
            'discountPolicy' => $consignor->discount_policy?->value,
        ],
    ]);
@endphp

<x-ui.page-header
    title="Consignment In"
    subtitle="Penerimaan barang titipan (CNxx). Commit menghasilkan SKU CN01/CN02 dan label antrean cetak."
    :crumbs="['Inbound', 'Consignment In']"
>
    <x-slot:actions>
        <a href="{{ route('inbound.consignment-in.riwayat') }}" class="btn-secondary">Riwayat</a>
    </x-slot:actions>
</x-ui.page-header>

    {{-- Draft yang bisa dilanjutkan. Diletakkan di luar <form>: daftar ini
         punya form sendiri untuk membuang draft, dan form di dalam form tidak
         valid -- browser akan memindahkan form anak ke luar form induk dan
         token CSRF-nya ikut hilang. Isinya milik Staff yang sedang login saja,
         karena memuat harga dan skema penitip. --}}
    @if ($drafts->isNotEmpty())
        <x-ui.section-card>
            <h2 class="text-title-sm font-semibold text-text-strong">Draft yang belum di-commit</h2>
            <ul class="mt-3 divide-y divide-border-subtle">
                @foreach ($drafts as $savedDraft)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-body-sm">
                            <b class="text-text-strong">{{ $savedDraft->consignor?->name ?? 'Penitip belum dipilih' }}</b>
                            @if ($savedDraft->consignor)
                                <span class="text-text-subtle">({{ $savedDraft->consignor->consignor_code }})</span>
                            @endif
                            <span class="text-text-subtle">
                                &middot; {{ $savedDraft->items->sum('qty') }} unit &middot;
                                tersimpan {{ $savedDraft->saved_at?->diffForHumans() ?? 'sebentar tadi' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('inbound.consignment-in', ['draft' => $savedDraft->draft_id]) }}" class="btn-secondary">Lanjutkan</a>
                            <form method="POST" action="{{ route('inbound.consignment-in.drafts.destroy', $savedDraft->draft_id) }}"
                                  onsubmit="return confirm('Buang draft ini? Isi yang belum di-commit akan hilang.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost">Buang</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.section-card>
    @endif

<div
      x-data="inboundGrid({
          consignors: {{ Js::from($consignorTerms) }},
          productPrices: {{ Js::from($productPrices) }},
          isOwner: {{ auth()->user()?->isOwner() ? 'true' : 'false' }},
          lookupUrl: {{ Js::from($lookupUrl) }},
          rows: {{ Js::from($initialRows) }},
          itemErrors: {{ Js::from($errors->getBag('default')->getMessages()) }},
          pinToken: {{ Js::from($errors->has('pin_token') ? '' : old('pin_token', '')) }},
          resumeDraftId: {{ Js::from(request()->query('draft', '')) }},
          restorable: {{ $errors->isEmpty() ? 'true' : 'false' }},
          draftId: {{ Js::from(old('draft_id', '')) }},
          source: {{ Js::from(old('source', '')) }},
          notes: {{ Js::from(old('notes', '')) }},
          claimedQty: {{ Js::from(old('qty_claimed', '')) }},
          varianceNote: {{ Js::from(old('variance_note', '')) }},
      })"
      class="space-y-6"
      @keydown.esc.window="if(pickerOpen) closePicker()"
      @consignment-in:add-product.window="onProductPicked($event)"
      x-effect="document.body.style.overflow = pickerOpen ? 'hidden' : ''">
<form method="POST" action="{{ route('inbound.consignment-in.store') }}"
      class="space-y-6"
      @submit.prevent="onSubmit($event)">
    @csrf

    {{-- Token dari dialog PIN Owner, diteruskan lagi kalau server menolak
         commit atas alasan lain. Kalau `pin_token` sendiri yang ditolak, isian
         ini sengaja dibiarkan kosong di atas supaya dialog terbuka lagi dengan
         token yang baru. --}}
    <input type="hidden" name="pin_token" x-model="pinToken">

    {{-- `draft_id` ikut Submit supaya commit tahu dokumen mana yang di-commit.
         Kosong untuk form yang belum pernah disimpan -- commit tanpa draft
         tetap benar, hanya tidak mewarisi draft yang sudah ada. --}}
    <input type="hidden" name="draft_id" x-model="draftId">

    @if ($errors->any())
        <x-ui.banner tone="error">
            Form belum bisa dikirim. Periksa kembali isian, termasuk setiap baris grid di bawah.
        </x-ui.banner>
    @endif

    @if ($errors->has('pin_token'))
        <x-ui.banner tone="warning">
            {{ $errors->first('pin_token') }}
        </x-ui.banner>
    @endif

    <!-- Tier 1: Consignor Meta -->
    <div class="card card-pad">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field label="Penitip (Consignor)" name="consignor_id" required>
                <x-ui.searchable-select placeholder="— pilih penitip —" :invalid="$errors->has('consignor_id')">
                    {{-- `x-model` di sini yang memberi tahu grid profil penitip
                         yang sedang dipilih, supaya baris bisa memakainya sebagai
                         default skema. --}}
                    <select id="consignor_id" name="consignor_id" x-model="consignorId"
                            class="sr-only" required @focus="openPanel()" @keydown="onSearchKeydown($event)">
                        <option value="">— pilih penitip —</option>
                        @foreach ($consignors as $consignor)
                            <option value="{{ $consignor->id }}" @selected((int) old('consignor_id') === $consignor->id)>
                                {{ $consignor->name }} ({{ $consignor->consignor_code }})
                            </option>
                        @endforeach
                    </select>
                </x-ui.searchable-select>
                @error('consignor_id')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Tanggal Terima" name="consignment_date" required>
                <input id="consignment_date" type="date" name="consignment_date"
                       value="{{ old('consignment_date', now()->toDateString()) }}"
                       class="input-base @error('consignment_date') border-error-border @enderror" required>
                @error('consignment_date')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Referensi / Drop-off" name="source">
                <input id="source" name="source" value="{{ old('source') }}"
                       class="input-base @error('source') border-error-border @enderror" placeholder="opsional">
                @error('source')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Catatan" name="notes">
                <input id="notes" name="notes" value="{{ old('notes') }}"
                       class="input-base @error('notes') border-error-border @enderror" placeholder="opsional">
                @error('notes')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
        </div>

        {{-- Ditulis dari Alpine, bukan dari Blade: penitip yang dipilih diketahui
             form, dan baris ikut berubah saat pilihan itu berubah. --}}
        <p class="mt-4 text-label-sm text-text-subtle" x-show="consignor !== null">
            Default penitip:
            <b class="text-text-strong" x-text="consignor?.name"></b>
            <span x-text="consignor?.schemeType === 'PERCENTAGE'
                ? `${consignor?.scheme_rate}%`
                : `${consignor?.schemeType?.toLowerCase()} ${window.format.rupiah(consignor?.scheme_amount)}`"></span>
            · diskon ditanggung
            <b class="text-text-strong" x-text="consignor?.discountPolicy === 'STORE_BEARS' ? 'toko' : 'berdua'"></b>.
            Baris yang tidak diisi mengikuti default ini.
        </p>

        {{-- Selisih qty (FR-IB-13). Angka yang diklaim penitip disimpan terpisah
             dari yang dihitung Staff: `qty_received` adalah sumber kebenaran,
             dan begitu dua angka itu disatukan dalam satu kolom, selisihnya hilang
             tanpa jejak dan tidak bisa dicantumkan di e-receipt. --}}
        <div class="mt-4 rounded-lg border border-border-subtle bg-surface-low p-4" x-show="consignor !== null">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <label class="flex cursor-pointer items-center gap-3 text-body-sm text-text-strong">
                    <input type="checkbox" x-model="claimedEnabled" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                    Ada selisih dengan klaim penitip
                </label>

                <div class="flex items-center gap-2 text-body-sm text-text-subtle" x-show="claimedEnabled">
                    Dihitung
                    <b class="tabular-nums text-text-strong" x-text="totalQty()"></b>
                    · diklaim
                    {{-- `name` ikut berubah mengikuti checkbox. Kalau field-nya
                         tetap punya `name`, isian yang pernah diketik akan ikut
                         terkirim walau checkbox-nya sudah dimatikan -- dan
                         `qty_claimed` yang tak sengaja tersimpan akan dibaca
                         sebagai klaim penitip padahal tidak ada klaim. --}}
                    <input type="number" min="0" max="99999" x-model="claimedQty"
                           :name="claimedEnabled ? 'qty_claimed' : null"
                           class="input-base w-24 text-right tabular-nums @error('qty_claimed') border-error-border @enderror"
                           placeholder="0">
                    <span x-show="variance() !== 0" class="font-medium"
                          :class="variance() < 0 ? 'text-error-text' : 'text-warning-text'">
                        (<span x-text="variance() > 0 ? '+' : ''"></span><span class="tabular-nums" x-text="variance()"></span>)
                    </span>
                </div>
            </div>

            <div class="mt-3" x-show="claimedEnabled" x-cloak>
                <input type="text" x-model="varianceNote" :name="claimedEnabled ? 'variance_note' : null"
                       class="input-base w-full @error('variance_note') border-error-border @enderror"
                       placeholder="Catatan selisih, mis. 2 blister sobek, mis. tidak ada barang cacat.">
                @error('variance_note')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </div>

            <p class="mt-3 text-label-sm text-text-subtle" x-show="!claimedEnabled">
                Kalau jumlah fisik sama dengan klaim, biarkan kosong. Angka yang diklaim penitip akan diisi otomatis dengan hasil hitungan.
            </p>
        </div>
    </div>

    <!-- Tier 2: Batch Entry Grid -->
    <x-ui.section-card pad="false"
                      x-effect="scheduleSave()"
                      x-cloak>
        <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary" @click="openPicker()">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Produk
                </button>
            </div>
            <p class="text-label-sm text-text-subtle">Satu baris = satu SKU. Barang serupa dengan kondisi sama boleh digabung di satu baris.</p>
        </div>

        <div class="table-scroll">
            <table class="w-full min-w-[1400px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-3 py-3 font-semibold">Produk</th>
                        <th class="px-3 py-3 font-semibold">Harga List</th>
                        <th class="px-3 py-3 font-semibold">Skema</th>
                        <th class="px-3 py-3 font-semibold">Parameter</th>
                        <th class="px-3 py-3 font-semibold">Diskon</th>
                        <th class="px-3 py-3 text-center font-semibold">Qty</th>
                        <th class="px-3 py-3 font-semibold">Kondisi Card</th>
                        <th class="px-3 py-3 font-semibold">Kondisi Blister</th>
                        <th class="px-3 py-3 font-semibold">Rak</th>
                        <th class="px-3 py-3 text-right font-semibold">Fee Toko</th>
                        <th class="px-3 py-3 text-right font-semibold">Hak Penitip</th>
                        <th class="px-3 py-3 text-center font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    <template x-for="(row, index) in rows" :key="index">
                        <tr class="border-b border-border-subtle transition hover:bg-canvas"
                            :class="deviates(row) ? 'bg-warning-bg' : ''">
                            <td class="px-3 py-2">
                                <div class="min-w-[12rem] max-w-[16rem]">
                                    <input type="hidden" :name="`items[${index}][product_id]`" :value="row.product_id">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate text-body-sm font-medium text-text-strong"
                                              x-text="row.product_name || '—'"></span>
                                        <span x-show="row.casting_code"
                                              class="shrink-0 rounded border border-border-subtle bg-canvas px-1.5 py-0.5 font-mono text-label-sm text-text-subtle"
                                              x-text="row.casting_code"></span>
                                    </div>
                                    <p class="mt-1 text-label-sm text-error-text" x-show="errorFor(index, 'product_id') !== ''" x-text="errorFor(index, 'product_id')"></p>
                                </div>
                            </td>

                            <td class="px-3 py-2">
                                <input type="text" inputmode="numeric" x-model="row.list_price"
                                       :name="`items[${index}][list_price]`"
                                       :placeholder="priceFor(row) === null ? 'default' : (priceFor(row).toLocaleString('id-ID'))"
                                       class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-right tabular-nums"
                                       :class="errorFor(index, 'list_price') !== '' && 'border-error-border'">
                                <p class="mt-1 text-label-sm text-error-text" x-show="errorFor(index, 'list_price') !== ''" x-text="errorFor(index, 'list_price')"></p>
                            </td>

                            <td class="px-3 py-2">
                                <select x-model="row.scheme_type" :name="`items[${index}][scheme_type]`"
                                        class="w-36 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    <option value="">— default —</option>
                                    <option value="PERCENTAGE">Persentase</option>
                                    <option value="NETT">Nett</option>
                                    <option value="FLAT">Flat</option>
                                </select>
                            </td>

                            <td class="px-3 py-2">
                                <template x-if="row.scheme_type === 'PERCENTAGE'">
                                    <input type="text" inputmode="decimal" x-model="row.scheme_rate"
                                           :name="`items[${index}][scheme_rate]`"
                                           :placeholder="consignor?.scheme_rate != null ? String(consignor.scheme_rate) : '—'"
                                           class="w-28 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-right tabular-nums"
                                           :class="errorFor(index, 'scheme_rate') !== '' && 'border-error-border'">
                                    <p class="mt-1 text-label-sm text-error-text" x-show="errorFor(index, 'scheme_rate') !== ''" x-text="errorFor(index, 'scheme_rate')"></p>
                                </template>
                                <template x-if="row.scheme_type === 'NETT' || row.scheme_type === 'FLAT'">
                                    <input type="text" inputmode="numeric" x-model="row.scheme_amount"
                                           :name="`items[${index}][scheme_amount]`"
                                           :placeholder="consignor?.scheme_amount != null ? String(consignor.scheme_amount) : '—'"
                                           class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-right tabular-nums"
                                           :class="errorFor(index, 'scheme_amount') !== '' && 'border-error-border'">
                                    <p class="mt-1 text-label-sm text-error-text" x-show="errorFor(index, 'scheme_amount') !== ''" x-text="errorFor(index, 'scheme_amount')"></p>
                                </template>
                            </td>

                            <td class="px-3 py-2">
                                <select x-model="row.discount_policy" :name="`items[${index}][discount_policy]`"
                                        class="w-36 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    <option value="">— default —</option>
                                    <option value="STORE_BEARS">Toko tanggung</option>
                                    <option value="SHARED">Berdua</option>
                                </select>
                            </td>

                            <td class="px-3 py-2 text-center">
                                <input type="number" x-model.number="row.qty" :name="`items[${index}][qty]`"
                                       min="1" max="999" required
                                       class="w-20 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-center tabular-nums"
                                       :class="errorFor(index, 'qty') !== '' && 'border-error-border'">
                                <p class="mt-1 text-label-sm text-error-text" x-show="errorFor(index, 'qty') !== ''" x-text="errorFor(index, 'qty')"></p>
                            </td>

                            <td class="px-3 py-2">
                                <select x-model="row.card_condition" :name="`items[${index}][card_condition]`"
                                        class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    @foreach ($card_conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="px-3 py-2">
                                <select x-model="row.blister_condition" :name="`items[${index}][blister_condition]`"
                                        class="w-32 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    @foreach ($blister_conditions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="px-3 py-2">
                                <select x-model="row.rack_id" :name="`items[${index}][rack_id]`"
                                        class="w-28 rounded-lg border border-border-subtle bg-surface-lowest px-2 py-1.5 text-body-sm">
                                    <option value="">—</option>
                                    @foreach ($racks as $rack)
                                        <option value="{{ $rack->id }}">{{ $rack->code }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <td class="px-3 py-2 text-right tabular-nums text-body-sm"
                                :class="termsFor(row)?.negativeMargin ? 'text-error-text font-semibold' : 'text-text-strong'"
                                x-text="termsFor(row) === null ? '—' : window.format.rupiah(termsFor(row).storeFee)"></td>

                            <td class="px-3 py-2 text-right tabular-nums text-body-sm text-text-strong"
                                x-text="termsFor(row) === null ? '—' : window.format.rupiah(termsFor(row).consignorRight)"></td>

                            <td class="px-3 py-2">
                                <div class="flex items-center justify-center gap-1">
                                    <span x-show="deviates(row)"
                                          class="rounded-md bg-warning-bg px-1.5 py-0.5 text-label-sm text-warning-text"
                                          title="Berbeda dari default penitip — butuh PIN Owner">PIN</span>
                                    <button type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-text-muted transition hover:bg-canvas"
                                            @click="splitRow(index)" title="Pecah jadi dua baris">
                                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
                                    </button>
                                    <button type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-error-text transition hover:bg-error-bg"
                                            @click="removeRow(index)" title="Hapus baris">
                                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="12">
                            <x-ui.empty-state title="Belum ada produk" description="Klik Tambah Produk untuk memilih produk dan mulai mengisi penerimaan." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-t border-border-subtle bg-canvas px-6 py-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-6 text-body-sm">
                    <span class="text-text-muted">Total Baris: <b class="text-text-strong" x-text="rows.length"></b></span>
                    <span class="text-text-muted">Total Unit: <b class="text-text-strong tabular-nums" x-text="totalQty()"></b></span>
                    <span class="text-text-muted">Total Fee Toko: <b class="text-text-strong tabular-nums" x-text="window.format.rupiah(totals().storeFee)"></b></span>
                    <span class="text-text-muted">Total Hak Penitip: <b class="text-text-strong tabular-nums" x-text="window.format.rupiah(totals().consignorRight)"></b></span>
                </div>
                <p x-show="needsOwnerPin()" class="text-label-sm text-warning-text">
                    <b>Ada baris di luar default penitip.</b> PIN Owner diminta sekali saat Commit.
                </p>
            </div>
        </div>
    </x-ui.section-card>

    <!-- Tier 3: Sticky Commit Bar -->
    <div class="sticky bottom-0 z-10 rounded-xl border border-border-subtle bg-surface-lowest px-5 py-4 sticky-bar">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <label class="flex cursor-pointer items-center gap-3 text-body-sm text-text-strong">
                <input type="checkbox" name="verified" value="1" x-model="verified"
                       class="h-5 w-5 rounded border-border-strong text-primary focus:ring-primary/30" required>
                Saya telah memverifikasi fisik unit sesuai baris (<b class="tabular-nums" x-text="totalQty()"></b>&nbsp;unit)
            </label>
            {{-- Status auto-save. WAJIB di dalam form: komponen `inboundGrid`
                 hanya jadi scope Alpine di dalam `x-data`-nya, jadi di luar
                 itu `saveState` tidak dikenal dan Alpine melempar error setiap
                 render. --}}
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-label-sm" x-show="saveState !== 'idle' && saveState !== 'saving'"
                      :class="saveState === 'locked' || saveState === 'offline' ? 'text-warning-text' : 'text-text-subtle'"
                      x-text="{
                          saved: savedAt ? `Draft tersimpan ${savedAtLabel()}` : 'Draft tersimpan',
                          offline: draftId === '' ? 'Draft belum tersimpan di server' : 'Tersimpan di perangkat ini saja',
                          locked: 'Draft sudah di-commit di tab lain',
                      }[saveState] ?? ''"></span>
                <span class="text-label-sm text-text-subtle" x-show="saveState === 'saving'">Menyimpan draft…</span>

                <button type="submit" class="btn-primary" :disabled="submitting || rows.length === 0">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    <span x-text="submitting ? 'Mengirim…' : 'Commit &amp; Buat Label'"></span>
                </button>
            </div>
        </div>
    </div>
</form>

    <!-- Picker: Bottom drawer mobile, Modal desktop -->
    <div x-show="pickerOpen" x-cloak>
        <div class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/40" @click="closePicker()"></div>
            <div
                class="absolute inset-x-0 bottom-0 flex max-h-[85dvh] flex-col rounded-t-xl bg-surface-lowest shadow-xl sm:inset-auto sm:top-1/2 sm:left-1/2 sm:max-h-[90vh] sm:w-full sm:max-w-3xl sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl"
            >
                <div class="flex flex-col">
                    <div class="mx-auto mt-2 h-1.5 w-10 rounded-full bg-border-subtle sm:hidden"></div>
                    <div class="flex items-center justify-between px-4 py-3 sm:px-6 sm:py-4 border-b border-border-subtle">
                        <h3 class="text-title-sm font-semibold">Cari Produk</h3>
                        <button type="button" class="btn-ghost" @click="closePicker()">Tutup</button>
                    </div>
                </div>
                <div
                    class="flex-1 min-h-0 overflow-auto px-4 py-4 sm:px-6"
                    x-data="productPicker({ url: lookupUrl, emitEvent: 'consignment-in:add-product' })"
                >
                    <div class="space-y-3">
                        <div class="relative">
                            <input
                                id="consignment-in-picker-search"
                                type="text"
                                class="input-base pl-9"
                                placeholder="Ketik nama produk, kode casting, atau barcode..."
                                x-model="term"
                                @input="searchSoon()"
                                @keydown.enter.prevent="submit()"
                                @keydown.arrow-down.prevent="move(1)"
                                @keydown.arrow-up.prevent="move(-1)"
                                @keydown.esc.prevent="closePicker()"
                            >
                            <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                        </div>

                        <div x-show="loading" class="text-body-sm text-text-muted">Mencari...</div>
                        <div x-show="error" class="text-body-sm text-error-text" x-text="error"></div>
                        <div x-show="!loading && searched && !hasResults" class="text-body-sm text-text-muted">Tidak ditemukan</div>

                        <div x-show="hasResults" class="divide-y divide-border-subtle rounded-lg border border-border-subtle">
                            <template x-for="(item, index) in items" :key="index">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-canvas"
                                    :class="activeIndex === index ? 'bg-primary-soft' : ''"
                                    @click="choose(item)"
                                    @mouseenter="activeIndex = index"
                                >
                                    <div class="flex-1 min-w-0">
                                        <div class="text-body-sm font-medium text-text-strong truncate" x-text="item.name"></div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-label-sm text-text-subtle">
                                            <span x-show="item.casting_code">Kode: <span x-text="item.casting_code"></span></span>
                                            <span x-show="item.series">Seri: <span x-text="item.series"></span></span>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
