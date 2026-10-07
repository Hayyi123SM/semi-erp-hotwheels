@php
    $roles = \App\Support\Enums::options(\App\Enums\Role::class);
@endphp

<form data-guard method="POST" action="{{ $isCreate ? route('setting.pengguna.store') : route('setting.pengguna.update', $user) }}" class="space-y-6">
    @csrf
    @if (! $isCreate)
        @method('PUT')
    @endif

    <x-ui.section-card title="Identitas Pengguna">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field label="Nama Lengkap" name="name" required>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" class="input-base @error('name') border-error-border @enderror" placeholder="mis. Ahmad Fauzi" required>
                @error('name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Username" name="username" required hint="Huruf, angka, titik, garis bawah, atau strip.">
                <input id="username" name="username" value="{{ old('username', $user->username) }}" class="input-base font-mono @error('username') border-error-border @enderror" placeholder="mis. ahmad.fauzi" required>
                @error('username')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Email (opsional)" name="email">
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" class="input-base @error('email') border-error-border @enderror" placeholder="mis. ahmad@toko.com">
                @error('email')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <x-ui.field label="Role" name="role">
                <select id="role" name="role" class="input-base @error('role') border-error-border @enderror">
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}" @selected((old('role', $user->role?->value) ?? 'STAFF') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <div>
                <x-ui.field label="Password" name="password" :required="$isCreate" :hint="$isCreate ? 'Minimal 8 karakter.' : 'Kosongkan bila tidak diganti.'">
                    <input type="password" id="password" name="password" class="input-base @error('password') border-error-border @enderror" placeholder="{{ $isCreate ? 'Minimal 8 karakter' : '••••••••' }}">
                    @error('password')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
            </div>

            <x-ui.field label="PIN (opsional)" name="pin" hint="6 digit. Dipakai untuk otorisasi aksi tertentu.">
                <input id="pin" name="pin" value="{{ old('pin') }}" class="input-base font-mono" placeholder="mis. 123456" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
                @error('pin')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>

            <div class="sm:col-span-2">
                <label class="flex cursor-pointer items-center gap-2 text-label-md text-text-muted">
                    <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $user->is_active ?? true)) class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                    Akun aktif (bisa login)
                </label>
            </div>
        </div>
    </x-ui.section-card>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('setting.pengguna') }}" class="btn-secondary">Batal</a>
        <button type="submit" class="btn-primary">{{ $isCreate ? 'Simpan Pengguna' : 'Simpan Perubahan' }}</button>
    </div>
</form>