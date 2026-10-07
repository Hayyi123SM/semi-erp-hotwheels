<?php

namespace App\Services\Inventory;

use App\Enums\ConsignmentStatus;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-IB-13/14: draft consignment yang bisa dilanjutkan setelah tab tertutup,
 * HP mati, atau Staff_tmp refill. Draft adalah jaring pengaman, bukan sumber
 * kebenaran -- yang dipakai saat commit tetap payload yang di-submit Staff, karena
 * form bisa saja sudah 10 detik lebih baru dari auto-save terakhir.
 */
class ConsignmentDraftService
{
    public function start(User $actor, array $header = []): Consignment
    {
        return DB::transaction(function () use ($actor, $header) {
            $draft = Consignment::create([
                'doc_no' => 'DRFT-'.Str::upper(Str::substr((string) Str::uuid(), 0, 8)),
                'draft_id' => (string) Str::uuid(),
                'status' => ConsignmentStatus::Draft,
                'consignment_date' => $header['consignment_date'] ?? now()->toDateString(),
                'consignor_id' => $header['consignor_id'] ?? null,
                'source' => $header['source'] ?? null,
                'notes' => $header['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->syncItems($draft, $header['items'] ?? []);

            $this->touchSaved($draft);

            return $draft->load('items');
        });
    }

    /**
     * Auto-save. Sengaja tidak melempar exception ke pemanggil: autosave dijalankan
     * dari debounce di browser, dan kegagalan network di sini tidak boleh
     * menggagalkan submit yang sedang dilakukan Staff.
     */
    public function save(Consignment $draft, array $header): ?Consignment
    {
        return DB::transaction(function () use ($draft, $header) {
            $this->guardEditable($draft);

            $draft->fill([
                'consignment_date' => $header['consignment_date'] ?? $draft->consignment_date,
                'consignor_id' => $header['consignor_id'] ?? $draft->consignor_id,
                'source' => $header['source'] ?? $draft->source,
                'notes' => $header['notes'] ?? $draft->notes,
                'qty_claimed' => $header['qty_claimed'] ?? $draft->qty_claimed,
                'variance_note' => $header['variance_note'] ?? $draft->variance_note,
            ])->save();

            $this->syncItems($draft, $header['items'] ?? []);

            $this->touchSaved($draft);

            return $draft->load('items');
        });
    }

    /**
     * Menutup draft yang memang tidak akan dipakai Staff -- bukan karena commit.
     * Barisnya dihapus, bukan di-archive: doc_no `DRFT-` tidak pernah dipakai
     * dokumen nyata, dan tidak ada yang perlu mempertahankan jejaknya.
     */
    public function discard(Consignment $draft): void
    {
        $this->guardEditable($draft);

        DB::transaction(function () use ($draft) {
            $draft->items()->delete();
            $draft->delete();
        });
    }

    /**
     * Draft milik Staff tertentu yang masih bisa dilanjutkan.
     */
    public function findOwnedDraft(string $draftId, User $actor): ?Consignment
    {
        return Consignment::query()
            ->where('draft_id', $draftId)
            ->where('status', ConsignmentStatus::Draft)
            ->where('created_by', $actor->id)
            ->with('items')
            ->first();
    }

    /**
     * Dokumen milik Staff tertentu dengan `draft_id` tersebut, apa pun statusnya.
     *
     * Dipakai saat commit dan saat autosave, bukan `findOwnedDraft()`. Bedanya
     * menentukan apakah pengulangan commit aman: kalau commit kedua hanya
     * mencari dokumen berstatus `DRAFT`, dokumen yang sudah di-commit oleh
     * percobaan pertama tidak akan ditemukan, tidak ada yang mengenali ini sebagai pengulangan,
     * dan barang masuk gudang dua kali.
     */
    public function findOwnedDocument(string $draftId, User $actor): ?Consignment
    {
        return Consignment::query()
            ->where('draft_id', $draftId)
            ->where('created_by', $actor->id)
            ->with('items')
            ->first();
    }

    public function listFor(User $actor, int $limit = 20)
    {
        return Consignment::query()
            ->where('status', ConsignmentStatus::Draft)
            ->where('created_by', $actor->id)
            ->with('consignor')
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Total qty dari baris draft. Dipakai untuk badges "draft" supaya Staff tahu
     * ada pekerjaan yang belum di-commit tanpa harus membuka draftnya dulu.
     */
    public function draftQty(Consignment $draft): int
    {
        return (int) $draft->items()->sum('qty');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(Consignment $draft, array $items): void
    {
        $draft->items()->delete();

        $rows = [];
        $lineNo = 0;

        foreach ($items as $item) {
            if (blank($item['product_id'] ?? null) || blank($item['qty'] ?? null)) {
                continue;
            }

            $lineNo++;

            // Kondisi boleh tidak dikirim di draft: saat commit kondisi yang kosong
            // berarti "mewarisi kondisi default produk", dan draft harus bisa
            // menyimpan keadaan itu tanpa gagal. `ReceiveStock` yang memutuskan
            // pewarisan itu nanti, jadi di sini cukup disimpan apa adanya.
            $rows[] = [
                'consignment_id' => $draft->id,
                'line_no' => $lineNo,
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'rack_id' => $item['rack_id'] ?? null,
                'card_condition' => $item['card_condition'] ?? null,
                'blister_condition' => $item['blister_condition'] ?? null,
                'list_price' => $item['list_price'] ?? null,
                'scheme_type' => $item['scheme_type'] ?? null,
                'scheme_rate' => $item['scheme_rate'] ?? null,
                'scheme_amount' => $item['scheme_amount'] ?? null,
                'discount_policy' => $item['discount_policy'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            ConsignmentItem::insert($rows);
        }
    }

    private function touchSaved(Consignment $draft): void
    {
        $draft->forceFill(['saved_at' => now()])->save();
    }

    /**
     * Draft yang sudah di-commit tidak boleh ditimpa. Menyalin ulang isinya
     * berarti ditempatkan ulang tanpa idempotency key, dan itu persis duplikasi
     * yang FR-IB-14 minta dicegah.
     */
    private function guardEditable(Consignment $draft): void
    {
        if ($draft->status !== ConsignmentStatus::Draft) {
            throw new DraftNotEditableException($draft);
        }
    }
}
