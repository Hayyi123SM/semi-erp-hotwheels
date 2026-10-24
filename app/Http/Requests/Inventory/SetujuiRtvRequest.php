<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setujui sekaligus eksekusi satu dokumen RTV (FR-IC-33).
 *
 * Tidak ada field lain di request ini: semua yang dibutuhkan eksekusi --
 * baris, qty, hasil scan -- sudah tersimpan di dokumen sejak langkah sebelumnya.
 * Yang ditambahkan form ini hanyalah otorisasi, dan otorisasi itu milik aksi
 * ini sendiri.
 *
 * Tidak ada otorisasi tambahan di sini. Modul Inventory berada di dalam grup
 * `['auth', 'owner']` (`routes/web.php`), jadi request yang sampai ke class ini
 * selalu dibawa Owner; Staff ditolak 403 sebelum aturan validasi sempat dibaca.
 * Memintanya lagi dari Owner sendiri hanya menambah satu langkah tanpa
 * menambah satu lapis pengaman -- persis seperti yang sudah dilakukan untuk
 * keputusan selisih opname di `ReviewBarisRequest`.
 */
class SetujuiRtvRequest extends FormRequest
{
    /**
     * Siapa yang menyetujui.
     *
     * Selalu orang yang menekan tombol: modul ini Owner-only, jadi tidak ada
     * pihak lain yang bisa sampai ke sini.
     */
    public function approver(): User
    {
        return $this->user();
    }
}
