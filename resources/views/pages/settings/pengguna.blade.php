<x-ui.page-header
    title="Pengguna &amp; Role"
    subtitle="Manajemen akun akses: Owner, Manager, Kasir. Role menentukan visibilitas finansial."
    :crumbs="['Pengaturan', 'Pengguna & Role']"
>
    <x-slot:actions>
        <button type="button" class="btn-primary" @click="$store.toast.push('Undangan terkirim (mock)', 'success')">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
            Undang Pengguna
        </button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Daftar Pengguna">
        <table class="w-full text-left">
            <thead class="thead-dense">
                <tr>
                    <th class="px-6 py-3 font-semibold">Nama</th>
                    <th class="px-6 py-3 font-semibold">Perangkat Utama</th>
                    <th class="px-6 py-3 font-semibold">Role</th>
                    <th class="px-6 py-3 font-semibold">No. WhatsApp</th>
                    <th class="px-6 py-3 font-semibold">Status</th>
                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
                @foreach ([
                    ['n' => 'Owner (Admin)', 'd' => 'CP1 · Web', 'r' => 'OWNER', 'w' => '+62 811-000-0001', 'st' => 'active'],
                    ['n' => 'Ahmad Fauzi', 'd' => 'CP2 · POS', 'r' => 'CASHIER', 'w' => '+62 812-000-0002', 'st' => 'active'],
                    ['n' => 'Dewi Lestari', 'd' => 'CP2 · WMS', 'r' => 'MANAGER', 'w' => '+62 813-000-0003', 'st' => 'active'],
                    ['n' => 'Siti Rahma', 'd' => '—', 'r' => 'CASHIER', 'w' => '+62 814-000-0004', 'st' => 'invited'],
                ] as $user)
                    <tr class="row-dense transition hover:bg-canvas">
                        <td class="px-6 py-3 text-body-md font-medium text-text-strong">{{ $user['n'] }}</td>
                        <td class="px-6 py-3 font-mono text-label-sm text-text-muted">{{ $user['d'] }}</td>
                        <td class="px-6 py-3">
                            @if ($user['r'] === 'OWNER')
                                <x-ui.badge-status type="error">{{ $user['r'] }}</x-ui.badge-status>
                            @elseif ($user['r'] === 'MANAGER')
                                <x-ui.badge-status type="info">{{ $user['r'] }}</x-ui.badge-status>
                            @else
                                <x-ui.badge-status type="success">{{ $user['r'] }}</x-ui.badge-status>
                            @endif
                        </td>
                        <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $user['w'] }}</td>
                        <td class="px-6 py-3">
                            @if ($user['st'] === 'active')
                                <x-ui.badge-status type="success" dot>Aktif</x-ui.badge-status>
                            @else
                                <x-ui.badge-status type="warning">Undangan</x-ui.badge-status>
                            @endif
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end gap-1">
                                <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Edit pengguna dibuka (mock)', 'info')">Edit</button>
                                <button type="button" class="btn-ghost h-9 px-3 text-error-text" @click="$store.toast.push('Nonaktifkan pengguna memerlukan PIN Owner', 'warning')">Nonaktifkan</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.section-card>

    <x-ui.section-card title="Peran &amp; Pembatasan">
        <ul class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                ['r' => 'OWNER', 'd' => 'Akses penuh: HPP, PIN, settlement, perangkat, audit.', 'class' => 'border-error-border bg-error-bg'],
                ['r' => 'MANAGER', 'd' => 'Operasional + lihat finansial; tanpa PIN & pengaturan perangkat.', 'class' => 'border-info-border bg-info-bg'],
                ['r' => 'CASHIER', 'd' => 'POS & inbound; kolom HPP & saldo disembunyikan.', 'class' => 'border-border-subtle bg-canvas'],
            ] as $role)
                <li class="rounded-lg border p-4 {{ $role['class'] }}">
                    <p class="text-body-md font-bold text-text-strong">{{ $role['r'] }}</p>
                    <p class="mt-1 text-body-sm text-text-muted">{{ $role['d'] }}</p>
                </li>
            @endforeach
        </ul>
    </x-ui.section-card>
</div>