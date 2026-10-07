<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\DiscountPolicy;
use App\Enums\LedgerType;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Enums\SaleStatus;
use App\Enums\SchemeType;
use App\Models\ConsignorLedger;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\Inventory\TermsCalculator;
use App\Support\Format;
use App\Support\Sql\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan satu penjualan kasir: potong stok, rekam bagi hasil, terima uang.
 *
 * Satu transaksi database, tanpa pengecualian. Tiga hal yang dijanjikan
 * layar kasir -- barang keluar, uang tercatat, hak penitip terakru -- hanya
 * benar kalau ketiganya terjadi bersamaan; menyimpan nota tanpa memotong stok,
 * atau memotong stok tanpa mencatat uangnya, menghasilkan angka yang tidak bisa
 * dipercaya siapa pun dan tidak bisa diperbaiki dengan melihat layar lagi.
 *
 * Urutannya penting dan tidak boleh ditukar:
 *
 *  1. **Dedup** `client_sale_id` sebelum apa pun. Percobaan ulang setelah
 *     timeout harus mengembalikan nota yang sama, bukan membuat yang kedua.
 *  2. **Validasi** seluruh keranjang sebelum satu baris pun ditulis, sehingga
 *     penolakan tidak meninggalkan setengah penjualan.
 *  3. **Nomor struk** dialokasikan paling akhir. Alokasi yang gagal berarti
 *     transaksi dibatalkan seluruhnya, dan hari tanpa nomor terpakai tidak
 *     meninggalkan lubang di urutan.
 *
 * Angka yang dipakai di sini semuanya dibaca dari `stock_lots` pada langkah 2.
 * Layar kasir tidak pernah mengirim harga, dan kalau pun ia mengirim, angka itu
 * tidak akan dipakai: nota harus mencerminkan barang yang benar-benar keluar,
 * bukan apa yang pernah tertulis di layar beberapa detik yang lalu.
 */
final class CheckoutService
{
    public function __construct(
        private readonly ReceiptSequencer $receipts,
        private readonly TermsCalculator $terms,
        private readonly ShiftService $shifts,
    ) {}

    /**
     * Simpan penjualan, atau kembalikan nota yang sudah pernah dibuat untuk
     * `client_sale_id` yang sama.
     *
     * @throws ValidationException bila keranjang tidak bisa dijual apa adanya;
     *                             seluruh penjualan dibatalkan, tidak ada stok
     *                             yang terpotong
     * @throws QueryException bila nomor struk tidak dapat dialokasikan
     */
    public function checkout(Checkout $checkout): Sale
    {
        if ($existing = $this->existing($checkout->clientSaleId)) {
            return $existing;
        }

        $shift = $this->shifts->currentFor($checkout->cashier, $checkout->deviceId);

        if ($shift === null) {
            throw ValidationException::withMessages([
                'checkout' => 'Belum ada shift yang terbuka. Buka shift dulu sebelum menerima pembayaran.',
            ]);
        }

        try {
            return DB::transaction(function () use ($checkout, $shift): Sale {
                $lines = $this->price($checkout);
                $total = $this->totalOf($lines);

                $this->guardPayments($checkout, $total);

                return $this->persist($checkout, $shift->id, $lines, $total);
            });
        } catch (QueryException $e) {
            // Dua percobaan bersamaan untuk `client_sale_id` yang sama: yang
            // satu menang, yang kalah tidak boleh menjadi error -- ia hanya
            // perlu menerima nota yang barusan dibuat pemenangnya.
            if (! UniqueViolation::is($e)) {
                throw $e;
            }

            return $this->existing($checkout->clientSaleId) ?? throw $e;
        }
    }

    /**
     * Nota yang pernah dibuat untuk kunci ini, atau `null`.
     */
    private function existing(string $clientSaleId): ?Sale
    {
        return Sale::query()->where('client_sale_id', $clientSaleId)->first();
    }

