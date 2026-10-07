<?php

namespace App\Services\Inventory;

use RuntimeException;

/**
 * Dilempar saat cetakan ulang melewati batas dan tidak ada otorisasi Owner yang
 * menyertainya (FR-IB-22).
 *
 * Kenapa bukan `ValidationException` dari dalam service: karena yang menyusun
 * ulang kalimat penolakannya sudah `ReprintVerdict`, dan service tidak perlu
 * tahu bentuk HTTP-nya. Controller memetakan ini ke 422 pada field `copies`
 * supaya pesan sampai ke baris jumlah label yang sedang diisi operator --
 * field yang sedang diketik ketika dia menekan tombol.
 *
 * 422 dan bukan 403: petugas Staff yang tidak berlebihan tidak salah, dan
 * aksi yang sama akan lolos begitu membawa token PIN. Mengembalikan 403 akan
 * membuat operator mengira dirinya tidak berhak dan berhenti mencoba sama
 * sekali.
 */
class ReprintLimitExceeded extends RuntimeException
{
    public function __construct(public readonly ReprintVerdict $verdict)
    {
        parent::__construct($verdict->summary());
    }
}
