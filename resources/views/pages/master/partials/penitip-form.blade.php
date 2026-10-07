@php
    $schemeTypes = \App\Support\Enums::options(\App\Enums\SchemeType::class);
    $cycles = \App\Support\Enums::options(\App\Enums\SettlementCycle::class);
    $discounts = \App\Support\Enums::options(\App\Enums\DiscountPolicy::class);
    $liabilities = \App\Support\Enums::options(\App\Enums\LossLiability::class);
    $schemeSelected = old('scheme_type', $consignor->scheme_type?->value ?? 'PERCENTAGE');
@endphp

<form data-guard method="POST" action="{{ $isCreate ? route('master.penitip.store') : route('master.penitip.update', $consignor) }}" class="space-y-6">
    @csrf
    @if (! $isCreate)
        @method('PUT')
    @endif

    <x-ui.section-card title="Data Dasar">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field label="Nama Lengkap" name="name" required>
                <input id="name" name="name" value="{{ old('name', $consignor->name) }}" class="input-base @error('name') border-error-border @enderror" placeholder="mis. Budi Santoso" required>
                @error('name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            {{-- Nilai yang ditampilkan tetap bentuk yang diketik manusia, sementara
                 yang disimpan polos untuk dibaca `wa.me`. Kalau form menampilkan
                 bentuk polos, placeholder di bawahnya kontradiktif dengan isinya
                 sendiri. --}}
            <x-ui.field label="No. WhatsApp (E.164)" name="wa_number" hint="Format internasional, contoh +62 812-3456-7890">
                <input id="wa_number" name="wa_number" inputmode="tel"
                       value="{{ old('wa_number') !== null && old('wa_number') !== '' ? \App\Support\WhatsappNumber::display(old('wa_number')) : $consignor->wa_number_display }}"
                       class="input-base font-mono @error('wa_number') border-error-border @enderror"
                       placeholder="+62 812-3456-7890">
                @error('wa_number')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Tanggal Perjanjian" name="agreement_date">
                <input type="date" id="agreement_date" name="agreement_date" value="{{ old('agreement_date', $consignor->agreement_date?->format('Y-m-d')) }}" class="input-base">
            </x-ui.field>

            <x-ui.field label="Alamat" name="address">
                <input id="address" name="address" value="{{ old('address', $consignor->address) }}" class="input-base" placeholder="mis. Jakarta Selatan">
            </x-ui.field>

            {{-- Persetujuan dicatat sebagai waktu, bukan sebagai kotak centang yang
                 disimpan apa adanya: yang perlu dibuktikan kapan penitip menyetujui,
                 supaya mencabutnya bisa dibedakan dari belum pernah ditanya.
                 Mengosongkan centang berarti mencabut persetujuan, bukan
                 membiarkannya. --}}
            <div class="sm:col-span-2">
                <label class="flex cursor-pointer items-start gap-2 text-label-md text-text-muted">
                    <input type="checkbox" name="wa_opt_in" value="1"
                           @checked((bool) old('wa_opt_in', $consignor->wa_opt_in_at !== null))
                           class="mt-0.5 h-4 w-4 shrink-0 rounded border-border-strong text-primary focus:ring-primary/30">
                    <span>
                        Penitip setuju dikabhari WhatsApp tentang barang &amp; pembayarannya
                        <span class="mt-0.5 block text-label-sm text-text-subtle">Centang hanya setelah penitip menyetujuinya. Mengosongkan centang berarti mencabut persetujuan.</span>
                    </span>
                </label>
                @error('wa_opt_in')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-ui.section-card>

    @if ($canManage)
        <x-ui.section-card title="Skema Bagi Hasil & Ketentuan">
            <div class="grid gap-5 sm:grid-cols-2" x-data="{ scheme: @js($schemeSelected) }">
                <x-ui.field label="Skema" name="scheme_type">
                    <select id="scheme_type" name="scheme_type" class="input-base @error('scheme_type') border-error-border @enderror" x-model="scheme">
                        @foreach ($schemeTypes as $value => $label)
                            <option value="{{ $value }}" @selected($schemeSelected === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('scheme_type')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>

                <x-ui.field label="Nilai Bagi Hasil" name="scheme_rate" hint="Persen (%) untuk PERCENTAGE, atau Rp/unit untuk NETT & FLAT">
                    <div x-show="scheme === 'PERCENTAGE'">
                        <x-ui.money-input name="scheme_rate" mode="rate" :value="$consignor->scheme_rate" placeholder="20" />
                    </div>
                    <div x-show="scheme !== 'PERCENTAGE'" x-cloak>
                        <x-ui.money-input name="scheme_amount" :value="$consignor->scheme_amount" placeholder="mis. 35000" />
                    </div>
                    @error('scheme_rate')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                    @error('scheme_amount')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>

                <x-ui.field label="Siklus Settlement" name="settlement_cycle">
                    <select id="settlement_cycle" name="settlement_cycle" class="input-base">
                        @foreach ($cycles as $value => $label)
                            <option value="{{ $value }}" @selected((old('settlement_cycle', $consignor->settlement_cycle?->value) ?? 'MONTHLY') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.field label="Kebijakan Diskon" name="discount_policy">
                    <select id="discount_policy" name="discount_policy" class="input-base">
                        @foreach ($discounts as $value => $label)
                            <option value="{{ $value }}" @selected((old('discount_policy', $consignor->discount_policy?->value) ?? 'STORE_BEARS') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.field label="Risiko Kehilangan / Kerusakan" name="loss_liability">
                    <select id="loss_liability" name="loss_liability" class="input-base">
                        @foreach ($liabilities as $value => $label)
                            <option value="{{ $value }}" @selected((old('loss_liability', $consignor->loss_liability?->value) ?? 'STORE') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.field label="Minimal Payout (Rp)" name="min_payout" hint="Batas saldo sebelum settlement otomatis">
                    <x-ui.money-input name="min_payout" :value="$consignor->min_payout" placeholder="100.000" />
                    @error('min_payout')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
            </div>
        </x-ui.section-card>

        <x-ui.section-card title="Rekening Penerima (terenkripsi)">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.field label="Bank" name="bank_name">
                    <input id="bank_name" name="bank_name" value="{{ old('bank_name', $consignor->bank_name) }}" class="input-base" placeholder="mis. BCA">
                </x-ui.field>
                <x-ui.field label="Nomor Rekening" name="bank_account">
                    <input id="bank_account" name="bank_account" value="{{ old('bank_account', $consignor->bank_account) }}" class="input-base font-mono" placeholder="1234567890">
                </x-ui.field>
                <x-ui.field label="Atas Nama" name="bank_holder">
                    <input id="bank_holder" name="bank_holder" value="{{ old('bank_holder', $consignor->bank_holder) }}" class="input-base" placeholder="Bud Santoso">
                </x-ui.field>
            </div>
        </x-ui.section-card>
    @else
        <x-ui.banner tone="info">
            Bagian <b>Skema Bagi Hasil</b> dan <b>Rekening</b> hanya dapat dikelola oleh Owner.
        </x-ui.banner>
    @endif

    <x-ui.section-card title="Catatan Internal">
        <x-ui.field label="Notes" name="notes">
            <textarea id="notes" name="notes" rows="2" class="input-base" placeholder="Catatan opsional tentang penitip ini">{{ old('notes', $consignor->notes) }}</textarea>
        </x-ui.field>
    </x-ui.section-card>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('master.penitip') }}" class="btn-secondary">Batal</a>
        <button type="submit" class="btn-primary">{{ $isCreate ? 'Simpan Penitip' : 'Simpan Perubahan' }}</button>
    </div>
</form>