    /**
     * Baca ulang seluruh keranjang dari database dan hitung ulang harganya.
     *
     * `lockForUpdate()` di sini, bukan nanti: baris yang dibaca saat validasi
     * dan baris yang ditulis harus baris yang sama, dan tanpa kunci keduanya
     * bisa berbeda ketika kasir lain menjual unit terakhir pada saat yang sama.
     *
     * @return list<PricedLine>
     *
     * @throws ValidationException
     */
    private function price(Checkout $checkout): array
    {
        $lots = StockLot::query()
            ->whereKey($checkout->lotIds())
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (StockLot $lot): int => (int) $lot->getKey());

        // Stok yang tersisa selama keranjang dibaca baris demi baris. Satu lot
        // yang muncul dua kali akan dipotong dua kali dari penampung ini, jadi
        // baris kedua melihat sisa yang benar, bukan stok awal yang kedua kali.
        $available = [];

        foreach ($lots as $lot) {
            $available[(int) $lot->getKey()] = $lot->qty_on_hand;
        }

        $lines = [];

        foreach ($checkout->lines as $line) {
            $lot = $lots->get($line->lotId);

            if ($lot === null) {
                throw ValidationException::withMessages([
                    'items' => 'Sebagian barang di keranjang sudah tidak ada di sistem. Muat ulang halaman kasir.',
                ]);
            }

            $key = (int) $lot->getKey();

            $this->guardSellable($lot);
            $this->guardStock($lot, $line->qty, $available[$key]);

            $available[$key] -= $line->qty;
            $lines[] = $this->priceLine($lot, $line);
        }

