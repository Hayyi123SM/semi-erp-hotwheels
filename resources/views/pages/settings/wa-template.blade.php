<x-ui.page-header
    title="Template WhatsApp"
    subtitle="Isi pesan yang benar-benar dipakai saat e-receipt dikirim ke penitip."
    :crumbs="['Pengaturan', 'Template WhatsApp']"
/>

{{--
    Satu form per template, bukan satu "Simpan Semua".

    Alasan yang menentukan: setiap template punya daftar variabelnya sendiri,
    dan template yang gagal divalidasi harus tetap bisa disimpan kalau yang
    salah itu template lain. Dengan satu tombol untuk semuanya, satu template
    yang belum rapi membuat seluruh halaman tidak bisa disimpan -- dan karena
    halaman ini tidak pernah tersimpan, tidak ada yang memberitahu.
--}}

@if ($errors->any())
    <x-ui.banner tone="error" class="mb-5">
        <ul class="list-disc space-y-1 pl-4">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.banner>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @foreach ($templates as $key => $template)
            <x-ui.section-card :title="$template['label']">
                <x-slot:actions>
                    <x-ui.badge-status :type="$template['custom'] ? 'info' : 'success'">
                        {{ $template['custom'] ? 'DISIMPAN' : 'BAWAAN' }}
                    </x-ui.badge-status>
                </x-slot:actions>

                <form method="POST" action="{{ route('setting.wa-template.update') }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="template" value="{{ $key }}">

                    <textarea
                        name="body"
                        rows="12"
                        class="input-base h-auto resize-y font-mono text-body-sm"
                        spellcheck="false"
                        aria-label="Isi template {{ $template['label'] }}"
                        @error('body') aria-invalid="true" @enderror
                    >{{ old('body', $template['body']) }}</textarea>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-label-sm text-text-subtle">
                            {{ $template['custom'] ? 'Disimpan di Pengaturan.' : 'Belum diubah, memakai bawaan sistem.' }}
                        </p>
                        <button type="submit" class="btn-primary">Simpan {{ $template['label'] }}</button>
                    </div>
                </form>

                <div class="mt-3 border-t border-border-subtle pt-3">
                    <p class="text-label-sm text-text-muted">
                        Variabel yang wajib ada, sesuai urutan parameter SRS:
                    </p>
                    <p class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($template['variables'] as $variable)
                            <code class="rounded bg-canvas px-1.5 py-0.5 text-label-sm text-text-muted">
                                {{ $variable['label'] }}
                            </code>
                        @endforeach
                    </p>
                    <p class="mt-2 text-label-sm text-text-subtle">
                        Boleh memakai variabel berulang atau menyusun ulang barisnya.
                        Yang tidak boleh: meninggalkan salah satu, atau menulis nama
                        yang tidak ada di daftar ini -- keduanya ditolak saat menyimpan,
                        bukan saat pesan dikirim.
                    </p>
                </div>
            </x-ui.section-card>
        @endforeach
    </div>

    <div class="space-y-6">
        <x-ui.section-card title="Log Pengiriman Terakhir">
            @if ($recentNotifications->isEmpty())
                <x-ui.empty-state title="Belum ada pengiriman" description="E-receipt pertama akan muncul di sini setelah konsinyasi diterima." />
            @else
                <ul class="divide-y divide-border-subtle">
                    @foreach ($recentNotifications as $notification)
                        <li class="py-3">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-body-sm font-medium text-text-strong">
                                    {{ $notification->consignment?->doc_no ?? '-' }}
                                </p>
                                <x-ui.badge-status :type="$notification->status->type()">
                                    {{ $notification->status->label() }}
                                </x-ui.badge-status>
                            </div>
                            <p class="text-label-sm text-text-subtle">
                                {{ $notification->created_at->format('d M Y H:i') }} · {{ $notification->attempts }} percobaan
                            </p>
                            @if ($notification->error_message)
                                <p class="mt-0.5 text-label-sm text-error-text">{{ $notification->error_message }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.section-card>

        <x-ui.banner tone="warning">
            Belum ada WhatsApp Business API yang terhubung, jadi e-receipt
            dikirim lewat tautan <code class="rounded bg-canvas px-1">wa.me</code>:
            Staff menekan tombolnya dan mengirim manual. Status
            "Diserahkan ke Staff" berarti tautannya sudah dibuka, bukan
            bahwa pesan sudah sampai ke penitip.
        </x-ui.banner>

        <x-ui.banner tone="info">
            Jangan menaruh nomor rekening atau data bank di template. Begitu
            terkirim, data itu keluar dari kendali toko, dan satu pesan
            konsinyasi bisa dibaca lebih dari satu orang.
        </x-ui.banner>
    </div>
</div>
