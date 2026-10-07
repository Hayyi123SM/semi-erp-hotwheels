<?php

namespace App\Http\Controllers\Master;

use App\Enums\ConsignorStatus;
use App\Enums\LedgerType;
use App\Enums\SchemeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsignorRequest\StoreConsignorRequest;
use App\Http\Requests\ConsignorRequest\UpdateConsignorRequest;
use App\Models\Consignor;
use App\Models\ConsignorLedger;
use App\Services\AuditLogger;
use App\Services\Master\ConsignorCodeService;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\Redirect;

class ConsignorController extends Controller
{
    private const SENSITIVE_FIELDS = [
        'bank_name',
        'bank_account',
        'bank_holder',
        'scheme_type',
        'scheme_rate',
        'scheme_amount',
    ];

    public function __construct(
        private readonly ConsignorCodeService $codeService,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $isOwner = auth()->user()->isOwner();

        $base = Consignor::query();
        $consignors = (clone $base)->when(
            request()->query('status'),
            fn ($query, $status) => $query->where('status', $status)
        );

        $balances = $this->balances();
        $due = fn (?Consignor $consignor) => $consignor === null
            ? 0
            : (($balances[$consignor->id]['accrual'] ?? 0) - ($balances[$consignor->id]['paid'] ?? 0));

        $stripDecimals = fn ($value) => $value === null ? null : rtrim(rtrim((string) $value, '0'), '.');

        $table = DataTable::for(request(), $consignors)
            ->searchable(['name', 'consignor_code', 'wa_number', 'bank_holder'])
            // Nomor tersimpan polos (`6281234567890`) sementara orang mengetik
            // `08123`, jadi `LIKE` biasa tidak akan menemukannya. Pencocokan
            // dilakukan pada bagian nomor tanpa awalan.
            ->orSearchUsing(function ($query, string $search): void {
                $fragment = WhatsappNumber::searchFragment($search);

                if ($fragment === null) {
                    return;
                }

                $query->orWhere('wa_number', 'like', '%'.$fragment.'%');
            })
            ->sortable(['consignor_code', 'name', 'scheme_rate', 'status', 'created_at'])
            ->columns([
                Column::make('consignor_code', 'Kode', sort: 'consignor_code')->mono()->priority(1)->card('subtitle'),
                Column::make('name', 'Nama')->priority(1)->card('title')->render(function (Consignor $consignor) {
                    $joined = $consignor->agreement_date ?? $consignor->created_at;

                    return '<div class="flex flex-col gap-0.5">'
                        .'<span class="font-medium text-text-strong">'.e($consignor->name).'</span>'
                        .'<span class="text-label-sm text-text-subtle">'
                        .($consignor->agreement_date ? 'Perjanjian ' : 'Bergabung ').e($joined->format('d M Y'))
                        .'</span></div>';
                }),
                Column::make('wa_number', 'Kontak WhatsApp')->mono()->priority(2)->card('meta'),
                Column::make('scheme_type', 'Skema', align: 'center')
                    ->visible(fn () => $isOwner)
                    ->priority(3)
                    ->card('meta')
                    ->render(function (Consignor $consignor) use ($stripDecimals) {
                        $label = match (true) {
                            $consignor->scheme_type?->value === SchemeType::Percentage->value => ($stripDecimals($consignor->scheme_rate) ?? '0').'% dari harga jual',
                            (bool) $consignor->scheme_amount => ($consignor->scheme_type?->value === SchemeType::Nett->value ? 'Nett' : 'Flat')
                                .' Rp'.number_format($consignor->scheme_amount, 0, ',', '.').'/unit',
                            default => Format::EMPTY,
                        };

                        return '<span class="inline-flex items-center gap-1.5 rounded-full border border-info-border bg-info-bg px-2 py-0.5 text-label-md text-info-text">'
                            .e($label).'</span>';
                    }),
                Column::make('due', 'Saldo Jatuh Tempo', align: 'right', format: 'rupiah')
                    ->visible(fn () => $isOwner)
                    ->priority(2)
                    ->card('price')
                    ->value(fn (Consignor $consignor) => $due($consignor)),
                Column::make('status', 'Status', format: 'status')->priority(1)->card('badge'),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => array_values(array_filter([
                            [
                                'key' => 'edit',
                                'label' => 'Edit',
                                'route' => 'master.penitip.edit',
                                'when' => $isOwner,
                            ],
                            [
                                'key' => 'restore',
                                'label' => 'Aktifkan',
                                'route' => 'master.penitip.restore',
                                'method' => 'PATCH',
                                'when' => fn (Consignor $consignor) => $isOwner && $consignor->status === ConsignorStatus::Archived,
                            ],
                            [
                                'key' => 'archive',
                                'label' => 'Arsip',
                                'route' => 'master.penitip.archive',
                                'method' => 'PATCH',
                                'variant' => 'danger',
                                'confirm' => [
                                    'title' => 'Arsipkan penitip?',
                                    'description' => 'Penitip disembunyikan dari daftar aktif, tetapi seluruh riwayat titipan tetap tersimpan.',
                                    'confirm_text' => 'Arsipkan',
                                ],
                                'when' => fn (Consignor $consignor) => $isOwner && $consignor->status !== ConsignorStatus::Archived,
                            ],
                        ])),
                    ]),
            ])
            ->filters([
                'status' => ['label' => 'Status', 'format' => fn (string $value) => Format::statusLabel($value)],
            ])
            ->create(route('master.penitip.create'), 'Tambah Penitip');

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Penitip tidak ditemukan' : 'Belum ada penitip',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Tambahkan penitip pertama, atau gunakan Impor Excel untuk memuat banyak data sekaligus.'
        );

        return $this->page('pages.master.penitip', [
            'table' => $table,
            'totalConsignors' => $base->toBase()->count(),
            'activeCount' => $base->toBase()->where('status', ConsignorStatus::Active->value)->count(),
            'dueTotal' => (int) collect($balances)->sum(fn ($row) => $row['accrual'] - $row['paid']),
            'canManage' => $isOwner,
        ], 'Data Penitip');
    }

    public function create()
    {
        return $this->page('pages.master.penitip-form', [
            'consignor' => new Consignor,
            'canManage' => auth()->user()->isOwner(),
        ], 'Tambah Penitip');
    }

    public function store(StoreConsignorRequest $request)
    {
        $data = $this->scopeForRole($request->validated());
        $data['consignor_code'] = $this->codeService->next();

        $consignor = Consignor::create($data);
        $this->audit->created($consignor);

        return Redirect::route('master.penitip')->with('toast', [
            'type' => 'success',
            'message' => 'Penitip '.$consignor->name.' tersimpan ('.$consignor->consignor_code.').',
        ]);
    }

    public function edit(Consignor $consignor)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return $this->page('pages.master.penitip-form', [
            'consignor' => $consignor,
            'canManage' => auth()->user()->isOwner(),
        ], 'Edit Penitip');
    }

    public function update(UpdateConsignorRequest $request, Consignor $consignor)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $before = $consignor->getAttributes();
        $consignor->fill($this->scopeForRole($request->validated()))->save();
        $this->audit->updated($consignor, $before);

        return Redirect::route('master.penitip')->with('toast', [
            'type' => 'success',
            'message' => 'Data '.$consignor->name.' diperbarui.',
        ]);
    }

    public function archive(Consignor $consignor)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $consignor->update(['status' => ConsignorStatus::Archived]);
        $this->audit->archived($consignor);

        return back()->with('toast', [
            'type' => 'info',
            'message' => 'Penitip '.$consignor->name.' diarsipkan.',
        ]);
    }

    public function restore(Consignor $consignor)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $consignor->update(['status' => ConsignorStatus::Active]);
        $this->audit->log('ACTIVE', class_basename($consignor), $consignor->getKey(), [], ['status' => ConsignorStatus::Active->value]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Penitip '.$consignor->name.' diaktifkan kembali.',
        ]);
    }

    private function scopeForRole(array $data): array
    {
        return auth()->user()->isOwner()
            ? $data
            : array_diff_key($data, array_flip(self::SENSITIVE_FIELDS));
    }

    private function balances(): array
    {
        return ConsignorLedger::query()
            ->selectRaw(
                'consignor_id,
                 COALESCE(SUM(CASE WHEN type <> ? THEN amount END), 0) as accrual,
                 COALESCE(SUM(CASE WHEN type = ? THEN amount END), 0) as paid',
                [LedgerType::SettlementPayment->value, LedgerType::SettlementPayment->value],
            )
            ->groupBy('consignor_id')
            ->get()
            ->keyBy('consignor_id')
            ->toArray();
    }
}
