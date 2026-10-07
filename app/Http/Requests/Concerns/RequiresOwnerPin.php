<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Auth\PinService;
use Closure;

/**
 * Otorisasi Owner untuk satu FormRequest, lewat token dari dialog PIN global.
 *
 * Dipakai request yang menjalankan aksi yang nama Owner di atasnya: menimpa skema
 * penitip saat commit, mencetak label melebihi qty, membatalkan transaksi, dan
 * seterusnya. Semuanya melewati kode yang sama di sini, jadi aturan "kapan butuh
 * PIN" hanya ada di satu tempat.
 *
 * Pakai ini berarti request menambahkan satu baris di `rules()`:
 *
 *     public function rules(): array
 *     {
 *         return [
 *             ...$this->ownerPinRules(),
 *             'scheme' => ['required', 'in:PERCENTAGE,NETT,FLAT'],
 *         ];
 *     }
 *
 * Satu baris, bukan dua. Versi sebelumnya minta pemanggil juga menulis
 * `withValidator()` dan memanggil `verifyOwnerPin()` di dalamnya, dengan alasan
 * bahwa trait tidak boleh diam-diam menimpa method milik pemakai. Tapi pemeriksaan
 * yang ditambahkan ke `$validator->errors()` setelah aturan dievaluasi tidak
 * membuat validasi gagal sama sekali: FormRequest sudah menganggap request ini
 * lolos, request diteruskan ke controller, dan kalimat penolakannya menempel di
 * session tanpa pernah dipakai. Aturan PIN harus jadi aturan validasi, bukan
 * pesan yang ditambahkan belakangan.
 */
trait RequiresOwnerPin
{
    /**
     * Field tempat token diletakkan di form.
     */
    protected function ownerPinField(): string
    {
        return 'pin_token';
    }

    /**
     * Konteks yang dicantumkan di dalam token.
     *
     * Token hanya sah untuk konteks yang sama, jadi pemanggil harus menyebut
     * konteks yang berbeda untuk setiap aksi. Menyamakan dua aksi yang berbeda
     * membuat token yang bocor untuk satu aksi berlaku untuk yang lain juga.
     */
    abstract protected function ownerPinContext(): string;

    /**
     * Apakah request ini memang butuh PIN.
     *
     * Default-nya: selain Owner. Owner tidak perlu mengotorisasi dirinya sendiri
     * untuk aksi yang memang hanya boleh dia yang lakukan, dan memaksanya
     * mengetik PIN hanya menambah langkah tanpa menambah keamanan.
     */
    protected function requiresOwnerPin(): bool
    {
        return ! $this->user()?->isOwner();
    }

    /**
     * Aturan untuk field token, untuk disebar ke `rules()`.
     *
     * Kosong saat tidak butuh PIN, sehingga field-nya tidak sekadar opsional:
     * field itu tidak diperiksa sama sekali, karena tidak ada yang perlu
     * diperiksa.
     *
     * @return array<string, list<mixed>>
     */
    protected function ownerPinRules(): array
    {
        if (! $this->requiresOwnerPin()) {
            return [];
        }

        return [
            $this->ownerPinField() => [
                'required',
                'string',
                'max:4096',
                $this->ownerPinCheck(),
            ],
        ];
    }

    /**
     * Aturan yang menukar token di form menjadi "sah untuk aksi ini" atau
     * "ditolak karena sebab ini".
     *
     * Pesan penolakannya diambil dari `PinService`, bukan ditulis di sini, agar
     * "belum ada Owner yang mengatur PIN" dan "PIN-nya salah" -- yang hanya satu
     * dari keduanya yang layak dicoba lagi -- tetap berbeda di mana pun token
     * diperiksa.
     */
    protected function ownerPinCheck(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $reason = app(PinService::class)->describeFailure(
                $this->user(),
                is_string($value) ? $value : null,
                $this->ownerPinContext(),
            );

            if ($reason !== null) {
                $fail($reason);
            }
        };
    }

    /**
     * Apakah token yang ikut di form ini sudah sah untuk aksi ini.
     *
     * Public, bukan protected, karena pemanggilnya adalah controller atau service
     * yang akan menjalankan aksi -- bukan request itu sendiri.
     *
     * Mengikuti `requiresOwnerPin()` persis, bukan memutuskan sendiri. Kalau
     * method ini tetap menuntut token untuk Owner, sedangkan `ownerPinRules()`
     * tidak, maka request milik Owner lolos validasi lalu ditolak lagi satu layer
     * di bawahnya, dengan alasan yang tidak pernah muncul di form.
     */
    public function ownerPinIsSatisfied(): bool
    {
        if (! $this->requiresOwnerPin()) {
            return true;
        }

        $token = $this->input($this->ownerPinField());

        return app(PinService::class)->check(
            $this->user(),
            is_string($token) ? $token : null,
            $this->ownerPinContext(),
        );
    }
}