        return $lines;
    }

    /**
     * Status lot mengizinkan penjualan -- bukan karantina, bukan barang yang
     * sudah dikembalikan atau ditulis off.
     *
     * @throws ValidationException
     */
    private function guardSellable(StockLot $lot): void
    {
        if ($lot->status === LotStatus::Available) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => sprintf(
                '%s tidak bisa dijual: statusnya %s.',
                $lot->sku,
                strtolower(Format::statusLabel($lot->status)),
            ),
        ]);
    }

    /**
     * @param  int  $requested  unit yang diminta baris ini
     * @param  int  $remaining  yang masih tersisa setelah baris sebelumnya pada
     *                          lot yang sama terbaca
     *
     * @throws ValidationException
     */
    private function guardStock(StockLot $lot, int $requested, int $remaining): void
    {
        if ($requested <= $remaining) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => sprintf(
                'Stok %s tinggal %d unit. Kurangi jumlahnya atau muat ulang halaman.',
                $lot->sku,
                $remaining,
            ),
        ]);
    }

    /**
     * Harga satu baris, dihitung ulang dari lot yang baru dikunci.
     *
     * @throws ValidationException
     */
    private function priceLine(StockLot $lot, CheckoutLine $line): PricedLine
    {
        // Stok milik toko sendiri tidak dibagi dengan siapa pun: tidak ada
        // skema, tidak ada hak penitip, dan seluruh harga jual adalah milik
        // toko. TermsCalculator sengaja tidak dipanggil di sini karena ia akan
        // menolak skema yang memang tidak boleh ada pada lot ini.
        if ($lot->owner_type === OwnerType::Own) {
            $lineTotal = $lot->list_price * $line->qty;

            return new PricedLine(
                lot: $lot,
                qty: $line->qty,
                inputMethod: $line->inputMethod,
                listPrice: $lot->list_price,
                sellPrice: $lot->list_price,
                lineTotal: $lineTotal,
                schemeType: null,
                schemeRate: null,
                schemeAmount: null,
                termsVersion: $lot->terms_version,
                costPrice: $lot->cost_price,
                feeToko: $lineTotal,
                hakPenitip: null,
            );
        }

        // `scheme_rate` di-cast `decimal:2`, sehingga nilainya string. Lulus
        // begitu saja ke parameter `?float` bersama `declare(strict_types=1)`
        // adalah TypeError -- bukan penolakan yang bisa dibaca kasir, tapi
        // layar 500 di tengah transaksi yang sedang berjalan.
        $rate = $lot->scheme_rate === null ? null : (float) $lot->scheme_rate;

        try {
            $breakdown = $this->terms->breakdown(
                listPrice: $lot->list_price,
                scheme: $this->guardScheme($lot, $rate, $lot->scheme_amount),
                rate: $rate,
                amount: $lot->scheme_amount,
                policy: $lot->discount_policy ?? DiscountPolicy::StoreBears,
                discount: 0,
            );
        } catch (ValidationException $e) {
            // Pesan dari TermsCalculator menyebut field, bukan barang. Satu
            // keranjang bisa berisi banyak SKU, dan kasir perlu tahu yang mana
            // yang harus dibetulkan Owner.
            throw ValidationException::withMessages([
                'items' => $lot->sku.': '.implode(' ', Arr::flatten($e->errors())),
            ]);
        }

        return new PricedLine(
            lot: $lot,
            qty: $line->qty,
            inputMethod: $line->inputMethod,
            listPrice: $lot->list_price,
            sellPrice: $breakdown->sellPrice,
            lineTotal: $breakdown->sellPrice * $line->qty,
            schemeType: $lot->scheme_type,
            schemeRate: $rate,
            schemeAmount: $lot->scheme_amount,
            termsVersion: $lot->terms_version,
            costPrice: $lot->cost_price,
            feeToko: $breakdown->storeFeeFor($line->qty),
            hakPenitip: $breakdown->consignorRightFor($line->qty),
        );
    }

    /**
     * Skema titipan yang lengkap parameter, atau penolakan yang menyebut SKU.
     *
     * Barang titipan tanpa skema tidak bisa dibagi hasilnya, dan menyimpannya
     * sebagai fee nol akan menghasilkan saldo penitip yang salah diam-diam --
     * lebih buruk daripada penjualan yang ditolak di depan pelanggan.
     *
     * @throws ValidationException
     */
    private function guardScheme(StockLot $lot, ?float $rate, ?int $amount): SchemeType
    {
        $scheme = $lot->scheme_type;

        if ($scheme === null) {
            throw ValidationException::withMessages([
                'items' => sprintf(
                    '%s belum punya skema komisi. Minta Owner mengisi skemanya di Pengaturan · Penitip sebelum barang ini dijual.',
                    $lot->sku,
                ),
            ]);
        }

        $missing = match ($scheme) {
            SchemeType::Percentage => $rate === null,
            SchemeType::Nett, SchemeType::Flat => $amount === null,
        };

        if ($missing) {
            throw ValidationException::withMessages([
                'items' => sprintf('%s tidak punya parameter skema %s.', $lot->sku, $scheme->value),
            ]);
        }

        return $scheme;
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    private function totalOf(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += $line->lineTotal;
        }

        return $total;
    }

    /**
     * Pembayaran menutup total belanja, dan uang tunai yang dipegang mencukupi.
     *
     * Keduanya dicek setelah harga dihitung ulang, bukan sebelum: total yang
     * dikirim layar tidak dipakai, jadi angka yang harus dibandingkan dengan
     * pembayaran hanyalah angka yang baru dihitung server.
     *
     * @throws ValidationException
     */
    private function guardPayments(Checkout $checkout, int $total): void
    {
        $paid = 0;

        foreach ($checkout->payments as $payment) {
            if ($payment->amount < 1) {
                throw ValidationException::withMessages([
                    'payments' => 'Setiap pembayaran harus lebih besar dari 0.',
                ]);
            }

            $paid += $payment->amount;
        }

        if ($paid !== $total) {
            throw ValidationException::withMessages([
                'payments' => sprintf(
                    'Pembayaran %s tidak sama dengan total belanja %s. Periksa kembali keranjangnya.',
                    Format::rupiah($paid),
                    Format::rupiah($total),
                ),
            ]);
        }

        // Uang yang dipegang kasir bukan uang yang diterima toko, jadi ia tidak
        // ikut dijumlahkan di atas. Yang diminta darinya hanya satu: mencukupi
        // total, supaya kembalian tidak pernah negatif.
        if ($checkout->paysWithCash() && ($checkout->tender ?? 0) < $total) {
            throw ValidationException::withMessages([
                'tender' => sprintf(
                    'Uang diterima %s kurang dari total %s.',
                    Format::rupiah($checkout->tender ?? 0),
                    Format::rupiah($total),
                ),
            ]);
        }
    }

    /**
     * Tulis nota beserta seluruh akibatnya, di dalam transaksi yang sudah
     * terbuka oleh pemanggil.
     *
     * @param  list<PricedLine>  $lines
     */
    private function persist(Checkout $checkout, int $shiftId, array $lines, int $total): Sale
    {
        $sale = Sale::create([
            'client_sale_id' => $checkout->clientSaleId,
            'receipt_no' => $this->receipts->next(),
            'shift_id' => $shiftId,
            'device_id' => $checkout->deviceId,
            'user_id' => $checkout->cashier->id,
            'sold_at' => now(),
            'subtotal' => $total,
            'discount_total' => 0,
            'total' => $total,
            'status' => SaleStatus::Paid,
            'flags' => [],
            // Diisi: penjualan ini dibuat oleh server yang sedang online, jadi
            // tidak pernah menunggu sinkronisasi. Mengosongkannya akan membuat
            // nota ini masuk daftar "Belum sinkron" yang berisi antrean perangkat
            // offline -- dan satu baris palsu di sana membuat seluruh daftar
            // tidak bisa dipercaya.
            'synced_at' => now(),
        ]);

        foreach ($lines as $line) {
            $item = SaleItem::create([
                'sale_id' => $sale->id,
            ] + $line->saleItemAttributes());

            $this->accrue($line, $sale, $item);
            $this->cutStock($line, $sale, $checkout);
        }

        foreach ($checkout->payments as $payment) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
            ]);
        }

        return $sale;
    }

    /**
     * Akrual hak penitip untuk satu baris titipan.
     *
     * Halaman saldo penitip membaca `consignor_ledger`, bukan `sales`: tanpa
     * entri ini penjualan tidak pernah mengurangi saldo penitip mana pun, dan
     * settlement berikutnya menagih barang yang sudah laku tanpa pernah tahu.
     *
     * Untuk stok milik toko sendiri `hakPenitip` memang `null`, sehingga tidak
     * ada baris yang ditulis -- bukan nol yang berarti "haknya nol", tapi
     * memang tidak ada hak yang bisa dibagi.
     */
    private function accrue(PricedLine $line, Sale $sale, SaleItem $item): void
    {
        $consignorId = $line->lot->consignor_id;

        if ($line->hakPenitip === null || $consignorId === null) {
            return;
        }

        ConsignorLedger::create([
            'consignor_id' => $consignorId,
            'type' => LedgerType::SaleAccrual,
            'amount' => $line->hakPenitip,
            'sale_item_id' => $item->id,
            'sale_id' => $sale->id,
            'reason' => sprintf(
                'Akrual hak penitip %s (%s)',
                $line->lot->sku,
                $line->schemeType?->value ?? '-',
            ),
            'created_at' => now(),
        ]);
    }

    /**
     * Potong stok lot dan catat gerakannya.
     *
     * `stock_movements` adalah catatan append-only yang menjadi satu-satunya
     * riwayat barang keluar; `qty_on_hand` hanyalah saldonya. Mengubah saldo
     * tanpa baris gerakan membuat angka di Live Stock tidak bisa dijelaskan
     * kepada siapa pun yang menanyakan "unit terakhir ke mana".
     */
    private function cutStock(PricedLine $line, Sale $sale, Checkout $checkout): void
    {
        $lot = $line->lot;

        $lot->qty_on_hand -= $line->qty;
        $lot->last_sold_at = now();

        if ($lot->qty_on_hand === 0) {
            $lot->status = LotStatus::SoldOut;
        }

        $lot->save();

        StockMovement::create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -$line->qty,
            'ref_type' => Sale::class,
            'ref_id' => $sale->id,
            'actor_id' => $checkout->cashier->id,
            'device_id' => $checkout->deviceId,
            'reason' => null,
            'balance_after' => $lot->qty_on_hand,
            'created_at' => now(),
        ]);
    }
}
