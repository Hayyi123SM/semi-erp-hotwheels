<x-ui.page-header
    title="Template WhatsApp"
    subtitle="Pesan otomatis untuk penitip & Owner. Variabel {name}, {code}, {total}, {date}."
    :crumbs="['Pengaturan', 'Template WhatsApp']"
>
    <x-slot:actions>
        <button type="button" class="btn-primary" @click="$store.toast.push('Template disimpan (mock)', 'success')">Simpan Semua</button>
    </x-slot:actions>
</x-ui.page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @foreach ([
            ['n' => 'Confirmation Stock In', 'k' => 'CONFIRM_IN', 'd' => 'Dikirim saat stock masuk diterima', 'tpl' => "Halo {name} (CN{code}) 👋\nBarang titipan Anda telah diterima:\n· {qty} unit · Total nilai Rp{total}\nTerima kasih!\n— Hot Wheels Store"],
            ['n' => 'Statement Settlement', 'k' => 'SETTLEMENT', 'd' => 'Dikirim saat DRAFT → SENT', 'tpl' => "Halo {name} 👋\nStatement penjualan Anda (periode {date}):\n· Bruto: Rp{bruto}\n· Fee: Rp{fee}\n· Hak penjualan: Rp{net}\nMohon konfirmasi pembayaran."],
            ['n' => 'Reminder RTV', 'k' => 'RTV_REMINDER', 'd' => 'SKU aging 60+ hari tanpa transaksi', 'tpl' => "Halo {name} 👋\nBeberapa item Anda telah {age} hari tanpa penjualan. Kami rencanakan pengembalian (RTV). Balas Y untuk setuju."],
        ] as $i => $wa)
            <x-ui.section-card :title="$wa['n']">
                <x-slot:actions>
                    <x-ui.badge-status type="info">{{ $wa['k'] }}</x-ui.badge-status>
                </x-slot:actions>
                <p class="mb-3 text-label-sm text-text-muted">{{ $wa['d'] }}</p>
                <textarea rows="5" class="input-base h-auto resize-none font-mono text-body-sm">{{ trim($wa['tpl']) }}</textarea>
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-label-sm text-text-subtle">Variabel: <code class="rounded bg-canvas px-1">{name}</code> <code class="rounded bg-canvas px-1">{code}</code> <code class="rounded bg-canvas px-1">{total}</code> <code class="rounded bg-canvas px-1">{date}</code></span>
                    <button type="button" class="btn-secondary h-9" @click="$store.toast.push('Uji kirim via WA (mock)', 'success')">Test Kirim</button>
                </div>
            </x-ui.section-card>
        @endforeach
    </div>

    <div class="space-y-6">
        <x-ui.section-card title="Log Terakhir">
            <ul class="divide-y divide-border-subtle">
                @foreach ([
                    ['at' => '24 Sep 12:59', 'to' => 'CN01', 't' => 'SETTLEMENT · SENT'],
                    ['at' => '24 Sep 11:30', 'to' => 'CN02', 't' => 'CONFIRM_IN · 3 unit'],
                    ['at' => '22 Sep 18:20', 'to' => 'CN01', 't' => 'RTV_REMINDER · 2 SKU'],
                ] as $log)
                    <li class="py-3">
                        <p class="text-body-sm font-medium text-text-strong">{{ $log['t'] }}</p>
                        <p class="text-label-sm text-text-subtle">{{ $log['at'] }} · ke {{ $log['to'] }}</p>
                    </li>
                @endforeach
            </ul>
        </x-ui.section-card>

        <x-ui.banner tone="info">
            Pastikan Anda telah mengizinkan koneksi WhatsApp Business API sebelum mengaktifkan pengiriman otomatis.
        </x-ui.banner>
    </div>
</div>