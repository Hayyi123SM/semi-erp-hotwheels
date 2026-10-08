<?php

namespace App\Http\Controllers\Pages;

use App\Enums\InputMethod;
use App\Enums\PaperSize;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\Pos\Checkout;
use App\Services\Pos\CheckoutLine;
use App\Services\Pos\CheckoutPayment;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\SaleStrukSheet;
use App\Services\Pos\ShiftAlreadyClosedException;
use App\Services\Pos\ShiftAlreadyOpenException;
use App\Services\Pos\ShiftService;
use App\Services\Print\PrintSettings;
use App\Services\Print\Thermal\PosStrukRenderer;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\DeviceId;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PosController extends Controller
{
    /**
     * Berapa shift tertutup terakhir yang ditampilkan sebagai konteks.
     *
     * Cukup sepuluh: halaman ini dipakai sambil menghitung uang, dan daftar yang
     * panjang tidak menambah kejelasan -- hanya memendorong orang untuk mencari di
     * laporan yang memang tempatnya.
     */
    private const RECENT_SHIFT_LIMIT = 10;

    /**
     * Kolom yang boleh jadi `ORDER BY` di riwayat transaksi.
     *
     * Satu daftar, dipakai dua kali: sebagai whitelist `DataTable`, dan untuk
     * menentukan apakah urutan bawaan perlu dipasang. Kalau keduanya terpisah,
     * suatu saat keduanya bisa berbeda -- `sort` diabaikan diam-diam sementara
     * urutan bawaan masih ditambah, dan tidak ada yang salahnya terlihat dari
     * tampilannya.
     */
    private const SALE_SORTABLE = ['sold_at', 'receipt_no', 'total', 'status'];

    /**
     * Layar kasir, dengan shift yang sedang berjalan di kepalanya.
     *
     * Shift dan identitas perangkat diteruskan ke view supaya kasir dan pemeriksa
     * melihat shift yang sama dengan yang akan dipakai rekonsiliasi. Halaman yang
     * menampilkan shift sendiri-sendiri -- satu di sini, satu di halaman Shift --
     * membuat kasir menutup shift dengan angka yang tidak cocok dengan yang ada
     * di kepala kasir, dan selisih yang muncul kemudian adalah milik salah satu
     * dari dua layar itu, bukan milik uangnya.
     *
     * `$shift` boleh `null`: kasir yang belum membuka shift tetap boleh sampai ke
     * halaman ini untuk membukanya, dan halaman yang menyuruhnya melakukan itu lebih
     * baik daripada halaman yang menampilkan keranjang yang nanti tidak masuk ke
     * rekap shift mana pun.
     */
    public function kasir(ShiftService $shifts)
    {
        return $this->page('pages.pos.kasir', [
            'shift' => $shifts->currentFor(request()->user()),
            'deviceId' => DeviceId::current(),
        ], 'Kasir');
    }

    /**
     * Selesaikan penjualan: potong stok, catat uang, terbitkan nomor struk.
     *
     * Semua aturan ada di {@see CheckoutService}; yang di sini hanya menerjemahkan
     * permintaan HTTP menjadi nilai-nilai yang bebas HTTP, dan membalas dengan
     * angka yang dibutuhkan layar kasir untuk menyelesaikan layarnya sendiri --
     * nomor struk untuk ditampilkan, kembalian untuk dihitung ulang di depan
     * pelanggan.
     *
     * Balasan juga membawa struktur untuk dialog struk yang muncul setelah bayar:
     * `struk_html` (pratinjau yang ditanam ke dialog), `thermal_url`/`print_url`
     * untuk tombol Cetak, dan pengaturan kertas/cara cetak yang sedang berlaku.
     * Kembalian TIDAK tersimpan di database, jadi URL dan pratinjau membawanya
     * sebagai query param saat pembayaran tunai -- cetakan ulang dari riwayat
     * tidak lagi punya angkanya.
     *
     * `POST`, bukan `GET`: ini membuat uang berpindah dan stok berkurang.
     */
    public function store(CheckoutRequest $request, CheckoutService $service, PrintSettings $printSettings): JsonResponse
    {
        $checkout = new Checkout(
            clientSaleId: (string) $request->validated('client_sale_id'),
            cashier: $request->user(),
            deviceId: DeviceId::current(),
            lines: array_map(
                fn (array $line): CheckoutLine => new CheckoutLine(
                    lotId: (int) $line['lot_id'],
                    qty: (int) $line['qty'],
                    inputMethod: InputMethod::from($line['input_method'] ?? InputMethod::Scan->value),
                ),
                $request->validated('items', []),
            ),
            payments: array_map(
                fn (array $payment): CheckoutPayment => new CheckoutPayment(
                    method: PaymentMethod::from($payment['method']),
                    amount: (int) $payment['amount'],
                    reference: $payment['reference'] ?? null,
                ),
                $request->validated('payments', []),
            ),
            tender: $request->validated('tender') === null ? null : (int) $request->validated('tender'),
        );

        $sale = $service->checkout($checkout);

        return response()->json([
            'ok' => true,
            'sale_id' => $sale->id,
            'receipt_no' => $sale->receipt_no,
            'total' => $sale->total,
            'change' => $this->change($checkout, $sale),
            ...$this->strukPayload($sale, $request, $printSettings, $checkout),
        ]);
    }

    /**
     * Isi dialog struk untuk nota yang baru saja jadi.
     *
     * Dipisah dari `store()` supaya daftar kunci jawabannya bisa diuji tanpa
     * menyusun seluruh balasan checkout, dan satu-satunya pemanggilnya adalah
     * layar kasir (lewat `store()`).
     *
     * @return array{struk_html: string, thermal_url: string, print_url: string, print_method: string, paper: string}
     */
    private function strukPayload(Sale $sale, Request $request, PrintSettings $printSettings, Checkout $checkout): array
    {
        $sale->loadMissing(['items.lot.product.series', 'payments', 'cashier', 'shift']);

        // Tunai selalu menghasilkan angka kembalian penyebutannya (0 pun untuk
        // uang pas); pembayaran non-tunai tidak punya kembalian sama sekali.
        $changeDue = $checkout->paysWithCash() ? $this->change($checkout, $sale) : null;

        $sheet = new SaleStrukSheet(
            sale: $sale,
            paper: $printSettings->paper(),
            printedBy: $request->user(),
            changeDue: $changeDue,
        );

        $query = ['auto' => 1];

        if ($changeDue !== null) {
            $query['change'] = $changeDue;
        }

        return [
            'struk_html' => view('components.pos.struk-sheet', ['sheet' => $sheet, 'embedded' => true])->render(),
            'thermal_url' => route('pos.struk.thermal', $sale).$this->changeQuery($changeDue),
            'print_url' => route('pos.struk', $sale).'?'.http_build_query($query),
            'print_method' => $printSettings->method()->value,
            'paper' => $printSettings->paper()->value,
        ];
    }

    /**
     * Query `change` untuk URL thermal, hanya saat layar kasir punya angkanya.
     */
    private function changeQuery(?int $changeDue): string
    {
        return $changeDue === null ? '' : '?change='.$changeDue;
    }

    /**
     * Kembalian untuk nota ini.
     *
     * Dihitung dari total yang tercatat, bukan dari total yang dikirim layar:
     * kalau server mengoreksi harga, kembalian yang dihitung di depan pelanggan
     * harus mengikuti koreksi itu -- bukan angka yang lahir dari harga yang
     * tadi sempat tertulis di layar.
     *
     * Nol untuk pembayaran non-tunai, berapa pun uang yang kebetulan dikirim
     * bersama permintaannya: QRIS tidak menghasilkan kembalian, dan angka
     * kembalian di sana akan dibaca kasir sebagai uang yang harus dihitung.
     */
    private function change(Checkout $checkout, Sale $sale): int
    {
        if (! $checkout->paysWithCash() || $checkout->tender === null) {
            return 0;
        }

        return max(0, $checkout->tender - $sale->total);
    }

    /**
     * Riwayat transaksi dari tabel `sales` yang sebenarnya.
     *
     * Halaman ini dulunya menampilkan baris hard-coded dari `MockData`, lengkap
     * dengan angka total yang tidak pernah berubah. Itu menutupi dua hal yang
     * paling perlu diketahui kasir: nota mana yang benar-benar tercatat, dan mana
     * yang masih datang dari perangkat offline yang belum sinkron -- status
     * `synced_at` yang kosong membuat daftar ini terlihat utuh padahal belum.
     *
     * Filter diterapkan ke query SEBELUM `DataTable` dibuat. `DataTable` menyimpan
     * salinan builder-nya sendiri, jadi menyaringnya sesudahnya tidak akan mengubah
     * apa pun: toolbar akan terlihat aktif sementara tabelnya tetap menampilkan
     * semuanya.
     */
    public function riwayat(Request $request)
    {
        $query = $this->salesQuery($request);

        $table = DataTable::for($request, $query, $request->user())
            ->searchable(['receipt_no', 'cashier.name', 'device_id'])
            ->orSearchUsing(function (Builder $query, string $search): void {
                // Kode pada nota bisa diketik tanpa tanda hubung (`POS20260115`
                // untuk `POS-2026-0115`), jadi dicocokkan juga tanpa separator.
                if (is_numeric($search)) {
                    $query->orWhere('sales.id', (int) $search);
                }
            })
            ->searchPlaceholder('Cari nota POS, kasir, atau device...')
            ->sortable(self::SALE_SORTABLE)
            ->perPage([25, 50, 100])
            ->columns([
                Column::make('receipt_no', 'Nota')
                    ->sortable('receipt_no')
                    ->priority(1)
                    ->card('title')
                    ->mono(),
                Column::make('sold_at', 'Waktu', format: 'datetime')
                    ->sortable('sold_at')
                    ->priority(1)
                    ->card('meta'),
                Column::make('cashier.name', 'Kasir')
                    ->priority(2)
                    ->card('subtitle')
                    ->value(fn (Sale $sale): string => $sale->cashier?->name ?? 'Sistem')
                    ->render(fn (Sale $sale) => e($sale->cashier?->name ?? 'Sistem')),
                Column::make('methods', 'Metode')
                    ->priority(2)
                    ->card('meta')
                    ->value(fn (Sale $sale): string => $sale->methodSummary())
                    ->render(fn (Sale $sale) => e($sale->methodSummary())),
                Column::make('items_count', 'Item', align: 'center')
                    ->priority(2)
                    ->card('meta')
                    ->value(fn (Sale $sale): string => (string) $sale->items_count)
                    ->render(fn (Sale $sale) => '<span class="tabular-nums">'.e((string) $sale->items_count).'</span>'),
                Column::make('total', 'Total', format: 'rupiah', align: 'right')
                    ->sortable('total')
                    ->priority(1)
                    ->card('price')
                    ->class('font-semibold text-text-strong tabular-nums'),
                Column::make('status', 'Status')
                    ->sortable('status')
                    ->priority(1)
                    ->card('badge')
                    ->value(fn (Sale $sale): string => $sale->status->label())
                    ->component('ui.sale-status-badge'),
                /**
                 * Satu aksi, bukan dua.
                 *
                 * Detail adalah satu-satunya yang membuka halaman, jadi kolom
                 * ini bukan daftar tombol melainkan penanda bahwa baris bisa
                 * dibuka. Void sengaja tidak ikut di sini: menghapus penjualan
                 * dari tabel riwayat adalah pekerjaan halaman detailnya, tempat
                 * alasannya bisa dibaca dan ditulis.
                 */
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => [
                            [
                                'key' => 'detail',
                                'label' => 'Detail',
                                'route' => 'pos.nota',
                            ],
                        ],
                    ]),
            ])
            /**
             * Baris ikut membuka nota yang sama.
             *
             * Dua jalan ke tempat yang sama, untuk dua kebiasaan yang berbeda:
             * tombol "Detail" untuk orang yang mencarinya, dan baris yang bisa
             * diklik untuk orang yang sudah tahu. Nomor nota sendiri sengaja
             * tidak dibungkus tautan -- kasir perlu menyalinnya, dan tautan di
             * dalam sel akan menyalin alih-alih teksnya.
             */
            ->rowUrl(fn (Sale $sale): string => route('pos.nota', $sale))
            ->filters([
                'method' => ['label' => 'Metode'],
                'status' => ['label' => 'Status'],
                'shift' => ['label' => 'Shift'],
                'pending_sync' => ['label' => 'Belum sinkron', 'flag' => true],
            ]);

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Nota tidak ditemukan' : 'Belum ada transaksi',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Setiap penjualan yang tersimpan akan muncul di sini, termasuk yang masih dikirim dari perangkat offline.',
        );

        return $this->page('pages.pos.riwayat-transaksi', [
            'table' => $table,
            'shiftOptions' => $this->shiftOptions($request),
            'methodOptions' => collect(PaymentMethod::pos())
                ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->label()])
                ->all(),
            'statusOptions' => collect(SaleStatus::cases())
                ->mapWithKeys(fn (SaleStatus $status): array => [$status->value => $status->label()])
                ->all(),
        ], 'Riwayat Transaksi');
    }

    /**
     * Satu nota, dibaca utuh: ringkasan, tiap baris, dan uang yang diterima.
     *
     * Halaman ini hanya membaca. Void, refund, dan cetak struk belum ada di
     * sini -- bukan karena lupa, tapi karena ketiganya menulis, dan menulis
     * nota orang lain adalah keputusan yang layak dibicarakan sendiri. Yang
     * tampil sekarang adalah semua yang dibutuhkan untuk membandingkan layar
     * dengan struk kertas di tangan kasir.
     *
     * @throws NotFoundHttpException
     */
    public function nota(Request $request, Sale $sale)
    {
        // Kepemilikan lewat helper yang sama dengan tabel riwayatnya, supaya
        // kedua halaman tidak bisa perlahan berbeda pendapat: daftar menampilkan
        // nota, tapi halamannya menolak adalah celah yang hanya muncul kalau
        // dua tempat menulis ulang aturan yang sama.
        $this->abortUnlessSees($request, $sale);

        $sale->load([
            'items.lot.product.series',
            'payments',
            'cashier',
            'shift',
        ]);

        return $this->page('pages.pos.nota', [
            'sale' => $sale,
        ], $sale->receipt_no);
    }

    /**
     * Halaman cetak struk POS.
     *
     * Kebalikan dari halaman nota dalam satu hal penting: ini dokumen utuh,
     * jadi `response()->view()`, bukan `$this->page()` -- alasan yang persis
     * sama dengan bukti terima titipan (`inbound.consignment-receipt`).
     *
     * `autoPrint` hanya menyala untuk alur "Bayar" di layar kasir, lewat
     * `print_url` yang dikirim balasan checkout. Cetakan ulang dari riwayat
     * (`pos.nota`) tidak pernah memunculkan dialog print dengan sendirinya.
     *
     * `change` query param membawa kembalian yang hanya diketahui saat checkout
     * selesai; tanpa itu, baris "Kembalian" tidak ditampilkan (cetakan ulang).
     */
    public function struk(Sale $sale, Request $request, PrintSettings $printSettings)
    {
        $this->abortUnlessSees($request, $sale);

        $sale->loadMissing(['items.lot.product.series', 'payments', 'cashier', 'shift']);

        $changeDue = $request->query('change') === null
            ? null
            : max(0, (int) $request->query('change'));

        $sheet = new SaleStrukSheet(
            sale: $sale,
            paper: $printSettings->paper(),
            printedBy: $request->user(),
            changeDue: $changeDue,
        );

        return response()->view('pages.pos.struk-cetak', [
            'sale' => $sale,
            'sheet' => $sheet,
            'previews' => collect(PaperSize::cases())->mapWithKeys(fn (PaperSize $paper) => [
                $paper->value => new SaleStrukSheet(
                    sale: $sale,
                    paper: $paper,
                    printedBy: $request->user(),
                    changeDue: $changeDue,
                ),
            ])->toArray(),
            'autoPrint' => $request->boolean('auto'),
            'method' => $printSettings->method(),
            'thermalError' => session('thermal_print_error'),
        ]);
    }

    /**
     * Susun byte ESC/POS struk POS untuk cetak thermal.
     *
     * Sama seperti bukti terima titipan, endpoint ini tidak mencetak apa pun
     * di server: pengiriman ke printer terjadi dari perangkat yang membuka
     * halaman (Web Bluetooth). Yang dikirim ke sini hanya permintaan byte.
     *
     * A4 tidak bisa dicetak thermal; kalau kertasnya A4, ini membalas 422 dan
     * pemanggil jatuh ke cetak browser.
     *
     * `POST`, bukan `GET`: membangun byte adalah pekerjaan yang tidak mengubah
     * keadaan, jadi GET yang berulang tidak punya alasan untuk masuk cache.
     */
    public function strukThermal(Sale $sale, Request $request, PrintSettings $printSettings): JsonResponse
    {
        $this->abortUnlessSees($request, $sale);

        $sale->loadMissing(['items.lot.product.series', 'payments', 'cashier', 'shift']);

        $paper = $printSettings->paper();
        $paperParam = $request->query('paper');
        if ($paperParam !== null) {
            $candidate = PaperSize::tryFrom((string) $paperParam);
            if ($candidate !== null) {
                $paper = $candidate;
            }
        }
        if (! $paper->isThermal() || $paper->escposColumnWidth() === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Ukuran '.$paper->label().' tidak bisa dicetak thermal. Gunakan tombol cetak browser.',
            ], 422);
        }

        $changeDue = $request->query('change') === null
            ? null
            : max(0, (int) $request->query('change'));

        $sheet = new SaleStrukSheet(
            sale: $sale,
            paper: $paper,
            printedBy: $request->user(),
            changeDue: $changeDue,
        );

        try {
            $bytes = PosStrukRenderer::render($sheet);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'bytesB64' => base64_encode($bytes),
            'paper' => $paper->value,
            'width' => $paper->escposColumnWidth(),
        ]);
    }

    /**
     * 404, bukan 403, saat pengunjung tidak berhak membuka nota/struk ini.
     *
     * Balasan 403 memberi tahu pengunjung bahwa nota ini ada dan dia tidak
     * berhak -- informasi yang tidak perlu diberikan pada kasir yang sedang
     * mengintip nomor nota orang lain. Dipakai halaman nota, halaman struk,
     * dan endpoint thermal: tiga halaman yang menyebutkan dokumen yang sama
     * tidak boleh punya aturan pembuka yang berbeda.
     *
     * @throws NotFoundHttpException
     */
    private function abortUnlessSees(Request $request, Sale $sale): void
    {
        $visible = $this
            ->visibleTo($request, Sale::query())
            ->whereKey($sale->getKey())
            ->exists();

        abort_unless($visible, 404);
    }

    /**
     * Nota milik shift orang sendiri, atau semua shift kalau yang membuka Owner.
     *
     * Batasannya di sini, bukan di view: halaman menampilkan penjualan milik orang
     * lain, dan angka itu bukan milik kasir yang membukanya. Owner melihat semuanya
     * karena mengaudit seluruh penjualan memang pekerjaannya -- dan hanya dia yang
     * berwenang membatalkan nota.
     *
     * Satu tempat untuk dua halaman (riwayat dan nota), supaya kepemilikan tidak
     * pernah berubah di satu tempat tanpa ikut berubah di tempat lain.
     */
    private function visibleTo(Request $request, Builder $query): Builder
    {
        if ($request->user()?->isOwner()) {
            return $query;
        }

        return $query->whereHas(
            'shift',
            fn (Builder $s) => $s->forUser($request->user()?->getKey()),
        );
    }

    private function salesQuery(Request $request): Builder
    {
        $query = Sale::query()
            ->with(['cashier', 'payments'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('method'), fn (Builder $q) => $q->whereHas(
                'payments',
                fn (Builder $p) => $p->where('method', $request->query('method'))
            ))
            ->when($request->filled('shift'), fn (Builder $q) => $q->where('shift_id', $request->query('shift')))
            ->when($request->boolean('pending_sync'), fn (Builder $q) => $q->whereNull('synced_at'));

        $query = $this->visibleTo($request, $query);

        // Urutan bawaan hanya dipasang saat pembaca tidak memilih kolom lain:
        // `DataTable` menempelkan `ORDER BY` miliknya di belakang urutan yang sudah
        // ada, jadi urutan bawaan harus lebih dulu. `id` sebagai pemutus supaya dua
        // nota dengan waktu yang sama tidak bergantian tempat antar halaman.
        $sort = $request->query('sort');

        if (! is_string($sort) || ! in_array($sort, self::SALE_SORTABLE, true)) {
            $query->orderByDesc('sold_at')->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Shift yang boleh dipilih di filter, mengikuti batas yang sama dengan tabelnya.
     *
     * Selector yang menawarkan semua shift ke kasir-adalah bug yang terlihat: filter
     * tersebut selalu mengembalikan kosong, dan yang kosong di sini terbaca sebagai
     * "tidak ada transaksi".
     */
    private function shiftOptions(Request $request)
    {
        $query = Shift::query()->orderByDesc('id');

        if (! $request->user()?->isOwner()) {
            $query->forUser($request->user()?->getKey());
        }

        return $query->limit(50)->get(['id', 'opened_at']);
    }

    /**
     * Halaman Shift Kasir: form buka shift, atau rekap shift yang sedang jalan.
     *
     * Dua bentuk dari satu halaman, bukan dua halaman terpisah, karena yang
     * menentukan bentuknya adalah keadaan shift -- bukan pilihan menu. Kasir yang
     * membuka halaman ini sedang menghitung uang di laci: ia harus melihat angka
     * shiftnya, bukan daftar halaman untuk dipilih.
     */
    public function shiftKasir(ShiftService $shifts)
    {
        $actor = request()->user();

        $shift = $shifts->currentFor($actor);

        return $this->page('pages.pos.shift-kasir', [
            'shift' => $shift,
            'summary' => $shift instanceof Shift ? $shifts->summary($shift) : null,
            'cashThreshold' => $shifts->cashDifferenceThreshold(),
            'recentShifts' => $this->recentShifts($actor),
        ], 'Shift Kasir');
    }

    /**
     * Buka shift baru.
     *
     * Exception dari `ShiftService` dipetakan ke pesan di session, bukan ke 409
     * seperti pada import. Halamannya form biasa yang di-post ulang, jadi kasir
     * harus dikembalikan ke formnya dengan kalimat yang menjelaskan kenapa shiftnya
     * belum bisa dibuka -- JSON tidak akan pernah tampil di layar ini.
     */
    public function openShift(OpenShiftRequest $request, ShiftService $shifts): RedirectResponse
    {
        try {
            $shift = $shifts->open(
                $request->user(),
                $request->openingCash(),
                notes: $request->notes(),
            );
        } catch (ShiftAlreadyOpenException $e) {
            return back()->withInput()->with('toast', [
                'type' => 'warning',
                'message' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('pos.kasir')
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf(
                    'Shift dibuka dengan modal %s. Kasir bisa mulai berjualan.',
                    Format::rupiah($shift->opening_cash),
                ),
            ]);
    }

    /**
     * Tutup shift yang sedang jalan.
     *
     * Route model binding dipakai untuk baris shift-nya, supaya shift yang ditutup
     * selalu ada di URL dan bisa ditautkan kembali. Row-level check "apakah ini
     * memang shift yang sedang dibuka" tetap milik `ShiftService`: controller
     * tidak boleh punya aturan kedua yang bisa berbeda dari yang dipakai service.
     */
    public function closeShift(CloseShiftRequest $request, Shift $shift, ShiftService $shifts): RedirectResponse
    {
        try {
            $closed = $shifts->close(
                $shift,
                $request->closingCash(),
                $request->user(),
                $request->cashDiffApprover(),
                $request->notes(),
            );
        } catch (ShiftAlreadyClosedException) {
            return back()->withInput()->with('toast', [
                'type' => 'warning',
                'message' => 'Shift ini sudah ditutup. Buka shift baru untuk melanjutkan penjualan.',
            ]);
        }

        return redirect()
            ->route('pos.shift-kasir')
            ->with('toast', [
                'type' => $closed->cash_diff === 0 ? 'success' : 'warning',
                'message' => $closed->cash_diff === 0
                    ? 'Shift ditutup. Uang di laci cocok dengan rekap penjualan.'
                    : sprintf(
                        'Shift ditutup dengan selisih %s: uang di laci %s. Selisihnya sudah tercatat atas nama %s.',
                        Format::rupiah(abs($closed->cash_diff)),
                        $closed->cash_diff < 0 ? 'kurang' : 'lebih',
                        $closed->cash_diff_approved_by === null
                            ? 'kasir yang menutup shift'
                            : 'Owner yang mengesahkannya',
                    ),
            ]);
    }

    /**
     * Shift milik sendiri, atau semua shift kalau yang membuka adalah Owner.
     *
     * Pembatasnya di sini, bukan di view: halaman menampilkan angka kas milik
     * orang lain, dan angka itu bukan milik kasir yang membukanya. Owner melihat
     * semuanya karena mengaudit seluruh angka kas memang pekerjaannya.
     */
    private function recentShifts(?User $actor)
    {
        $query = Shift::query()->with(['user', 'closedBy'])->latest('id');

        if (! $actor?->isOwner()) {
            $query->forUser($actor?->getKey());
        }

        return $query->limit(self::RECENT_SHIFT_LIMIT)->get();
    }
}
