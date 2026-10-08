@php
    use App\Enums\PaperSize;

    $papersOptions = collect(PaperSize::cases())
        ->map(fn (PaperSize $paper) => [
            'value' => $paper->value,
            'label' => $paper->label(),
        ])
        ->toArray();

    $previewSheets = $previews ?? [];
    if (empty($previewSheets)) {
        foreach (PaperSize::cases() as $paper) {
            $previewSheets[$paper->value] = new \App\Services\Pos\SaleStrukSheet(
                $sale,
                $paper,
                auth()->user(),
            );
        }
    }

    $activePaperValue = $sheet->paper()->value;
    $baseThermalUrl = route('pos.struk.thermal', ['sale' => $sale]);
    $initialChange = $sheet->changeDue;
    $initialQuery = [];
    if ($initialChange !== null) {
        $initialQuery[] = 'change=' . $initialChange;
    }
    // Only add paper if different from global (same as active/global here initially)
    if ($activePaperValue !== $sheet->paper()->value && $sheet->paper()->value) {
        // not expected initially, but keep safe
    }
    // For initial state, paper != global only if preview differs? global is sheet.paper(); active is preview selection
    // But we set globalPaper = activePaperValue (preview). So initially same → no paper.
    $initialThermalUrl = $baseThermalUrl;
    if (! empty($initialQuery)) {
        $initialThermalUrl .= '?' . implode('&', $initialQuery);
    }
@endphp

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>[x-cloak]{display:none!important}</style>
    <title>Struk POS #{{ $sale->receipt_no }}</title>
    @vite('resources/css/app.css')
    @vite('resources/css/receipt.css')
</head>

<body class="bg-white font-sans text-[10pt] text-slate-900 print:bg-white" x-data="{
    paper: @js($activePaperValue),
    change: @js($sheet->changeDue),
    baseThermal: @js(route('pos.struk.thermal', ['sale' => $sale])),
    globalPaper: @js($activePaperValue),
    thermalUrl(p) {
        let url = this.baseThermal;
        const q = [];
        if (this.change !== null) {
            q.push('change=' + this.change);
        }
        if (p && p !== this.globalPaper) {
            q.push('paper=' + encodeURIComponent(p));
        }
        return q.length ? url + '?' + q.join('&') : url;
    }
}">
    <div class="no-print sticky top-0 z-50 border-b border-slate-200 bg-white/98 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/90 print:hidden">
        <div class="mx-auto flex w-full max-w-screen-lg flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex flex-1 flex-col gap-1 sm:flex-row sm:items-center sm:gap-4">
                <div class="flex items-center gap-3">
                    <label for="paper-preview" class="text-sm font-medium text-slate-700">
                        Kertas pratinjau
                    </label>
                    <select id="paper-preview" name="paper-preview" x-model="paper"
                        class="input-base h-9 min-w-[160px] py-1.5 text-sm">
                        @foreach ($papersOptions as $option)
                            <option value="{{ $option['value'] }}">
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-slate-500 sm:mt-0">
                    Untuk arsip permanen, pilih <span class="font-medium">Save as PDF</span> pada dialog
                    Cetak/Cetak ke PDF.
                    @if ($method->value === 'thermal' && $sheet->paper->isThermal())
                        Cara cetak global saat ini <span class="font-medium">Thermal</span>: tombol Cetak
                        Thermal mengirim langsung ke printer lewat Bluetooth.
                    @endif
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 sm:gap-3">
                <button type="button" id="thermal-print"
                        data-url="{{ $initialThermalUrl }}"
                        :data-url="thermalUrl(paper)"
                        :disabled="paper === 'a4'"
                        onclick="window.__thermalPrint && window.__thermalPrint(this.dataset.url, {})"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-800 bg-slate-800 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-40">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 2a6 6 0 00-4.95 9.5 4.5 4.5 0 007 2.5.75.75 0 01-1.5 0A6 6 0 0010 2zm0 0a6 6 0 011.16 11.87.75.75 0 01-.49-1.41A4.5 4.5 0 0014.5 9H15a1.5 1.5 0 100-3h-.31A6 6 0 0010 2z" clip-rule="evenodd" />
                    </svg>
                    Cetak Thermal
                </button>
                <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-md border border-blue-600 bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5 2a2 2 0 00-2 2v2h14V4a2 2 0 00-2-2H5zm0 16a2 2 0 01-2-2v-3h4v1a1 1 0 001 1h4a1 1 0 001-1v-1h4v3a2 2 0 01-2 2H5zm8-10a1 1 0 10-2 0v3a1 1 0 102 0V8zm-6 0a1 1 0 10-2 0v3a1 1 0 102 0V8z" clip-rule="evenodd" />
                    </svg>
                    Cetak
                </button>
                <a href="{{ route('pos.nota', ['sale' => $sale]) }}"
                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H8.81l3.72 3.72a.75.75 0 11-1.06 1.06l-5-5a.75.75 0 010-1.06l5-5a.75.75 0 111.06 1.06L8.81 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd" />
                    </svg>
                    Kembali ke Nota
                </a>
            </div>
        </div>
    </div>

    @if ($thermalError)
        <div class="no-print mx-auto mt-4 w-full max-w-screen-lg px-4 sm:px-6">
            <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                Cetak thermal gagal: {{ $thermalError }} Pakai tombol <strong>Cetak</strong> (browser) sebagai gantinya.
            </p>
        </div>
    @endif

    <main class="flex flex-col items-center px-4 py-6 pt-24 print:px-0 print:py-0 sm:pt-28">
        @foreach ($previewSheets as $value => $previewSheet)
            <div x-show="paper === @js($value)" x-cloak class="print-area flex justify-center px-2 py-4 print:px-0 print:py-0">
                <x-receipt.document :title="'Struk POS '.$previewSheet->docNo()" :page-size="$previewSheet->page()['size']">
                    <div class="receipt-toolbar receipt-toolbar--preview no-print mb-2 flex justify-center">
                        <p class="receipt-toolbar__note text-center text-xs text-slate-500">
                            Pratinjau: {{ $previewSheet->label() }}.
                            @if ($previewSheet->paper->isThermal())
                                Kalau struk terpotong miring, matikan <strong>Headers and footers</strong> pada dialog Cetak.
                            @else
                                Untuk arsip permanen, pilih <strong>Save as PDF</strong> pada dialog Cetak/Cetak ke PDF.
                            @endif
                        </p>
                    </div>

                    {{-- Partial yang sama dengan pratinjau di layar kasir: satu
                         sumber isi untuk layar, kertas, dan byte ESC/POS. --}}
                    @include('components.pos.struk-sheet', ['sheet' => $previewSheet, 'embedded' => false])
                </x-receipt.document>
            </div>
        @endforeach
    </main>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => {
                window.setTimeout(() => {
                    const btn = document.getElementById('thermal-print');
                    const tryThermal = @js($method->value === 'thermal') && btn && !btn.disabled;
                    if (tryThermal && window.__thermalPrint) {
                        window.__thermalPrint(btn.dataset.url, { onFailed: () => window.print() });
                    } else {
                        window.print();
                    }
                }, 250);
            }, { once: true });
        </script>
    @endif
    @vite('resources/js/app.js')
</body>

</html>