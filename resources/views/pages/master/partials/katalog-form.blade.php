@php
    $packaging = \App\Support\Enums::options(\App\Enums\PackagingType::class);
    $cardConditions = \App\Support\Enums::options(\App\Enums\CardCondition::class);
    $blisterConditions = \App\Support\Enums::options(\App\Enums\BlisterCondition::class);
    $seriesSelected = old('series_id', $product->series_id);
@endphp

<form data-guard method="POST" action="{{ $isCreate ? route('master.katalog-produk.store') : route('master.katalog-produk.update', $product) }}" class="space-y-6">
    @csrf
    @if (! $isCreate)
        @method('PUT')
    @endif

    <x-ui.section-card title="Identitas Produk">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field label="Nama Produk" name="name" required>
                <input id="name" name="name" value="{{ old('name', $product->name) }}" class="input-base @error('name') border-error-border @enderror" placeholder="mis. '70 Mustang Funny Car" required>
                @error('name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Seri" name="series_id">
                <x-ui.searchable-select placeholder="— Tanpa Seri —">
                    <select id="series_id" name="series_id" class="sr-only" @focus="openPanel()" @keydown="onSearchKeydown($event)">
                        <option value="">— Tanpa Seri —</option>
                        @foreach ($series as $seri)
                            <option value="{{ $seri->id }}" @selected((int) old('series_id', $seriesSelected ?? 0) === $seri->id)>{{ $seri->name }}</option>
                        @endforeach
                    </select>
                </x-ui.searchable-select>
            </x-ui.field>

            <x-ui.field label="Kode Casting" name="casting_code">
                <input id="casting_code" name="casting_code" value="{{ old('casting_code', $product->casting_code) }}" class="input-base" placeholder="mis. MBX-408">
            </x-ui.field>

            <x-ui.field label="Tahun" name="year">
                <input id="year" name="year" value="{{ old('year', $product->year) }}" class="input-base tabular-nums" placeholder="mis. 2024">
                @error('year')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Warna" name="color">
                <input id="color" name="color" value="{{ old('color', $product->color) }}" class="input-base" placeholder="mis. Burnt Orange">
            </x-ui.field>

            <x-ui.field label="Referensi Barcode Pabrik" name="factory_barcode_ref">
                <input id="factory_barcode_ref" name="factory_barcode_ref" value="{{ old('factory_barcode_ref', $product->factory_barcode_ref) }}" class="input-base font-mono" placeholder="opsional">
            </x-ui.field>
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Kondisi & Kemasan">
        <div class="grid gap-5 sm:grid-cols-3">
            <x-ui.field label="Kemasan" name="packaging_type">
                <select id="packaging_type" name="packaging_type" class="input-base">
                    @foreach ($packaging as $value => $label)
                        <option value="{{ $value }}" @selected((old('packaging_type', $product->packaging_type?->value) ?? 'CARDED') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field label="Kondisi Kardus" name="card_condition">
                <select id="card_condition" name="card_condition" class="input-base">
                    @foreach ($cardConditions as $value => $label)
                        <option value="{{ $value }}" @selected((old('card_condition', $product->card_condition?->value) ?? 'MINT') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field label="Kondisi Blister" name="blister_condition">
                <select id="blister_condition" name="blister_condition" class="input-base">
                    @foreach ($blisterConditions as $value => $label)
                        <option value="{{ $value }}" @selected((old('blister_condition', $product->blister_condition?->value) ?? 'CLEAR') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Harga & Label">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field label="Harga Jual (Rp)" name="default_list_price" required hint="Harga default yang dipakai saat membuat stock lot baru.">
                <x-ui.money-input name="default_list_price" :value="$product->default_list_price" placeholder="mis. 65000" required />
                @error('default_list_price')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Tags" name="tags" hint="Pisahkan dengan koma, contoh: limited, mainline.">
                <input id="tags" name="tags" value="{{ old('tags', is_array($product->tags) ? implode(', ', $product->tags) : '') }}" class="input-base" placeholder="mainline, mint">
            </x-ui.field>
        </div>
    </x-ui.section-card>

    @if ($canManage)
        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('master.katalog-produk') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">{{ $isCreate ? 'Simpan Produk' : 'Simpan Perubahan' }}</button>
        </div>
    @else
        <div class="flex items-center justify-between gap-3 rounded-lg border border-border-subtle bg-canvas px-4 py-3">
            <p class="text-body-sm text-text-muted">
                <b>Quick Add:</b> produk ini akan ditandai <b>Perlu Review</b> dan menunggu persetujuan Owner.
            </p>
            <button type="submit" class="btn-primary shrink-0">Simpan untuk Review</button>
        </div>
    @endif
</form>