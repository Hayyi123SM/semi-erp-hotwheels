<?php

namespace App\Enums;

use App\Support\Format;

/**
 * Keadaan satu baris dalam sesi opname.
 *
 * `Counted` menambah satu keadaan di antara "sudah dihitung tapi selisihnya
 * nol" dan "sudah diputuskan". Tanpanya, baris yang menunggu persetujuan harus
 * memakai `Pending` -- yang juga dipakai baris yang belum sama sekali dihitung,
 * sehingga "belum dihitung" dan "menunggu Owner" harus dibedakan lewat
 * `counted_qty` yang null, yaitu lewat keadaan kolom, bukan lewat statusnya.
 *
 * Status adalah satu-satunya yang dibaca untuk menentukan aksi berikutnya, jadi
 * ia harus menjawab pertanyaannya sendiri.
 */
enum OpnameLineStatus: string
{
    /** Belum dihitung. */
    case Pending = 'PENDING';

    /** Sudah dihitung, selisih nol: tidak butuh persetujuan siapa pun. */
    case Ok = 'OK';

    /** Sudah dihitung, selisih ≠ 0: menunggu keputusan Owner. */
    case Counted = 'COUNTED';

    /** Disetujui Owner: selisih sudah diterapkan ke stok. */
    case Approved = 'APPROVED';

    /** Ditolak Owner: selisih dicatat, stok tidak diubah. */
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return Format::statusLabel($this->value);
    }
}
