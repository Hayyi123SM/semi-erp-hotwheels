@use('App\Enums\LabelPaperMode')
@use('App\Http\Requests\Settings\SavePrinterSettingsRequest')
@use('App\Services\Label\LabelPrinterSettings')
@use('App\Services\Label\LabelTemplate')
@use('App\Services\Label\SheetGridCalculator')

<x-ui.page-header
    title="Perangkat"
    subtitle="Konfigurasi printer termal, laci kas, endpoint synchronizer & koneksi offline."
    :crumbs="['Pengaturan', 'Perangkat']"
>
</x-ui.page-header>

<div class="space-y-6">
    {{--
        Satu kolom, bukan dua: kartu label dan kartu struk sama-sama penuh
        lebar. Pratinjau struk butuh lebar kertas A4 (190 mm) yang tidak muat
        kalau ia hanya menempati separuh halaman.
    --}}
    <div class="grid gap-6">
        <x-ui.section-card title="Printer Label (WMS-01)">
            <div class="space-y-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">D-Label D4 · USB (203 DPI)</p>
                        <p class="text-label-sm text-text-muted">
                            Rak A · {{ $labelTemplates[$defaultTemplate]['label'] }} default
                        </p>
                    </div>
                    <x-ui.badge-status type="success" dot>ONLINE</x-ui.badge-status>
                </div>

                {{--
                    Bentuk yang bisa disimpan menyimpan dua angka, bukan yang satu
                    saja: ukuran label dan sisi QR. Keduanya berdiri sendiri, dan
                    keduanya punya batas. Ukuran label yang tidak ada di daftar
                    preset ditolak `Rule::in` di `SavePrinterSettingsRequest`
                    supaya tidak ada geometry yang belum pernah diuji yang bisa
                    terpilih diam-diam; sisi QR boleh bebas karena tidak mengubah
                    tata letak, hanya memperbesar atau memperkecil QR di dalam
                    ruang yang sudah ada.

                    Ini bukan sekadar dua angka yang disimpan. Mengganti ukuran
                    label mengubah isi label yang keluar dari printer -- baris
                    mana yang muncul dan mana yang hilang -- jadi menyimpan
                    setelan ini dibatasi untuk Owner.
                --}}
                @can('owner-only')
                    <form method="POST" action="{{ route('setting.perangkat.label.update') }}"
                          x-data="{
                              template: @js(old('default_template', $defaultTemplate)),
                              qrInput: @js((string) old('qr_side_cm', $qrSideCm)),
                              paperMode: @js(old('paper_mode', $paperMode)),

                              /*
                               * Angka kertas dan celah, bukan preset.
                               *
                               * Disimpan sebagai string, sama seperti qrInput:
                               * kolomnya `type='number'`, jadi `x-model` akan
                               * menulis balik apa adanya yang diketik Owner --
                               * termasuk string kosong. `parseFloat('')` jadi
                               * NaN, dan semua tempat yang membacanya harus
                               * memperlakukannya sebagai 'belum diisi' supaya
                               * tampilan tidak pernah menampilkan NaN mm.
                               */
                              mediaWidth: @js(old('sheet_media_width_mm', $sheet['width'])),
                              mediaHeight: @js(old('sheet_media_height_mm', $sheet['height'])),
                              hasGap: @js((bool) old('sheet_has_gap', $sheet['hasGap'])),
                              gap: @js(old('sheet_gap_mm', $sheet['gap'])),
                              maxPrintWidth: @js(old('max_print_width_mm', $sheet['maxPrintWidth'])),

                              savedTemplate: @js($defaultTemplate),
                              savedQr: @js((string) $qrSideCm),
                              savedPaperMode: @js($paperMode),
                              savedMediaWidth: @js($sheet['width']),
                              savedMediaHeight: @js($sheet['height']),
                              savedHasGap: @js($sheet['hasGap']),
                              savedGap: @js($sheet['gap']),
                              savedMaxPrintWidth: @js($sheet['maxPrintWidth']),

                              /*
                               * Ringkasan grid ikut mengikuti angka yang sedang
                               * diketik, dihitung server.
                               *
                               * Dua alasan kenapa ini lewat server, bukan
                               * dihitung ulang di JavaScript: satu rumus yang
                               * ada di dua tempat adalah cara paling murah
                               * untuk membuat Owner melihat '6 x 8 = 48 label',
                               * menekan Simpan, lalu mendapat cetakan dengan 4
                               * label per halaman tanpa ada yang gagal. Dan
                               * kolom yang perlu diperbaiki Owner tidak selalu
                               * kelihatan dari angkanya -- penolakan harus
                               * sampai ke kolom yang salah, sama seperti saat
                               * menyimpan.
                               *
                               * `sequence` menahan jawaban yang sudah basi:
                               * Owner mengetik cepat, dan jawaban lama yang
                               * tiba belakangan akan menimpa ringkasan yang
                               * sebenarnya untuk angka terakhirnya.
                               */
                              previewUrl: @js(route('setting.perangkat.label.preview')),
                              previewState: 'idle',
                              previewReadable: false,
                              previewFits: true,
                              previewSummary: '',
                              previewPageSize: '',
                              previewSlack: '',
                              previewMissing: [],
                              previewErrors: {},
                              previewTimer: null,
                              previewSequence: 0,
                              previewAbort: null,

                              isSheet() {
                                  return this.paperMode === @js(LabelPaperMode::Sheet->value);
                              },

                              /*
                               * Angka yang berubah sejak terakhir disimpan.
                               *
                               * Dipakai untuk peringatan 'Uji Cetak memakai yang
                               * sudah disimpan'. Normalisasi ke number dipakai
                               * supaya '100' dan '100.0' tidak dihitung berbeda:
                               * keduanya angka yang sama, dan peringatan yang
                               * muncul padahal tidak ada yang berubah hanya
                               * membuat Owner mengabaikannya.
                               */
                              isDirty() {
                                  return this.template !== this.savedTemplate
                                      || this.qrInput !== this.savedQr
                                      || this.paperMode !== this.savedPaperMode
                                      || this.mediaWidth !== this.savedMediaWidth
                                      || this.mediaHeight !== this.savedMediaHeight
                                      || this.hasGap !== this.savedHasGap
                                      || this.gap !== this.savedGap
                                      || this.maxPrintWidth !== this.savedMaxPrintWidth;
                              },

                              /*
                               * `template` ikut dipantau: ukuran presetnya
                               * menentukan berapa label yang muat, jadi Owner
                               * yang berganti preset tanpa mengubah kertaspun
                               * perlu ringkasan yang baru.
                               */
                              init() {
                                  this.$watch('paperMode', () => this.queuePreview());
                                  this.$watch('template', () => this.queuePreview());
                                  this.$watch('mediaWidth', () => this.queuePreview());
                                  this.$watch('mediaHeight', () => this.queuePreview());
                                  this.$watch('hasGap', () => this.queuePreview());
                                  this.$watch('gap', () => this.queuePreview());
                                  this.$watch('maxPrintWidth', () => this.queuePreview());

                                  this.queuePreview();
                              },

                              /*
                               * Menunggu Owner berhenti mengetik dulu.
                               *
                               * Tanpa jeda, satu ketikan '100' menembak tiga
                               * request: angka 1, 10, lalu 100. Dua yang
                               * pertama tidak berguna tapi tetap membebani
                               * server -- dan Owner bisa melihat ringkasan
                               * berkedip lewat '1 x 1 = 1 label' sebelum
                               * sampai ke angka yang benar.
                               */
                              queuePreview() {
                                  if (! this.isSheet()) {
                                      this.previewState = 'idle';

                                      return;
                                  }

                                  this.previewState = 'pending';

                                  if (this.previewTimer !== null) {
                                      clearTimeout(this.previewTimer);
                                  }

                                  this.previewTimer = setTimeout(() => this.loadPreview(), 250);
                              },

                              async loadPreview() {
                                  const sequence = ++this.previewSequence;

                                  if (this.previewAbort !== null) {
                                      this.previewAbort.abort();
                                  }

                                  this.previewAbort = new AbortController();
                                  this.previewState = 'loading';

                                  try {
                                      const response = await fetch(this.previewUrl, {
                                          method: 'POST',
                                          signal: this.previewAbort.signal,
                                          credentials: 'same-origin',
                                          headers: {
                                              'Content-Type': 'application/json',
                                              'Accept': 'application/json',
                                              'X-CSRF-TOKEN': @js(csrf_token()),
                                          },
                                          body: JSON.stringify({
                                              default_template: this.template,
                                              sheet_media_width_mm: this.mediaWidth,
                                              sheet_media_height_mm: this.mediaHeight,
                                              sheet_has_gap: this.hasGap ? 1 : 0,
                                              sheet_gap_mm: this.gap,
                                              max_print_width_mm: this.maxPrintWidth,
                                          }),
                                      });

                                      const data = await response.json();

                                      if (sequence !== this.previewSequence) {
                                          return;
                                      }

                                      this.previewReadable = data.readable === true;
                                      this.previewFits = data.fits === true;
                                      this.previewSummary = data.summary ?? '';
                                      this.previewPageSize = data.pageSize ?? '';
                                      this.previewSlack = data.trailingSlack ?? '';
                                      this.previewMissing = data.missing ?? [];
                                      this.previewErrors = data.errors ?? {};
                                      this.previewState = 'ready';
                                  } catch (error) {
                                      if (sequence !== this.previewSequence) {
                                          return;
                                      }

                                      // Batal bukan kegagalan: itu jawaban dari
                                      // request yang memang sudah tidak relevan
                                      // karena Owner masih mengetik.
                                      if (error.name === 'AbortError') {
                                          return;
                                      }

                                      this.previewState = 'failed';
                                  }
                              },

                              /*
                               * Kolom yang belum terisi dan alasan penolakan
                               * diratakan menjadi kalimat, supaya form tidak
                               * harus tahu nama field mana yang mana.
                               */
                              previewMissingLabels() {
                                  const labels = {
                                      sheet_media_width_mm: 'lebar kertas',
                                      sheet_media_height_mm: 'tinggi kertas',
                                      sheet_gap_mm: 'celah',
                                  };

                                  return this.previewMissing
                                      .map((field) => labels[field] ?? field);
                              },

                              previewErrorList() {
                                  const labels = {
                                      sheet_media_width_mm: 'Lebar kertas',
                                      sheet_media_height_mm: 'Tinggi kertas',
                                      sheet_gap_mm: 'Celah',
                                      default_template: 'Ukuran label',
                                  };

                                  return Object.entries(this.previewErrors).flatMap(
                                      ([field, messages]) => (messages ?? []).map(
                                          (message) => (labels[field] ?? field) + ': ' + message,
                                      ),
                                  );
                              },
                          }"
                          class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field label="Ukuran label bawaan" name="default_template" required
                                        hint="Dipakai untuk label baru yang tidak dipilih manual.">
                                <select id="default_template" name="default_template" x-model="template"
                                        class="input-base @error('default_template') border-error-border @enderror"
                                        required>
                                    @foreach ($labelTemplates as $value => $template)
                                        <option value="{{ $value }}" @selected(old('default_template', $defaultTemplate) === $value)>
                                            {{ $template['label'] }} — {{ $template['description'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('default_template')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror

                                {{--
                                    Ukuran 1,5 x 1,5 cm mencetak QR dengan SKU
                                    dan harga di bawahnya; tanpa nama produk
                                    dan kondisi. `LabelGeometry` memberinya
                                    `rows: []`, jadi baris-baris teks biasa
                                    (produk, kondisi) tidak punya tempat --
                                    SKU dan harga digambar renderer dengan
                                    font `QR_ONLY_SKU_FONT_CM` (0,15 cm), dan
                                    QR-nya sampai 1,05 cm supaya dua baris
                                    itu muat. Peringatan ini muncul begitu
                                    Owner memilihnya, sebelum disimpan, bukan
                                    setelah label pertama tercetak.
                                --}}
                                <div x-show="template === @js(LabelTemplate::QrOnly->value)"
                                     x-cloak
                                     class="mt-2">
                                    <x-ui.banner tone="warning">
                                        Ukuran ini mencetak <strong>QR, SKU, dan harga</strong>; tanpa
                                        nama produk dan kondisi. Pakai untuk rak sempit yang labelnya
                                        dibaca dengan scanner; label barang butuh 3 x 2 cm atau 4 x 3 cm.
                                    </x-ui.banner>
                                </div>
                            </x-ui.field>

                            <x-ui.field label="Sisi QR (cm)" name="qr_side_cm"
                                        hint="Kosongkan untuk memakai angka bawaan ukuran label.">
                                <input id="qr_side_cm" name="qr_side_cm" type="number"
                                       inputmode="decimal" step="0.01"
                                       min="{{ LabelPrinterSettings::MIN_QR_SIDE_CM }}"
                                       max="{{ LabelPrinterSettings::MAX_QR_SIDE_CM }}"
                                       value="{{ old('qr_side_cm', $qrSideCm) }}"
                                       x-model="qrInput"
                                       placeholder="{{ number_format($labelTemplates[$defaultTemplate]['qrSideCm'], 2, ',', '.') }}"
                                       class="input-base @error('qr_side_cm') border-error-border @enderror">
                                @error('qr_side_cm')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror

                                {{--
                                    Dampak sisi QR terhadap teks, dihitung di server.

                                    Ini bukan hiasan. Sisi QR dan lebar kolom teks
                                    memakai ruang yang sama: makin besar QR,
                                    makin sempit teks, dan ada batas di mana
                                    SKU atau harga ikut terpotong. Untuk harga
                                    itu berarti angka yang salah -- `Rp100.0...`
                                    terbaca sebagai harga seratus ribu.

                                    Tanpa ini, Owner baru tahu setelah menekan
                                    Simpan dan membaca pesan penolakan. Batasnya
                                    juga template-dependent, jadi tidak bisa
                                    ditulis sebagai angka tetap di atribut
                                    `max`.
                                --}}
                                <div x-show="template !== @js(LabelTemplate::QrOnly->value)" x-cloak class="mt-2">
                                    <x-ui.banner tone="info">
                                        <span x-text="
                                            (() => {
                                                const raw = parseFloat(qrInput);
                                                const qr = Number.isFinite(raw) && raw > 0 ? raw : null;
                                                return qr === null
                                                    ? 'Sisi QR memakai angka bawaan ukuran label.'
                                                    : `Sisi QR ${qr.toFixed(2).replace('.', ',')} cm memakai sebagian lebar label. Sisa ruang untuk teks mengecil seiring naiknya angka ini, dan diperiksa ulang saat disimpan.`;
                                            })()
                                        "></span>
                                    </x-ui.banner>
                                </div>
                            </x-ui.field>
                        </div>

                        {{--
                            Mode kertas, ukuran kertas, dan celah.

                            Dipisah dari dua field di atas karena governs hal yang
                            berbeda: `default_template` mengatur isi satu label,
                            sedangkan mode kertas mengatur bagaimana label-label
                            itu ditata di atas kertas. Menyamakan keduanya dalam
                            satu field akan membuat "label 1,5 x 1,5" terlihat
                            seperti pilihan yang salah untuk stiker, padahal itu
                            justru satu-satunya yang muat kolom 15 mm.

                            Jumlah kolom dan baris TIDAK ada di form ini. Itu
                            hasil, bukan masukan: Owner mengukur kertasnya, bukan
                            menghitung gridnya. Grid dihitung server dari angka-angka
                            di bawah ini, jadi tidak ada dua versi hitungan yang
                            bisa berbeda -- yang tampil di form dan yang dipakai
                            halaman cetak.
                        --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field label="Mode kertas" name="paper_mode" required
                                        hint="Menentukan halaman cetak: satu label per halaman, atau grid di atas kertas stiker.">
                                <select id="paper_mode" name="paper_mode" x-model="paperMode"
                                        class="input-base @error('paper_mode') border-error-border @enderror"
                                        required>
                                    @foreach ($labelPaperModes as $mode)
                                        <option value="{{ $mode['value'] }}" @selected(old('paper_mode', $paperMode) === $mode['value'])">
                                            {{ $mode['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('paper_mode')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror

                                <p class="mt-1 text-label-sm text-text-subtle" x-text="
                                    (() => {
                                        const found = @js($labelPaperModes).find((m) => m.value === paperMode);
                                        return found ? found.description : '';
                                    })()
                                "></p>
                            </x-ui.field>

                            {{--
                                Batas cetak printer ada di luar blok mode
                                stiker dengan sengaja. Batasnya soal kemampuan
                                alat, bukan soal kertas yang dipakai: printer
                                216 mm tetap 216 mm baik di mode stiker maupun
                                gulungan, jadi menyembunyikannya di salah satu mode
                                hanya membuat nilainya ikut hilang dari form saat
                                mode itu dipilih.
                            --}}
                            <x-ui.field label="Lebar cetak printer (mm)" name="max_print_width_mm"
                                        hint="Area cetak maksimum printer label. Kertas yang lebih lebar dari ini akan terpotong di tepi kanan.">
                                <input id="max_print_width_mm" name="max_print_width_mm" type="number"
                                       inputmode="decimal" step="0.5"
                                       min="{{ SavePrinterSettingsRequest::MIN_MEDIA_MM }}"
                                       max="{{ SavePrinterSettingsRequest::MAX_MEDIA_MM }}"
                                       value="{{ old('max_print_width_mm', $sheet['maxPrintWidth']) }}"
                                       x-model="maxPrintWidth"
                                       class="input-base @error('max_print_width_mm') border-error-border @enderror">
                                @error('max_print_width_mm')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                            </x-ui.field>
                        </div>

                        {{--
                            Cara cetak label, di bawah mode kertas dan di luar blok
                            stiker dengan alasan yang sama seperti lebar cetak:
                            ini soal alat, bukan soal kertas. `Thermal` dipakai
                            kalibrasi & cetak label langsung dari jalur TSPL
                            (Web Bluetooth sebagai tombol utama, WebUSB sebagai
                            tombol cadangan); `Browser` memakai dialog cetak
                            browser.
                        --}}
                        <div class="mt-4">
                            <x-ui.field label="Cara cetak label" name="print_method"
                                        hint="Dialog browser untuk semua printer. Langsung memakai perintah TSPL (QRCODE/TEXT): tombol utama lewat Web Bluetooth -- dialognya sudah menyaring nama printer BP-TD110BT -- dan tombol &quot;Cetak via USB&quot; lewat WebUSB untuk printer yang tersambung lewat kabel; kalau keduanya gagal, di halaman label ada tombol fallback ke dialog browser.">
                                <select id="print_method" name="print_method"
                                        class="input-base @error('print_method') border-error-border @enderror">
                                    @foreach ($labelPrintMethods as $labelMethod)
                                        <option value="{{ $labelMethod['value'] }}" @selected(old('print_method', $labelPrintMethod) === $labelMethod['value'])>
                                            {{ $labelMethod['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('print_method')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                            </x-ui.field>
                        </div>

                        {{--
                            Empat kolom kertas stiker, hanya saat mode stiker aktif.

                            `x-show`, bukan `hidden` atau kondisi PHP: mode bisa
                            Owner ganti berulang kali tanpa memuat ulang halaman,
                            dan kolom yang ikut hilang harus hilang bersamanya --
                            kalau tidak, ia akan tetap mengirim angkanya ke server
                            untuk mode yang tidak memakainya.
                        --}}
                        <div x-show="isSheet()" x-cloak>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.field label="Lebar kertas stiker (mm)" name="sheet_media_width_mm"
                                            hint="Diukur dari satu sisi ke sisi lain, dalam milimeter.">
                                    <input id="sheet_media_width_mm" name="sheet_media_width_mm" type="number"
                                           inputmode="decimal" step="0.5"
                                           min="{{ SavePrinterSettingsRequest::MIN_MEDIA_MM }}"
                                           max="{{ SavePrinterSettingsRequest::MAX_MEDIA_MM }}"
                                           value="{{ old('sheet_media_width_mm', $sheet['width']) }}"
                                           x-model="mediaWidth"
                                           x-bind:required="isSheet()"
                                           class="input-base @error('sheet_media_width_mm') border-error-border @enderror">
                                    @error('sheet_media_width_mm')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                                </x-ui.field>

                                <x-ui.field label="Tinggi kertas stiker (mm)" name="sheet_media_height_mm"
                                            hint="Diukur dari sisi atas ke sisi bawah, dalam milimeter.">
                                    <input id="sheet_media_height_mm" name="sheet_media_height_mm" type="number"
                                           inputmode="decimal" step="0.5"
                                           min="{{ SavePrinterSettingsRequest::MIN_MEDIA_MM }}"
                                           max="{{ SavePrinterSettingsRequest::MAX_MEDIA_MM }}"
                                           value="{{ old('sheet_media_height_mm', $sheet['height']) }}"
                                           x-model="mediaHeight"
                                           x-bind:required="isSheet()"
                                           class="input-base @error('sheet_media_height_mm') border-error-border @enderror">
                                    @error('sheet_media_height_mm')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                                </x-ui.field>
                            </div>

                            {{--
                                Celah punya checkbox dan angka, bukan cuma angka.

                                Dua kolom itu satu keputusan: "ada celah" menentukan
                                apakah angkanya dipakai atau diabaikan. Kalau Owner
                                mematikan celah, angkanya sengaja disimpan supaya
                                tidak perlu mengetik ulang -- jadi kolomnya
                                dinonaktifkan, bukan dikosongkan.
                            --}}
                            <div class="mt-4">
                                <label class="flex items-start gap-2 text-label-md text-text-main">
                                    <input type="checkbox" name="sheet_has_gap" value="1"
                                           x-model="hasGap"
                                           class="mt-0.5 h-4 w-4 rounded border-border-strong text-accent-base focus:ring-accent-base">
                                    <span>
                                        Ada celah antar label
                                        <span class="block text-label-sm text-text-subtle">
                                            Celah ikut dipakai untuk menghitung jumlah kolom dan baris. Matikan kalau
                                            label-labelnya menempel tanpa jarak.
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <x-ui.field label="Celah antar label (mm)" name="sheet_gap_mm"
                                            hint="Jarak antar label. Diabaikan saat celah dimatikan.">
                                    <input id="sheet_gap_mm" name="sheet_gap_mm" type="number"
                                           inputmode="decimal" step="0.125"
                                           min="{{ SheetGridCalculator::MIN_GAP_MM }}"
                                           max="{{ SheetGridCalculator::MAX_GAP_MM }}"
                                           value="{{ old('sheet_gap_mm', $sheet['gap']) }}"
                                           x-model="gap"
                                           x-bind:required="isSheet() && hasGap"
                                           x-bind:disabled="! hasGap"
                                           class="input-base disabled:cursor-not-allowed disabled:bg-surface-subtle @error('sheet_gap_mm') border-error-border @enderror">
                                    @error('sheet_gap_mm')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                                </x-ui.field>
                            </div>
                        </div>


                        {{--
                            Ringkasan grid di bawah kolom kertas, bukan di halaman
                            cetak.

                            Ringkasan ini mengikuti angka yang sedang diketik,
                            tapi angkanya tetap dihitung server -- bukan diulang
                            di JavaScript. Alasannya bukan sekadar soal teknis:
                            mengulangi perhitungan yang sama di dua bahasa berarti
                            ada dua versi rumus yang bisa berbeda -- lalu Owner
                            melihat "6 x 8 = 48 label", menekan Simpan, dan
                            mendapat cetakan dengan 4 label per halaman tanpa
                            ada yang gagal.

                            Karena perhitungannya di server, form ini hanya
                            menampilkan jawabannya: berapa kolom, berapa baris,
                            dan kalau angkanya ditolak, kolom mana yang harus
                            diperbaiki. Angka yang sudah disimpan tetap jadi
                            cadangan, jadi form ini tetap berguna kalau
                            permintaan preview-nya gagal.
                        --}}
                        <div x-show="isSheet()" x-cloak>
                            <template x-if="previewState === 'pending' || previewState === 'loading'">
                                <x-ui.banner tone="info">
                                    Menghitung grid dari angka yang sedang diketik&hellip;
                                </x-ui.banner>
                            </template>

                            <template x-if="previewState === 'failed'">
                                <x-ui.banner tone="warning">
                                    <span>
                                        Ringkasan grid tidak bisa dihitung sekarang, jadi yang tampil masih
                                        angka yang <strong>sudah disimpan</strong>:
                                        @if ($sheet['grid']['fits'])
                                            {{ $sheet['grid']['summary'] }}.
                                        @else
                                            kertas tersimpan tidak muat untuk grid label ini.
                                        @endif
                                    </span>
                                </x-ui.banner>
                            </template>

                            <template x-if="previewState === 'ready' && ! previewReadable">
                                <x-ui.banner tone="info">
                                    <span x-text="'Ringkasan menunggu ' + previewMissingLabels().join(' dan ') + ' terisi.'"></span>
                                </x-ui.banner>
                            </template>

                            <template x-if="previewState === 'ready' && previewReadable && previewFits">
                                <div>
                                    <x-ui.banner tone="info">
                                        <span>
                                            Grid untuk ukuran label ini: <span x-text="previewSummary"></span>.
                                            Dialog cetak harus dipilih Custom <span x-text="previewPageSize"></span>,
                                            Margins: None, Scale: 100%.
                                        </span>
                                    </x-ui.banner>

                                    <p class="mt-2 text-label-sm text-text-subtle">
                                        Sisa tinggi kertas yang tidak dipakai:
                                        <span x-text="previewSlack"></span> pada baris terakhir. Sisa itu tidak
                                        bisa dipakai label tambahan -- jumlah kolom dan baris sudah dibulatkan ke
                                        bawah.
                                    </p>
                                </div>
                            </template>

                            <template x-if="previewState === 'ready' && previewReadable && ! previewFits">
                                <x-ui.banner tone="warning">
                                    <span>
                                        Kertas dan celah ini tidak bisa dipakai untuk grid label tersebut:
                                        <span class="mt-1 block" x-text="previewErrorList().join(' ')" x-cloak></span>
                                    </span>
                                </x-ui.banner>
                            </template>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn-primary">Simpan Pengaturan Label</button>
                            <a href="{{ route('inbound.cetak-label.test-print') }}" class="btn-secondary">Uji Cetak</a>
                        </div>

                        {{--
                            Uji Cetak membaca SETELAN YANG SUDAH DISIMPAN, bukan
                            isi form yang belum disimpan. Itu pilihan yang
                            benar -- yang diuji harus benar-benar konfigurasi yang
                            sedang aktif -- tapi harus disebut, karena form ini
                            memuat input yang bisa diubah tanpa disimpan, dan
                            semuanya kelihatan "siap" padahal belum berlaku.

                            Peringatan ini muncul tepat setelah nilai berubah,
                            jadi Owner tidak perlu saving lalu gagal lalu
                            membaca pesan penolakan untuk tahu urutannya salah.
                            Ringkasan grid di atas sudah tidak punya masalah ini:
                            ia mengikuti angka yang sedang diketik.
                        --}}
                        <div x-show="isDirty()" x-cloak>
                            <x-ui.banner tone="warning">
                                Ringkasan grid di atas sudah mengikuti angka yang kamu ketik, tapi Uji Cetak
                                masih memakai ukuran, celah, dan mode kertas yang <strong>sudah disimpan</strong>.
                                Nilai di form ini belum berlaku sampai kamu menekan Simpan Pengaturan Label.
                            </x-ui.banner>
                        </div>

                        <p class="text-label-sm text-text-subtle">
                            Ukuran yang aktif ikut dipakai oleh uji cetak di halaman inbound, jadi
                            setelah menyimpan, buka Uji Cetak untuk memastikan ukurannya pas sebelum
                            label asli dicetak.
                        </p>
                    </form>
                @else
                    {{-- Staff boleh melihat ukuran yang sedang aktif untuk tahu kertas apa yang diroll, tapi tidak boleh mengubahnya. --}}
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-label-md text-text-muted">Ukuran label bawaan</dt>
                            <dd class="text-body-md font-medium text-text-strong">{{ $labelTemplates[$defaultTemplate]['label'] }}</dd>
                            <p class="text-label-sm text-text-subtle">{{ $labelTemplates[$defaultTemplate]['description'] }}</p>
                            {{-- Staff perlu tahu ini juga: kalau ukuran aktifnya
                                 QR-saja, laporan "harga tidak tercetak" akan
                                 mencari jawabannya di tempat lain. Peringatan
                                 yang sama seperti di form Owner, tanpa field
                                 yang bisa diubah. --}}
                            @if ($defaultTemplate === LabelTemplate::QrOnly->value)
                                <div class="mt-2">
                                    <x-ui.banner tone="warning">
                                        Ukuran aktif ini mencetak <strong>QR, SKU, dan harga</strong>;
                                        tanpa nama produk dan kondisi.
                                    </x-ui.banner>
                                </div>
                            @endif
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Sisi QR</dt>
                            <dd class="text-body-md font-medium text-text-strong">
                                {{ $qrSideCm !== null ? number_format($qrSideCm, 2, ',', '.').' cm' : 'Bawaan ukuran label ('.number_format($labelTemplates[$defaultTemplate]['qrSideCm'], 2, ',', '.').' cm)' }}
                            </dd>
                        </div>
                        {{--
                            Mode kertas masuk ke sini juga, bukan hanya ke form
                            Owner. Staff yang meng-roll label dan yang
                            memecah kertas stiker melakukan pekerjaan berbeda --
                            "salah pilih kertas" adalah laporan yang akan dicari
                            jawabannya di pengaturan, jadi jawaban itu harus
                            terlihat oleh orang yang tidak bisa mengubahnya.
                        --}}
                        <div>
                            <dt class="text-label-md text-text-muted">Mode kertas</dt>
                            <dd class="text-body-md font-medium text-text-strong">
                                {{ $paperModeLabel }}
                            </dd>
                            @if ($sheetLabel)
                                <p class="text-label-sm text-text-subtle">
                                    {{ $sheetLabel }}
                                </p>
                            @endif
                        </div>
                    </dl>
                    <p class="text-label-sm text-text-subtle">Hanya Owner yang bisa mengubah pengaturan ini.</p>
                @endcan
            </div>
        </x-ui.section-card>

        <x-ui.section-card title="Printer Struk (POS-01)">
            {{--
                Satu `x-data` untuk seluruh kartu: select kertas, chip kertas,
                dan pratinjau berbagi state `paper` yang sama, jadi Owner melihat
                pratinjau berganti di saat yang sama dengan select -- sebelum
                menekan Simpan. `savedPaper` dipakai badge "belum disimpan":
                pratinjau boleh bergerak bebas, tapi kartu harus jujur kalau
                yang tampil belum berlaku.

                `|| '80mm'` di tiap ekspresi adalah bawaan ketika belum pernah
                menyimpan (nilainya `null`): select menampilkan "Belum disimpan",
                pratinjau tetap menunjukkan struk 80 mm sebagai default.
            --}}
            <div class="space-y-5"
                 x-data="{
                     paper: @js(old('paper', $paper?->value)),
                     savedPaper: @js($paper?->value),
                     method: @js(old('method', $method?->value)),
                 }">
                <div class="flex items-center justify-between">
                    <div>
                        {{--
                            Nama printer memakai kertas yang benar-benar aktif,
                            bukan tulisan tetap. "Struk 80mm" sebelumnya ditulis
                            mati di sini, jadi begitu Owner menyimpan 58mm, kartu
                            ini tetap berbunyi 80mm -- padahal justru kartu ini
                            yang dibaca Staff untuk tahu kertas apa yang diroll.
                            Agar tidak berbohong, teksnya ikut dari setelan.
                        --}}
                        <p class="text-body-md font-medium text-text-strong">{{ $paper?->label() ?? 'Struk 80 mm' }} · Ethernet</p>
                        <p class="text-label-sm text-text-muted">Terakhir online 13:40 WIB</p>
                    </div>
                    <x-ui.badge-status type="error">OFFLINE</x-ui.badge-status>
                </div>
                <x-ui.banner tone="warning">
                    Printer struk offline <b>2 jam</b>. Transaksi tetap berjalan (antrean lokal tersimpan).
                </x-ui.banner>
                <div class="flex items-center justify-between rounded-lg border border-border-subtle bg-canvas px-4 py-3">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">Laci Kas (Cash Drawer)</p>
                        <p class="text-label-sm text-text-muted">Trigger pada transaksi TUNAI</p>
                    </div>
                    <div class="inline-flex items-center gap-2" x-data="{ casher: true }">
                        <button type="button"
                                class="relative h-6 w-11 rounded-full transition"
                                :class="casher ? 'bg-primary' : 'bg-border-strong'"
                                @click="casher = !casher">
                            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-surface-lowest shadow transition" :class="casher ? 'left-[22px]' : 'left-0.5'"></span>
                        </button>
                        <span class="text-label-md text-text-muted" x-text="casher ? 'Aktif' : 'Nonaktif'"></span>
                    </div>
                </div>

                {{--
                    Kertas dokumen GLOBAL (`print.paper`), bukan milik satu
                    halaman: kertas ini menentukan isi halaman bukti terima
                    titipan DAN struk kasir POS, jadi pratinjau di bawah
                    adalah struk kasir -- dokumen yang paling sering
                    dicetak dengan kertas ini.

                    Nilai yang dipilih operator tetap hanya bawaan: saat membuka
                    dialog print, browser yang tahu kertas yang benar-benar ada
                    di laci printer. Yang disimpan di sini adalah nilai bakanya,
                    supaya halaman cetak tidak bergantung pada tebakan tiap
                    kali dibuka.

                    Batasannya Owner, sama seperti ukuran label: kertas ini
                    menentukan isi cetakan yang dibaca penitip.
                --}}
                <div class="space-y-6 border-t border-border-subtle pt-5">
                @can('owner-only')
                    <form method="POST" action="{{ route('setting.perangkat.struk.update') }}"
                          class="max-w-2xl space-y-3">
                        @csrf
                        @method('PUT')

                        <x-ui.field label="Ukuran kertas dokumen" name="paper"
                                    hint="Bawaan saat Staff membuka halaman bukti terima titipan.">
                            <select id="paper" name="paper" x-model="paper"
                                    class="input-base @error('paper') border-error-border @enderror"
                                    required>
                                <option value="" disabled>Belum disimpan — memakai Struk 80 mm</option>
                                @foreach ($papers as $paperOption)
                                    <option value="{{ $paperOption['value'] }}"
                                            @selected(old('paper', $paper?->value) === $paperOption['value'])>
                                        {{ $paperOption['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('paper')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                        </x-ui.field>

                        <x-ui.field label="Cara cetak" name="method"
                                    hint="Thermal mengirim data langsung ke printer tanpa dialog print; browser memakai dialog cetak bawaan perangkat.">
                            <select id="method" name="method" x-model="method"
                                    class="input-base @error('method') border-error-border @enderror"
                                    required>
                                @foreach ($methods as $methodOption)
                                    <option value="{{ $methodOption['value'] }}"
                                            @selected(old('method', $method?->value) === $methodOption['value'])>
                                        {{ $methodOption['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('method')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                            <p class="mt-2 text-label-sm text-text-subtle">
                                Cetak thermal memakai Web Bluetooth pada perangkat yang membuka
                                halaman, dan hanya tersedia untuk kertas struk (58/80 mm).
                                Kalau printer tidak mendukung Bluetooth yang bisa diakses
                                peramban, pilih &ldquo;Cetak Browser&rdquo;.
                            </p>
                        </x-ui.field>

                        <x-ui.field label="Perilaku setelah commit" name="post_commit_mode"
                                    hint="Mengatur apa yang terjadi setelah tombol &quot;Commit &amp; Buat Label&quot; ditekan.">
                            <select id="post_commit_mode" name="post_commit_mode"
                                    class="input-base @error('post_commit_mode') border-error-border @enderror">
                                <option value="auto_print"
                                        @selected((string) old('post_commit_mode', $postCommitMode ?? app(\App\Services\Consignment\ReceiptPrinterSettings::class)->postCommitMode()) === 'auto_print')>
                                    Cetak langsung (otomatis)
                                </option>
                                <option value="preview"
                                        @selected((string) old('post_commit_mode', $postCommitMode ?? app(\App\Services\Consignment\ReceiptPrinterSettings::class)->postCommitMode()) === 'preview')>
                                    Tampilkan review bukti terima (manual cetak)
                                </option>
                                <option value="go_to_labels"
                                        @selected((string) old('post_commit_mode', $postCommitMode ?? app(\App\Services\Consignment\ReceiptPrinterSettings::class)->postCommitMode()) === 'go_to_labels')>
                                    Langsung ke cetak label
                                </option>
                            </select>
                            @error('post_commit_mode')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                        </x-ui.field>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="btn-primary">Simpan Pengaturan Struk</button>
                            <button type="button" onclick="window.__thermalTest && window.__thermalTest()"
                                    class="btn-secondary">
                                Uji Cetak Thermal
                            </button>
                        </div>

                        <p class="text-label-sm text-text-subtle">
                            Saat &ldquo;Cara cetak&rdquo; disetel ke <strong>Thermal</strong>, kertas
                            A4 tidak bisa dikirim ke printer; halaman bukti terima otomatis memakai
                            cetak browser. Uji Cetak Thermal butuh halaman HTTPS dan peramban yang
                            mendukung Web Bluetooth.
                        </p>
                    </form>
                @else
                    <div class="max-w-2xl space-y-3">
                        <div>
                            <dt class="text-label-md text-text-muted">Ukuran kertas dokumen</dt>
                            <dd class="text-body-md font-medium text-text-strong">
                                {{ $paper?->label() ?? 'Struk 80 mm (bawaan)' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Cara cetak</dt>
                            <dd class="text-body-md font-medium text-text-strong">
                                {{ $method?->label() ?? 'Cetak Browser (bawaan)' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-label-md text-text-muted">Perilaku setelah commit</dt>
                            <dd class="text-body-md font-medium text-text-strong">
                                @php($currentMode = $postCommitMode ?? app(\App\Services\Consignment\ReceiptPrinterSettings::class)->postCommitMode())
                                {{ $currentMode === 'preview' ? 'Tampilkan review bukti terima (manual cetak)' : ($currentMode === 'go_to_labels' ? 'Langsung ke cetak label' : 'Cetak langsung (otomatis)') }}
                            </dd>
                        </div>
                        <p class="text-label-sm text-text-subtle">Hanya Owner yang bisa mengubah pengaturan ini.</p>
                    </div>
                @endcan

                    {{--
                        Pratinjau struk kasir: ketiga ukuran kertas sudah dirender
                        sekali oleh server dari nota contoh, lalu ditukar oleh
                        state `paper` yang sama dengan select di atas.
                        Owner bisa mengubah kertas dan langsung melihat efeknya
                        tanpa menyimpan; Staff tidak punya hak ubah, tapi tetap
                        bisa membaca kertas seperti apa struk yang berlaku.
                    --}}
                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-body-md font-medium text-text-strong">Pratinjau struk</p>
                                <p class="text-label-sm text-text-muted">Nota contoh, bukan transaksi nyata.</p>
                            </div>
                            <div class="inline-flex items-center gap-0.5 rounded-lg bg-canvas p-1"
                                 role="group" aria-label="Ukuran kertas pratinjau">
                                @foreach ($papers as $paperOption)
                                    <button type="button"
                                            class="rounded-md px-3 py-1.5 text-label-md transition"
                                            :class="(paper || '80mm') === @js($paperOption['value'])
                                                ? 'bg-text-strong text-white shadow-sm'
                                                : 'text-text-muted hover:text-text-strong'"
                                            @click="paper = @js($paperOption['value'])">
                                        {{ $paperOption['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <p class="text-label-sm text-warning-text"
                           x-show="paper !== savedPaper" x-cloak>
                            Pratinjau belum disimpan — yang sedang berlaku tetap kertas yang tersimpan.
                        </p>

                        <div class="max-h-[70vh] overflow-auto rounded-lg border border-border-strong bg-surface-lowest p-4 sm:p-6">
                            @foreach ($strukPreviews as $paperValue => $previewSheet)
                                {{--
                                    Pembungkus selebar kertas aslinya: A4 tampil
                                    190 mm dan digulir horizontal kalau panel
                                    sempit, bukan menyusut sampai kolomnya tidak
                                    terbaca. Margin `auto` pada elemen yang lebih
                                    lebar dari wadahnya menghasilkan 0, jadi tidak
                                    ada yang terpotong di kiri.
                                --}}
                                <div x-show="(paper || '80mm') === @js($paperValue)" x-cloak
                                     class="mx-auto"
                                     style="width: {{ $previewSheet->page()['width'] }}">
                                    @include('components.pos.struk-sheet', ['sheet' => $previewSheet, 'embedded' => false])
                                </div>
                            @endforeach
                        </div>

                        <div x-show="(method || 'browser') === 'thermal' && (paper || '80mm') === 'a4'" x-cloak>
                            <x-ui.banner tone="warning">
                                Cara cetak <strong>Thermal</strong> hanya menerima kertas struk
                                (58/80 mm). Dengan kertas A4, halaman ini tetap tercetak lewat
                                cetak browser.
                            </x-ui.banner>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.section-card>
    </div>

    <x-ui.section-card title="Sync Offline (Pending Queue)">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-info-bg text-info-text">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h5M20 20v-5h-5m-9.5-5.5A9 9 0 0019.5 19M4.66 15A9 9 0 017 4.66a9 9 0 007.34.5"/></svg>
                </span>
                <div>
                    <p class="text-body-md font-semibold text-text-strong">Endpoint / perangkat pengumpul</p>
                    <p class="text-label-sm text-text-muted">POS-CP2 · terakhir sync 13:55 · 2 nota antrean</p>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" class="btn-secondary" @click="$store.toast.push('Sync sekarang (mock)', 'success')">Sync Sekarang</button>
            </div>
        </div>
    </x-ui.section-card>
</div>
