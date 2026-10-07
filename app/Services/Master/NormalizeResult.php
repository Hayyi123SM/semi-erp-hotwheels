<?php

declare(strict_types=1);

namespace App\Services\Master;

/**
 * Hasil satu kali penORMALisan nomor WhatsApp.
 *
 * Ada supaya migration dan test bisa membaca apa yang sebenarnya terjadi tanpa
 * menggali log, dan supaya pemanggil tidak perlu tahu bahwa "baris yang
 * dikosongkan" menyimpan nilai lamanya sebagai isi.
 */
final readonly class NormalizeResult
{
    /**
     * @param  int  $rewritten  baris yang ditulis ulang ke bentuk polos
     * @param  array<int, string|null>  $emptied  id yang dikosongkan => nilai lamanya
     */
    public function __construct(
        public int $rewritten = 0,
        public array $emptied = [],
    ) {}

    public function emptiedCount(): int
    {
        return count($this->emptied);
    }

    public function changedAnything(): bool
    {
        return $this->rewritten > 0 || $this->emptied !== [];
    }
}
