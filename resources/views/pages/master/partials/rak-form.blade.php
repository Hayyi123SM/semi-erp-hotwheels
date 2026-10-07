@php
    $types = \App\Support\Enums::options(\App\Enums\RackType::class);
@endphp

<form data-guard method="POST" action="{{ $isCreate ? route('master.lokasi-rak.store') : route('master.lokasi-rak.update', $rack) }}" class="space-y-6">
    @csrf
    @if (! $isCreate)
        @method('PUT')
    @endif

    <x-ui.section-card title="Identitas Rak">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field label="Kode Rak" name="code" required hint="Contoh: A-S1-L1, Q-01. Huruf dan angka dengan tanda pangkur.">
                <input id="code" name="code" value="{{ old('code', $rack->code) }}" class="input-base font-mono uppercase @error('code') border-error-border @enderror" placeholder="A-S1-L1" required>
                @error('code')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Zona" name="zone">
                <input id="zone" name="zone" value="{{ old('zone', $rack->zone) }}" class="input-base" placeholder="mis. A">
            </x-ui.field>

            <x-ui.field label="Tipe" name="type">
                <select id="type" name="type" class="input-base">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected((old('type', $rack->type?->value) ?? 'DISPLAY') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>

            <x-ui.field label="Kapasitas (item)" name="capacity" hint="Kosongkan bila tanpa batas.">
                <input id="capacity" name="capacity" value="{{ old('capacity', $rack->capacity) }}" class="input-base tabular-nums" placeholder="mis. 120">
                @error('capacity')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <div class="sm:col-span-2">
                <label class="flex cursor-pointer items-center gap-2 text-label-md text-text-muted">
                    <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $rack->is_active)) class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                    Rak aktif (bisa dipakai untuk penyimpanan)
                </label>
            </div>
        </div>
    </x-ui.section-card>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('master.lokasi-rak') }}" class="btn-secondary">Batal</a>
        <button type="submit" class="btn-primary">{{ $isCreate ? 'Simpan Rak' : 'Simpan Perubahan' }}</button>
    </div>
</form>