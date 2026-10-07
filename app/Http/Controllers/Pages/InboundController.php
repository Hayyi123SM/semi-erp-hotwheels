<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LabelStatus;
use App\Enums\PaperSize;
use App\Enums\ProductStatus;
use App\Enums\SchemeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbound\ConfirmLabelsRequest;
use App\Http\Requests\Inbound\FailLabelsRequest;
use App\Http\Requests\Inbound\MarkLabelsPrintedRequest;
use App\Http\Requests\Inbound\RenderLabelsRequest;
use App\Http\Requests\Inbound\ReprintLabelsRequest;
use App\Http\Requests\Inbound\RetryLabelsRequest;
use App\Http\Requests\Inbound\SaveConsignmentDraftRequest;
use App\Http\Requests\Inbound\StoreConsignmentInRequest;
use App\Http\Requests\Inbound\StoreStockInPribadiRequest;
use App\Models\AuditLog;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Consignor;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\Rack;
use App\Services\AuditLogger;
use App\Services\Consignment\ReceiptPrinterSettings;
use App\Services\Consignment\ReceiptSheet;
use App\Services\Inventory\ConsignmentDraftService;
use App\Services\Inventory\DraftNotEditableException;
use App\Services\Inventory\InboundLine;
use App\Services\Inventory\InboundService;
use App\Services\Inventory\LabelPrintService;
use App\Services\Inventory\ReprintLimitExceeded;
use App\Services\Inventory\StockLotSearch;
use App\Services\Label\LabelContent;
use App\Services\Label\LabelPage;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\TspLabelJobBuilder;
use App\Services\Label\TspLabelSpec;
use App\Services\Notification\ConsignmentReceipt;
use App\Services\Notification\NotificationSender;
use App\Services\Notification\NotificationTemplate;
use App\Services\Notification\Transport\WhatsappTransport;
use App\Services\Print\PrintSettings;
use App\Services\Print\Thermal\ReceiptRenderer;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Enums;
use App\Support\Format;
use App\Support\WhatsappNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InboundController extends Controller
{
    /**
     * Aksi audit yang berarti "kertas bukti terima sudah diserahkan ke penitip".
     *
     * `@var list<string>`
     */
    private const RECEIPT_HANDOVER_ACTIONS = [
        'CONSIGNMENT_RECEIPT_HANDED_OVER',
        'CONSIGNMENT_RECEIPT_REPRINT',
    ];

    public function __construct(
        private readonly InboundService $inbound,
        private readonly ConsignmentDraftService $drafts,
        private readonly LabelPrintService $labels,
        private readonly AuditLogger $audit,
        private readonly NotificationSender $notifications,
    ) {}

    public function stockInPribadi()
    {
        return $this->page('pages.inbound.stock-in-pribadi', $this->pickerData(), 'Stock In Pribadi');
    }

    public function stockInPribadiStore(StoreStockInPribadiRequest $request)
    {
        abort_unless($request->user()->isOwner(), 403, 'Hanya Owner yang dapat commit Stock In Pribadi.');

        $lines = $this->linesFrom($request->validated('items'));

        $lots = $this->inbound->receiveOwnStock(
            lines: $lines,
            source: $request->validated('source'),
            notes: $request->validated('notes'),
            actor: $request->user(),
            deviceId: $this->audit->deviceId(),
        );

        $qty = array_sum(array_map(fn (InboundLine $line) => $line->qty, $lines));

        $this->audit->log('RECEIVE_OWN', 'StockLot', null, [], [
            'qty' => $qty,
            'lots' => count($lots),
        ]);

        return redirect()->route('inbound.stock-in-pribadi')->with('toast', [
            'type' => 'success',
            'message' => "{$qty} unit stok pribadi diterima (".count($lots).' SKU, OW00).',
        ]);
    }

    public function consignmentIn()
    {
        return $this->page('pages.inbound.consignment-in', [
            'consignors' => Consignor::query()
                ->where('status', ConsignorStatus::Active->value)
                ->orderBy('name')
                ->get(),
            'drafts' => $this->drafts->listFor(request()->user()),
            ...$this->pickerData(),
        ], 'Consignment In');
    }

    /**
     * Mulai draft baru.
     *
     * Endpoint ini dipanggil sekali saat form dibuka, bukan tiap auto-save: draft
     * adalah identitas dokumen, dan membuatnya ulang tiap 10 detik akan
     * menghasilkan 200 baris `DRAFT` yang tidak pernah di-commit.
     */
    public function draftStore(SaveConsignmentDraftRequest $request)
    {
        $draft = $this->drafts->start($request->user(), $request->validated());

        return response()->json(['draft' => $this->draftPayload($draft)], 201);
    }

    public function draftShow(Request $request, string $draftId)
    {
        $draft = $this->drafts->findOwnedDraft($draftId, $request->user());

        abort_if($draft === null, 404);

        return response()->json(['draft' => $this->draftPayload($draft)]);
    }

    /**
     * Auto-save. `saved_at` dikembalikan supaya browser bisa menampilkan waktu
     * simpan terakhir tanpa harus menghitungnya sendiri, dan supaya balasan ini
     * bisa dipakai untuk membedakan autosave yang baru saja berhasil dari
     * request lama yang telat sampai.
     */
    public function draftUpdate(SaveConsignmentDraftRequest $request, string $draftId)
    {
        $draft = $this->drafts->findOwnedDocument($draftId, $request->user());

        abort_if($draft === null, 404);

        try {
            $saved = $this->drafts->save($draft, $request->validated());
        } catch (DraftNotEditableException) {
            return response()->json([
                'message' => 'Dokumen ini sudah di-commit dan tidak bisa diubah lagi.',
            ], 409);
        }

        return response()->json(['draft' => $this->draftPayload($saved)]);
    }

    public function draftDestroy(Request $request, string $draftId)
    {
        $draft = $this->drafts->findOwnedDraft($draftId, $request->user());

        abort_if($draft === null, 404);

        $this->drafts->discard($draft);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(Consignment $draft): array
    {
        return [
            'draft_id' => $draft->draft_id,
            'saved_at' => $draft->saved_at?->toIso8601String(),
            'doc_no' => $draft->doc_no,
            'consignor_id' => $draft->consignor_id,
            'consignment_date' => $draft->consignment_date?->toDateString(),
            'source' => $draft->source,
            'notes' => $draft->notes,
            'qty_claimed' => $draft->qty_claimed,
            'variance_note' => $draft->variance_note,
            'items' => $draft->items->map(fn (ConsignmentItem $item) => [
                'line_no' => $item->line_no,
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'rack_id' => $item->rack_id,
                'card_condition' => $item->card_condition,
                'blister_condition' => $item->blister_condition,
                'list_price' => $item->list_price,
                'scheme_type' => $item->scheme_type?->value,
                'scheme_rate' => $item->scheme_rate === null ? null : (float) $item->scheme_rate,
                'scheme_amount' => $item->scheme_amount,
                'discount_policy' => $item->discount_policy,
            ])->all(),
        ];
    }

    public function consignmentStore(StoreConsignmentInRequest $request)
    {
        $consignor = Consignor::findOrFail((int) $request->validated('consignor_id'));

        $draft = $this->resolveDraft($request);
        $idempotencyKey = $this->idempotencyKey($request);

        [$consignment, $lots] = $this->inbound->receiveConsignment(
            consignor: $consignor,
            lines: $this->linesFrom($request->validated('items')),
            consignmentDate: $request->validated('consignment_date'),
            source: $request->validated('source'),
            notes: $request->validated('notes'),
            actor: $request->user(),
            deviceId: $this->audit->deviceId(),
            draft: $draft,
            idempotencyKey: $idempotencyKey,
            qtyClaimed: $request->validated('qty_claimed'),
            varianceNote: $request->validated('variance_note'),
        );

        $this->audit->log('RECEIVE_CONSIGN', 'Consignment', $consignment->getKey(), [], [
            'doc_no' => $consignment->doc_no,
            'qty' => $consignment->qty_received,
            'lots' => count($lots),
        ]);

        /**
         * E-receipt dikirim setelah commit, bukan di dalamnya.
         *
         * Tabel penanganan error inbound menyatakan "WhatsApp gagal terkirim ->
         * dokumen tetap COMPLETED". Kalau ini ikut di dalam transaksi commit,
         * satu nomor yang salah membuat seluruh penerimaan barang ikut gagal dan
         * Staff harus mengulang semuanya -- padahal barangnya sudah masuk.
         * `NotificationSender` juga tidak pernah melempar galat, jadi kegagalan
         * di sini hanya tercatat di daftar notifikasi.
         */
        $notification = $this->notifications->sendReceipt($consignment, $request->user());

        /**
         * Langsung ke bukti terima, bukan ke daftar.
         *
         * Bukti terima adalah bagian dari penerimaan barang, dan orang yang baru
         * selesai menekan tombol commit sedang berdiri di depan penitip yang
         * menunggu kertasnya. Mengarahkan ke daftar berarti struk ada di halaman
         * berikutnya yang harus dicari -- dan karena daftar itu juga tempat orang
         * mengira commit-nya gagal, halaman itu pun sering dibuka ulang.
         *
         * `auto=1` membuat struk langsung keluar. Auto-print dilepas ke sini
         * karena posisinya sudah benar: struk dicetak setelah commit selesai dan
         * e-receipt dikirim, jadi tidak ada halaman lain yang ikut tercetak.
         */
        $postCommitMode = app(ReceiptPrinterSettings::class)->postCommitMode();

        return match ($postCommitMode) {
            'preview' => redirect()->route('inbound.consignment-in.bukti-terima', [
                'consignment' => $consignment,
            ])->with('toast', [
                'type' => $notification->status->isFailed() ? 'warning' : 'success',
                'message' => "{$consignment->doc_no} diterima: {$consignment->qty_received} unit, ".count($lots).' SKU. '
                    .($notification->status->isFailed()
                        ? 'E-receipt WhatsApp gagal: '.$notification->error_message
                        : 'E-receipt WhatsApp siap dikirim.'),
            ]),
            'go_to_labels' => redirect()->route('inbound.cetak-label')->with('toast', [
                'type' => $notification->status->isFailed() ? 'warning' : 'success',
                'message' => "{$consignment->doc_no} diterima: {$consignment->qty_received} unit, ".count($lots).' SKU. '
                    .($notification->status->isFailed()
                        ? 'E-receipt WhatsApp gagal: '.$notification->error_message
                        : 'E-receipt WhatsApp siap dikirim.'),
            ]),
            default => redirect()->route('inbound.consignment-in.bukti-terima', [
                'consignment' => $consignment,
                'auto' => 1,
            ])->with('toast', [
                'type' => $notification->status->isFailed() ? 'warning' : 'success',
                'message' => "{$consignment->doc_no} diterima: {$consignment->qty_received} unit, ".count($lots).' SKU. '
                    .($notification->status->isFailed()
                        ? 'E-receipt WhatsApp gagal: '.$notification->error_message
                        : 'E-receipt WhatsApp siap dikirim.'),
            ]),
        };
    }

    /**
     * Draft yang akan dipakai commit ini, kalau form punya satu.
     *
     * Draft milik Staff lain diperlakukan sebagai tidak ada, bukan sebagai error:
     * `draft_id` ikut terbawa bersama form, dan yang salah terakhir adalah Staff
     * yang mendapat 422 saat commit padahal semua isinya valid. Commit tanpa draft
     * tetap benar -- hanya saja tidak resuming.
     */
    private function resolveDraft(StoreConsignmentInRequest $request): ?Consignment
    {
        $draftId = $request->validated('draft_id');

        if (! is_string($draftId) || $draftId === '') {
            return null;
        }

        return $this->drafts->findOwnedDocument($draftId, $request->user());
    }

    /**
     * Header `Idempotency-Key`, dibatasi panjang dan bentuk.
     *
     * Nilai taken dari header, bukan dari body, supaya retry yang sama persis
     * tidak butuh field tambahan di form. Dibatasi 64 karakter karena kolomnya
     * berukuran tetap dan key yang masuk bisa datang dari kode yang tidak kita kendalikan.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        if ($key === '' || preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key) !== 1) {
            return null;
        }

        return $key;
    }

    public function cetakLabel(Request $request, StockLotSearch $lotSearch)
    {
        $jobs = LabelPrintJob::query()
            ->with(['lot.product.series', 'lot.consignment', 'requester'])
            ->whereIn('status', [
                LabelStatus::Queued->value,
                LabelStatus::Sent->value,
                LabelStatus::Failed->value,
            ])
            // Urutan tindakan, bukan urutan id: yang paling butuh operator
            // sekarang (menunggu konfirmasi) muncul paling atas, dan yang
            // gagal didorong ke bawah tapi tetap terlihat.
            ->orderByRaw("CASE status WHEN 'SENT' THEN 0 WHEN 'FAILED' THEN 1 WHEN 'QUEUED' THEN 2 ELSE 3 END, id DESC")
            ->limit(200)
            ->get();

        $summary = [
            'queued' => $jobs->where('status', LabelStatus::Queued)->count(),
            'sent' => $jobs->where('status', LabelStatus::Sent)->count(),
            'failed' => $jobs->where('status', LabelStatus::Failed)->count(),
        ];

        return $this->page('pages.inbound.cetak-label', [
            'jobs' => $jobs,
            'summary' => $summary,
            'query' => (string) $request->query('q', ''),
            'matches' => $lotSearch->search($request->query('q')),
        ], 'Cetak Label');
    }

    /**
     * Buat job cetak ulang untuk lot yang dipilih (FR-IB-21).
     *
     * Job baru dibuat, bukan job lama yang dihidupkan: job lama yang sudah
     * CONFIRMED adalah bukti label pernah keluar dan angkanya sudah dihitung
     * di `labels_printed`.
     */
    public function reprintLabels(ReprintLabelsRequest $request)
    {
        try {
            $created = $this->labels->reprint(
                lotIds: $request->lotIds(),
                copies: $request->copies(),
                reason: $request->reason(),
                userId: $request->user()?->id,
                approvedBy: $request->reprintApprover(),
            );
        } catch (ReprintLimitExceeded $e) {
            // Batasnya sudah dicek di `ReprintLabelsRequest`, jadi sampai ke
            // sini berarti angkanya berubah setelah form divalidasi -- job lain
            // keluar dari printer sementara operator sedang mengetik PIN.
            // 422, bukan 403: aksi yang sama akan lolos begitu membawa PIN.
            throw ValidationException::withMessages(['copies' => $e->verdict->summary()]);
        }

        if ($created === 0) {
            return Redirect::route('inbound.cetak-label')->with('toast', [
                'type' => 'error',
                'message' => 'Lot yang dipilih sudah tidak ada.',
            ]);
        }

        return Redirect::route('inbound.cetak-label')->with('toast', [
            'type' => 'success',
            'message' => $created === 1
                ? '1 job cetak ulang dibuat di antrean.'
                : "{$created} job cetak ulang dibuat di antrean.",
        ]);
    }

    /**
     * Halaman cetak untuk job yang dipilih.
     *
     * Status job sengaja TIDAK diubah di sini. Halaman ini hanya menampilkan
     * label; operator menandai "sudah dicetak" setelah keluar dari printer.
     * Kalau status ikut berubah di sini, job yang diklik lalu tidak jadi
     * dicetak akan tetap terhitung -- dan `labels_printed` naik tanpa label
     * yang benar-benar keluar.
     *
     * `payload` ditulis saat label pertama kali dirender, lalu dipakai ulang
     * untuk cetakan berikutnya.
     */
    public function renderLabels(RenderLabelsRequest $request, LabelPage $page, LabelPrinterSettings $printer)
    {
        $jobs = LabelPrintJob::query()
            ->with(['lot.product'])
            ->whereKey($request->validated('ids'))
            ->get()
            // Urutan sesuai pilihan operator, bukan urutan id. Kalau job
            // diurutkan ulang diam-diam, label keluar dari printer dalam
            // urutan yang tidak diminta.
            ->sortBy(fn (LabelPrintJob $job) => array_search($job->id, $request->validated('ids'), true))
            ->values();

        if ($jobs->isEmpty()) {
            return Redirect::route('inbound.cetak-label')->with('toast', [
                'type' => 'error',
                'message' => 'Job label yang dipilih sudah tidak ada.',
            ]);
        }

        $this->snapshotPayloads($jobs);

        // Ukuran diambil dari setelan yang sedang berlaku, bukan dari kolom
        // `template` milik tiap job. Satu halaman cetak tidak boleh berisi dua
        // ukuran kertas: printer thermal memakai gauge yang sama untuk seluruh
        // halaman, jadi label 3x2 dan 1,5x1,5 dalam satu sheet keluar dengan
        // jarak yang salah dan tidak bisa diukur. `LabelPage::forJobs()` punya
        // alasannya sendiri.
        $template = $printer->defaultTemplate();

        // Mode gulungan atau stiker ikut device yang sedang berlaku, bukan
        // pilihan di halaman ini: satu device, satu kertas, satu tata letak.
        // `layoutFor()` sudah encapsulate keputusan mode dan `@page`, jadi
        // controller ini tidak perlu tahu kertas yang sedang dimuat.
        $paperLayout = $printer->layoutFor($template);

        return response()->view('pages.inbound.label-print', [
            'title' => 'Cetak Label',
            'labels' => $page->forJobs($jobs, $template, $paperLayout->grid),
            'total' => $jobs->sum('copies'),
            'activeTemplate' => $template,
            'paperLayout' => $paperLayout,
            // Jalur cetak langsung: tombolnya hanya muncul kalau Owner mengaturnya
            // ke `thermal`, dan perintah diambil per id yang sedang di halaman ini.
            'jobIds' => $jobs->pluck('id')->all(),
            'labelPrintMethod' => $printer->printMethod()->value,
            'thermalUrl' => route('inbound.cetak-label.tsp'),
        ]);
    }

    /**
     * Bekukan isi label saat pertama kali dirender.
     *
     * Snapshot ini yang membuat re-print berikutnya bisa menghasilkan label
     * yang sama persis. Kalau kolom `payload` dibiarkan kosong, job lama akan
     * selalu membaca data lot terbaru, dan re-print karena `PRICE_CHANGE`
     * akan menghasilkan label yang isinya sama dengan cetakan pertama.
     */
    private function snapshotPayloads(Collection $jobs): void
    {
        foreach ($jobs as $job) {
            $job->snapshotContent(LabelContent::fromLot($job->lot));
        }
    }

    /**
     * Perintah TSPL (jalur cetak langsung ke printer label) untuk job yang
     * dipilih.
     *
     * Halaman `label-print` (`renderLabels`) mencetak lewat `window.print()`.
     * Jalur ini menghasilkan byte cetak yang sama makna-nya -- job yang sama,
     * urutan yang sama, salinan yang sama, isi yang dibekukan `payload` -- tapi
     * dikirim ke device langsung lewat WebUSB/agent, tanpa dialog cetak
     * browser dan tanpa kertas yang bisa salah dipilih di dialog.
     *
     * Kontraknya sengaja minimal:
     *  - `text` adalah perintah TSPL murni (ASCII), bukan base64 -- TSPL tidak
     *    mengandung byte kontrol seperti ESC/POS, jadi penyandian hanya
     *    menambah kemungkinan salah.
     *  - `sheets`, `total`, `columns`, `rows`, `paper` untuk badge operator,
     *    supaya dia tahu berapa lembar stiker yang akan keluar.
     *
     * Status job TIDAK diubah di sini: angka `labels_printed` baru naik saat
     * operator mengonfirmasi (jalur `confirmLabels`) setelah benar-benar
     * keluar, persis seperti jalur HTML.
     */
    public function renderLabelsTsp(RenderLabelsRequest $request, LabelPrinterSettings $printer, TspLabelJobBuilder $builder)
    {
        $jobs = LabelPrintJob::query()
            ->with(['lot.product'])
            ->whereKey($request->validated('ids'))
            ->get()
            ->sortBy(fn (LabelPrintJob $job) => array_search($job->id, $request->validated('ids'), true))
            ->values();

        if ($jobs->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'Job label yang dipilih sudah tidak ada.',
            ], 404);
        }

        $total = (int) $jobs->sum('copies');

        if ($total > LabelPage::MAX_LABELS_PER_PAGE) {
            return response()->json([
                'ok' => false,
                'message' => sprintf(
                    'Jumlah label (%d) melebihi batas %d per halaman. Cetak per kelompok yang lebih kecil.',
                    $total,
                    LabelPage::MAX_LABELS_PER_PAGE,
                ),
            ], 422);
        }

        $this->snapshotPayloads($jobs);

        $template = $printer->defaultTemplate();
        $paperLayout = $printer->layoutFor($template);

        $specs = [];

        foreach ($jobs as $job) {
            $content = $this->jobContent($job);

            for ($copy = 0; $copy < $job->copies; $copy++) {
                $specs[] = new TspLabelSpec($content, (bool) $job->show_price);
            }
        }

        $job = $builder->build($specs, $template, $printer->qrSideCm(), $paperLayout->grid);

        return response()->json([
            'ok' => true,
            'text' => $job->text,
            'sheets' => $job->sheets,
            'total' => $job->total,
            'paper' => $paperLayout->grid === null ? 'roll' : 'sheet',
            'columns' => $paperLayout->grid?->columns,
            'rows' => $paperLayout->grid?->rows,
        ]);
    }

    /**
     * Job lama memakai snapshot `payload`; job baru membaca lot sekarang.
     * Duplikat yang sengaja dari `LabelPage::contentFor()` karena metode itu
     * privat dan controller TSPL bekerja tanpa `LabelPage`.
     */
    private function jobContent(LabelPrintJob $job): LabelContent
    {
        $payload = $job->payload;

        if (is_array($payload) && $payload !== [] && isset($payload['sku'])) {
            return LabelContent::fromPayload($payload);
        }

        return LabelContent::fromLot($job->lot);
    }

    /**
     * QUEUED -> SENT: job dikirim ke printer.
     *
     * `labels_printed` TIDAK naik di sini. Angka itu baru naik setelah
     * operator mengonfirmasi labelnya keluar (lihat `confirmLabels`).
     */
    /**
     * Uji cetak (FR-IB-25): contoh label untuk mengukur gauge printer, jarak
     * antar label, dan kerapatan cetakan.
     *
     * Sengaja TIDAK membuat `label_print_jobs`, tidak menaikkan `labels_printed`,
     * dan tidak menulis audit. Kalau uji cetak ikut tercatat, setiap penyetelan
     * printer akan menambah cetakan palsu di laporan, dan lot bisa terlihat
     * sudah berlabel padahal tidak ada label yang ditempel.
     *
     * Isinya dari `LabelContent::sample()`, yaitu kasus terburuk yang mungkin
     * muncul di label asli, supaya layout yang meluap ketahuan di sini.
     */
    public function testPrint(Request $request, LabelPage $page, LabelPrinterSettings $printer)
    {
        $validated = $request->validate([
            'template' => ['nullable', Rule::in($this->labelTemplateValues())],
            'copies' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // Tanpa template, uji cetak memakai ukuran yang sedang jadi bawaan,
        // bukan ukuran tetap. Uji cetak justru yang dilakukan Owner tepat
        // setelah mengubah setelan, jadi kalau halaman ini masih memakai
        // angka lamanya, Owner menyetel label yang tidak sedang aktif.
        $template = isset($validated['template'])
            ? LabelTemplate::parse((string) $validated['template'])
            : $printer->defaultTemplate();
        $copies = (int) ($validated['copies'] ?? 1);

        // Uji cetak mengikuti mode kertas yang berlaku, bukan selalu mode
        // gulungan. Uji cetak justru alat untuk mengukur grid stiker, jadi
        // menjalankannya pada kertas yang salah hanya mengukur kertas yang salah.
        $paperLayout = $printer->layoutFor($template);

        return response()->view('pages.inbound.label-print', [
            'title' => 'Uji Cetak Label',
            'labels' => $page->forTestPrint($template, $copies, grid: $paperLayout->grid),
            'total' => $copies,
            'backUrl' => route('inbound.cetak-label'),
            'isTestPrint' => true,
            'activeTemplate' => $template,
            'paperLayout' => $paperLayout,
        ]);
    }

    /**
     * @return list<string>
     */
    private function labelTemplateValues(): array
    {
        return array_map(
            static fn (LabelTemplate $template): string => $template->value,
            LabelTemplate::cases(),
        );
    }

    public function cetakLabelStore(MarkLabelsPrintedRequest $request)
    {
        $marked = $this->labels->markSent(collect($request->jobIds()));

        return $this->labelRedirect(
            $marked,
            $marked === 1 ? '1 label ditandai sudah dikirim ke printer.' : "{$marked} label ditandai sudah dikirim ke printer.",
        );
    }

    /**
     * SENT -> CONFIRMED: label terlihat keluar dan menempel pada unit.
     *
     * Hanya aksi ini yang menambah `labels_printed`.
     */
    public function confirmLabels(ConfirmLabelsRequest $request)
    {
        $confirmed = $this->labels->confirm(collect($request->jobIds()));

        return $this->labelRedirect(
            $confirmed,
            $confirmed === 1
                ? '1 label dikonfirmasi sudah tercetak.'
                : "{$confirmed} label dikonfirmasi sudah tercetak.",
        );
    }

    /**
     * SENT -> FAILED: percetakan gagal, dengan alasan yang wajib diisi.
     */
    public function failLabels(FailLabelsRequest $request)
    {
        $failed = $this->labels->markFailed(
            collect($request->jobIds()),
            $request->failureMessage(),
        );

        return $this->labelRedirect(
            $failed,
            $failed === 1
                ? '1 label ditandai gagal dicetak.'
                : "{$failed} label ditandai gagal dicetak.",
        );
    }

    /**
     * FAILED -> QUEUED: coba ulang percetakan yang gagal.
     */
    public function retryLabels(RetryLabelsRequest $request)
    {
        $retried = $this->labels->retry(collect($request->jobIds()));

        return $this->labelRedirect(
            $retried,
            $retried === 1
                ? '1 label dikembalikan ke antrean cetak.'
                : "{$retried} label dikembalikan ke antrean cetak.",
        );
    }

    /**
     * Balik ke antrean label dengan pesan yang sesuai.
     *
     * Kalau tidak ada job yang berubah, jawabannya "tidak ada yang berubah",
     * bukan sukses. Itu informasi yang benar: job-nya sudah diproses operator
     * lain, jadi operator perlu tahu aksinya tidak mengubah apa pun.
     */
    private function labelRedirect(int $changed, string $successMessage): RedirectResponse
    {
        if ($changed === 0) {
            return Redirect::route('inbound.cetak-label')->with('toast', [
                'type' => 'error',
                'message' => 'Tidak ada job yang berubah. Statusnya mungkin sudah diubah operator lain.',
            ]);
        }

        return Redirect::route('inbound.cetak-label')->with('toast', [
            'type' => 'success',
            'message' => $successMessage,
        ]);
    }

    public function consignmentHistory()
    {
        $table = DataTable::for(request(), Consignment::query()->with(['consignor', 'creator'])->withCount('stockLots'))
            ->searchable(['doc_no', 'consignor.name'])
            ->sortable(['doc_no', 'consignment_date', 'status', 'created_at'])
            ->columns([
                Column::make('doc_no', 'Nomor Dokumen')->mono()->priority(1)->card('title')->render(fn (Consignment $c) => $c->doc_no),
                Column::make('consignor.name', 'Penitip')->priority(1)->card('subtitle'),
                Column::make('consignment_date', 'Tanggal', format: 'date')->priority(2)->card('meta'),
                Column::make('qty_received', 'Unit', align: 'right')->priority(3)->card('meta')->render(fn (Consignment $c) => Format::number($c->qty_received ?? 0)),
                Column::make('stock_lots_count', 'SKU', align: 'right')->priority(3)->card('meta')->render(fn (Consignment $c) => Format::number($c->stock_lots_count)),
                Column::make('status', 'Status', format: 'status')->priority(1)->card('badge'),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => [
                            [
                                'key' => 'detail',
                                'label' => 'Detail',
                                'route' => 'inbound.consignment-in.detail',
                            ],
                            [
                                /**
                                 * Bukti terima hanya muncul untuk dokumen yang
                                 * sudah `COMPLETED`.
                                 *
                                 * Bukan karena route-nya menolak draf, dan bukan
                                 * karena tekannya tidak terjadi -- `when` di sini
                                 * supaya daftar riwayat tidak dipenuhi baris
                                 * dengan aksi yang pasti gagal, dan supaya
                                 * "cetak bukti terima" tidak terlihat tersedia
                                 * untuk dokumen yang tidak bisa dicetak.
                                 */
                                'key' => 'receipt',
                                'label' => 'Bukti Terima',
                                'route' => 'inbound.consignment-in.bukti-terima',
                                'when' => fn (Consignment $c): bool => $c->status === ConsignmentStatus::Completed,
                            ],
                        ],
                    ]),
            ])
            ->emptyState(
                'Belum ada konsinyasi',
                'Commit konsinyasi pertama dari halaman Consignment In untuk melihat riwayatnya di sini.'
            );

        return $this->page('pages.inbound.consignment-riwayat', [
            'table' => $table,
        ], 'Riwayat Konsinyasi');
    }

    public function consignmentDetail(Consignment $consignment)
    {
        $lots = $consignment->stockLots()
            ->with(['product.series', 'rack'])
            ->orderBy('sku')
            ->get();

        /**
         * Jejak penyerahan bukti terima, diambil dengan satu query.
         *
         * Jumlah dan waktu diambil dari hasil yang sama, bukan dari dua query
         * terpisah. Halaman ini dibuka pada saat orang butuh tahu
         * struknya sudah diserahkan atau belum; dua query berarti dua peluang
         * untuk membaca angka yang berbeda, dan gejalannya adalah tampilan
         * bilang "sudah 1x" padahal waktunya masih kosong.
         */
        $handovers = $this->receiptHandoversQuery($consignment)->latest('id')->get();
        $handoverCount = $handovers->count();
        $lastHandoverAt = $handovers->first()?->created_at;

        return $this->page('pages.inbound.consignment-detail', [
            'consignment' => $consignment,
            'lots' => $lots,
            'notification' => $this->notifications->prepare(
                $consignment->load('consignor'),
                NotificationTemplate::ConsignmentReceipt,
            ),
            'handoffLink' => $this->handoffLink($consignment),
            'receiptHandovers' => $handoverCount,
            'lastHandoverAt' => $lastHandoverAt,
        ], 'Consignment '.$consignment->doc_no);
    }

    /**
     * Halaman cetak bukti terima titipan.
     *
     * Hanya dokumen `COMPLETED` yang boleh dicetak. Draf belum berarti barang
     * ada, dan dokumen yang sudah dibatalkan tidak boleh keluar sebagai bukti
     * penerimaan: struk seperti itu membuat orang memegang kertas yang
     * menyatakan barang sudah masuk, padahal barangnya belum pernah masuk.
     *
     * `autoPrint` sengaja hanya menyala untuk alur commit. Cetakan ulang dari
     * daftar riwayat tidak pernah memunculkan dialog print dengan sendirinya:
     * yang sedang dibaca waktu itu daftar, bukan struk.
     */
    public function consignmentReceiptPrint(
        Consignment $consignment,
        Request $request,
        ReceiptPrinterSettings $printer,
        PrintSettings $printSettings,
    ) {
        abort_unless(in_array($consignment->status, [ConsignmentStatus::Completed, ConsignmentStatus::Committed], true), 404);

        $consignment->loadMissing(['consignor', 'stockLots']);

        /**
         * `response()->view()`, bukan `$this->page()`.
         *
         * View ini adalah dokumen utuh, bukan potongan halaman: ia sudah membawa `<html>`,
         * `<head>`, dan `@page` miliknya sendiri. `$this->page()` membungkusnya
         * di `layouts.app`, dan hasilnya dokumen HTML yang di dalam dokumen HTML
         * -- browser repairingnya sendiri, dan yang tidak repairingnya akan
         * mencetak struk bersema sidebar, topbar, dan setiap tombol navigasi.
         *
         * Halaman cetak label sudah memakai cara yang sama karena alasan yang
         * persis sama.
         */
        return response()->view('pages.inbound.consignment-receipt', [
            'consignment' => $consignment,
            'sheet' => new ReceiptSheet(
                $consignment,
                $printer->paper(),
                $request->user(),
                autoPrint: $request->boolean('auto'),
            ),
            'previews' => collect(PaperSize::cases())->mapWithKeys(fn (PaperSize $p) => [
                $p->value => new ReceiptSheet(
                    $consignment,
                    $p,
                    $request->user(),
                    autoPrint: false,
                ),
            ])->toArray(),
            'autoPrint' => $request->boolean('auto'),
            'method' => $printSettings->method(),
            'thermalError' => session('thermal_print_error'),
        ]);
    }

    /**
     * Susun byte ESC/POS bukti terima untuk cetak thermal.
     *
     * Endpoint ini tidak mencetak apa pun di server: pengiriman ke printer
     * terjadi dari perangkat yang membuka halaman (Web Bluetooth). Yang dikirim
     * ke sini hanyalah permintaan byte, supaya layout struk tetap dihitung oleh
     * satu renderer yang sama dengan yang diuji -- bukan disalin ke JavaScript.
     *
     * Hanya dokumen yang sudah `COMPLETED`/`COMMITTED` yang boleh dicetak,
     * sama seperti halaman bukti terima biasa: struk ditujukan barang yang
     * benar-benar sudah diterima.
     *
     * A4 tidak bisa dicetak thermal: ESC/POS adalah dunia kertas gulung,
     * bukan dokumen. Kalau ukuran kertasnya A4, ini membalas 422 dan halaman
     * jatuh ke cetak browser.
     *
     * `POST`, bukan `GET`, karena membangun byte bisa dipicu berulang lewat
     * browser -- prerender, tombol back, Ctrl+R -- dan GET yang membangun byte
     * tidak mengubah apa-apa pun, jadi tidak punya alasan untuk masuk cache.
     */
    public function consignmentReceiptPrintThermal(
        Consignment $consignment,
        Request $request,
        PrintSettings $printSettings,
    ) {
        abort_unless(in_array($consignment->status, [ConsignmentStatus::Completed, ConsignmentStatus::Committed], true), 404);

        $consignment->loadMissing(['consignor', 'stockLots']);

        $paper = $printSettings->paper();

        if (! $paper->isThermal() || $paper->escposColumnWidth() === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Ukuran '.$paper->label().' tidak bisa dicetak thermal. Gunakan tombol cetak browser.',
            ], 422);
        }

        $sheet = new ReceiptSheet(
            $consignment,
            $paper,
            $request->user(),
            autoPrint: false,
        );

        try {
            $bytes = ReceiptRenderer::render($sheet);
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
     * Catat bahwa bukti terima sudah diserahkan ke penitip.
     *
     * Audit ditulis di sini, bukan saat halaman cetak dibuka. Halaman `GET`
     * bisa dipanggil berulang -- tombol di browser, tombol "back", prerender,
     * atau orang yang menekan Ctrl+R karena struknya belum keluar -- dan kalau
     * pencetakan dihitung dari sana, jumlah cetakan tidak lagi berarti
     * apa-apa: satu struk bisa tercatat sepuluh kali.
     *
     * Yang dihitung di sini adalah jumlah\emph{penyerahan}, yang memang punya
     * satu titik penanda: saat penyalin menekan tombol. Karena itu aksi ini
     * memakai `POST` dan tidak punya jalur lain.
     *
     * Tidak ada batas atas jumlah cetakan. Batas harian pada label ada karena
     * label hilang berarti barang tidak bisa dilacak. Bukti terima berbeda:
     * barang sudah tercatat masuk sejak commit, dan struk yang hilang bisa
     * dibuktikan ulang dari catatan serah terima. Membatasi cetakan hanya
     * mendorong orang memanipulasi catatan supaya boleh mencetak.
     */
    public function consignmentReceiptHandedOver(Consignment $consignment, Request $request)
    {
        abort_unless(in_array($consignment->status, [ConsignmentStatus::Completed, ConsignmentStatus::Committed], true), 404);

        $isReprint = $this->receiptHandoutCount($consignment) > 0;

        $this->audit->log(
            $isReprint ? 'CONSIGNMENT_RECEIPT_REPRINT' : 'CONSIGNMENT_RECEIPT_HANDED_OVER',
            'Consignment',
            $consignment->getKey(),
            after: [
                'doc_no' => $consignment->doc_no,
                'count' => $this->receiptHandoutCount($consignment) + 1,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $isReprint
                ? 'Cetakan ulang bukti terima dicatat untuk '.$consignment->doc_no.'.'
                : 'Bukti terima '.$consignment->doc_no.' dicatat sudah diserahkan.',
        ]);
    }

    /**
     * Jejak penyerahan bukti terima untuk satu dokumen.
     *
     * Dipisah dari pemakaiannya supaya definisi "sudah diserahkan" hanya ada di
     * satu tempat. Kalau daftar aksi ini ditulis ulang di dua tempat, yang satu
     * bisa berhenti menghitung sementara yang lain masih menghitung -- dan tampilannya diam-diam
     * menunjukkan jumlah yang lebih kecil, yang arah kesalahannya justru ke
     * underestimate.
     */
    private function receiptHandoversQuery(Consignment $consignment): Builder
    {
        return AuditLog::query()
            ->where('entity', 'Consignment')
            ->where('entity_id', $consignment->getKey())
            ->whereIn('action', self::RECEIPT_HANDOVER_ACTIONS);
    }

    /**
     * Berapa kali bukti terima dokumen ini sudah dicatat diserahkan.
     *
     * Dihitung dari audit, bukan dari kolom di dokumen. Dokumennya tidak berubah
     * status saat struk diserahkan -- barang sudah tetap diterima pada saat
     * commit -- dan menambah kolom counter untuk sesuatu yang cuma perlu dibaca
     * berarti satu kolom lagi yang harus dijaga konsisten di setiap tempat
     * dokumen diperbarui.
     */
    private function receiptHandoutCount(Consignment $consignment): int
    {
        return $this->receiptHandoversQuery($consignment)->count();
    }

    /**
     * Kirim ulang e-receipt dari halaman dokumen.
     *
     * Notifikasi yang sudah `SENT` ditolak, bukan dikirim ulang diam-diam.
     * Status itu berarti pesan sudah diserahkan; mengirimnya lagi tanpa
     * permintaan berarti penitip menerima dua nota untuk satu barang, dan
     *penjelasannya sekarang lebih sulit daripada penolakannya sekarang.
     */
    public function consignmentReceiptSend(Consignment $consignment, Request $request)
    {
        $notification = $this->notifications->prepare(
            $consignment->load('consignor'),
            NotificationTemplate::ConsignmentReceipt,
        );

        $notification = $this->notifications->send($notification, $request->user());

        if ($notification->status->isFailed()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'E-receipt tidak terkirim: '.$notification->error_message,
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $notification->status->isSettled()
                ? 'E-receipt sudah pernah diserahkan ke Staff, tidak dikirim ulang.'
                : 'E-receipt siap dikirim. Buka tautan WhatsApp untuk mengirimnya.',
        ]);
    }

    /**
     * Tautan `wa.me` untuk pesan yang sudah disiapkan, atau `null` kalau belum.
     */
    private function handoffLink(Consignment $consignment): ?string
    {
        $consignor = $consignment->consignor;

        if ($consignor?->wa_opt_in_at === null || ! WhatsappNumber::isValid($consignor->wa_number)) {
            return null;
        }

        return app(WhatsappTransport::class)->handoffLink(
            (string) $consignor->wa_number,
            (new ConsignmentReceipt($consignment))->body(),
        );
    }

    /** @return array{products: Collection, racks: Collection, card_conditions: array<string,string>, blister_conditions: array<string,string>} */
    private function pickerData(): array
    {
        return [
            'products' => Product::query()
                ->with('series')
                ->where('status', ProductStatus::Active->value)
                ->orderBy('name')
                ->get(),
            'racks' => Rack::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'card_conditions' => Enums::options(CardCondition::class),
            'blister_conditions' => Enums::options(BlisterCondition::class),
        ];
    }

    /** @param  list<array<string, mixed>>  $items
     * @return list<InboundLine>
     */
    /**
     * @param  array<string, mixed>  $item
     */
    private function schemeTypeFrom(array $item): ?SchemeType
    {
        return isset($item['scheme_type']) ? SchemeType::tryFrom((string) $item['scheme_type']) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function discountPolicyFrom(array $item): ?DiscountPolicy
    {
        return isset($item['discount_policy']) ? DiscountPolicy::tryFrom((string) $item['discount_policy']) : null;
    }

    private function linesFrom(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $rackIds = array_values(array_unique(array_filter(array_column($items, 'rack_id'))));
        $racks = $rackIds === []
            ? collect()
            : Rack::query()->whereIn('id', $rackIds)->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $lines[] = new InboundLine(
                product: $products[$item['product_id']],
                qty: (int) $item['qty'],
                rack: ! empty($item['rack_id']) ? ($racks[$item['rack_id']] ?? null) : null,
                cardCondition: ! empty($item['card_condition']) ? CardCondition::tryFrom($item['card_condition']) : null,
                blisterCondition: ! empty($item['blister_condition']) ? BlisterCondition::tryFrom($item['blister_condition']) : null,
                costPrice: isset($item['cost_price']) ? (int) $item['cost_price'] : null,
                listPrice: isset($item['list_price']) ? (int) $item['list_price'] : null,
                schemeType: $this->schemeTypeFrom($item),
                schemeRate: isset($item['scheme_rate']) ? (float) $item['scheme_rate'] : null,
                schemeAmount: isset($item['scheme_amount']) ? (int) $item['scheme_amount'] : null,
                discountPolicy: $this->discountPolicyFrom($item),
            );
        }

        return $lines;
    }
}
