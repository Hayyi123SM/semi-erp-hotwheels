<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\DeviceId;
use InvalidArgumentException;

class AuditLogger
{
    /**
     * Catat satu perubahan.
     *
     * Ada dua cara menyebut apa yang berubah, dan pemanggilnya tidak boleh
     * mencampur keduanya:
     *
     * - `$entityId` untuk entitas berprimary key: `StockLot`, `User`,
     *   `LabelPrintJob`. Isinya integer, dan itu bukan pilihan gaya --
     *   kolomnya `unsignedBigInteger`. String di sini ditolak MySQL dengan
     *   "Incorrect integer value", dan karena suite harian berjalan di SQLite
     *   yang tidak menegakkan tipe kolom, bentuk yang salah bisa lolos semua
     *   test lalu 500 di produksi.
     * - `$entityKey` untuk entitas yang identitasnya justru berupa teks, yaitu
     *   `Setting` yang tidak punya primary key. Kuncinya seperti
     *   `label.printer`.
     *
     * Keduanya tidak pernah diisi bersamaan. Kalau diisi, nama entitas jadi
     * ambigu: pembaca log tidak tahu `entity_id` merujuk ke yang mana, dan
     * menjawabnya berarti menebak.
     *
     * `@param  array<string, mixed>  $before`  nilai sebelum perubahan
     *
     * @param  array<string, mixed>  $after`  nilai sesudah perubahan
     *
     * Bentuk `$before` dan `$after` sengaja dibuat rata: field yang sama,
     * urutan yang sama. Laporan audit menampilkan keduanya berdampingan sebagai
     * diff, dan bentuk rata membuat itu terlihat tanpa logika khusus per
     * halaman. Bentuk bersarang `['from' => ..., 'to' => ...]` di dalam `after`
     * memaksa pembaca laporan tahu bentuk mana yang sedang gegenüberannya.
     *
     * @throws InvalidArgumentException hanya untuk pemanggil yang salah isi
     *                                  kedua jenis identitas sekaligus, yang
     *                                  tidak bisa diam-diam diterima karena
     *                                  hasilnya ambigu.
     */
    public function log(
        string $action,
        string $entity,
        ?int $entityId = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?string $entityKey = null,
    ): void {
        $this->refuseAmbiguousIdentity($entityId, $entityKey);

        AuditLog::create([
            'user_id' => auth()->id(),
            'device_id' => $this->deviceId(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'entity_key' => $entityKey,
            'before' => $before !== [] ? $before : null,
            'after' => $after !== [] ? $after : null,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Nama entitas tidak boleh punya dua identitas sekaligus.
     *
     * Pemeriksaan ini ada karena bentuk yang salahnya tidak terlihat dari
     * tipe: `entity_id` menerima `null`, `entity_key` menerima `null`, dan
     * kedua kolomnya nullable. Tanpa penjaga, pemanggil bisa mengisi keduanya
     * dan lognya akan tersimpan dengan dua identitas yang saling bertentangan
     * tanpa satu pun yang gagal.
     */
    private function refuseAmbiguousIdentity(?int $entityId, ?string $entityKey): void
    {
        if ($entityId === null || $entityKey === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Audit log hanya boleh punya satu identitas: entityId %d dan entityKey "%s" '
            .'untuk entitas yang sama. Pilih yang sesuai dengan jenis identitasnya.',
            $entityId,
            $entityKey,
        ));
    }

    public function created(mixed $model, array $extra = []): void
    {
        $this->log('CREATED', class_basename($model), $model->getKey(), [], $model->getAttributes(), $extra['reason'] ?? null);
    }

    public function updated(mixed $model, array $before, array $extra = []): void
    {
        $after = $model->getChanges();
        if ($after === []) {
            return;
        }

        $this->log('UPDATED', class_basename($model), $model->getKey(), $before, $after, $extra['reason'] ?? null);
    }

    public function archived(mixed $model, array $extra = []): void
    {
        $this->log('ARCHIVED', class_basename($model), $model->getKey(), $model->getAttributes(), [], $extra['reason'] ?? null);
    }

    public function delete(mixed $model, array $extra = []): void
    {
        $this->log('DELETED', class_basename($model), $model->getKey(), $model->getAttributes(), [], $extra['reason'] ?? null);
    }

    public function imported(string $entity, array $summary): void
    {
        $this->log('IMPORT', $entity, null, [], $summary);
    }

    /**
     * Perangkat yang sedang dipakai, menurut aturan yang sama dengan shift kasir.
     *
     * Tetap method, bukan fungsi statis, karena pemanggil yang sudah ada
     * (`InboundController`) memakainya lewat instance `$this->audit`.
     */
    public function deviceId(): ?string
    {
        return DeviceId::current();
    }
}
