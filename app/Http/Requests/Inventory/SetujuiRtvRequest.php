<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setujui sekaligus eksekusi satu dokumen RTV (FR-IC-33).
 *
 * Tidak ada field lain di request ini: semua yang dibutuhkan eksekusi --
 * baris, qty, hasil scan -- sudah tersimpan di dokumen sejak langkah sebelumnya.
 * Yang ditambahkan form ini hanyalah otorisasi, dan otorisasi itu milik aksi
 * ini sendiri: token untuk opname atau untuk aksi lain tidak diterima di sini,
 * karena keduanya berbeda dampaknya terhadap stok dan berbeda siapa yang boleh
 * memakainya.
 *
 * PIN hanya menuntut Staff. Owner yang menekan tombol tidak perlu
 * mengotorisasi dirinya sendiri -- memaksanya mengetik PIN hanya menambah
 * langkah tanpa menambah keamanan, sesuai `RequiresOwnerPin`.
 */
class SetujuiRtvRequest extends FormRequest
{
    use RequiresOwnerPin;

    protected function ownerPinContext(): string
    {
        return 'inventory.rtv-approve';
    }

    public function rules(): array
    {
        return [
            ...$this->ownerPinRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'pin_token.required' => 'Verifikasi PIN Owner diperlukan untuk menyetujui pengeluaran barang lewat RTV.',
        ];
    }

    /**
     * Siapa yang menyetujui: Owner lewat token PIN, atau Owner sendiri yang
     * mengklik. Staff tanpa token tidak akan sampai ke sini -- aturan PIN di
     * atas menahannya -- tetapi `null` tetap dikembalikan sebagai bentuk paling
     * jujur dari "tidak ada yang menotorisasi", bukan jatuh ke pemohon.
     */
    public function approver(): ?User
    {
        $user = $this->user();

        if ($user?->isOwner()) {
            return $user;
        }

        $approver = app(PinService::class)->approverFor(
            $user,
            $this->input($this->ownerPinField()),
            $this->ownerPinContext(),
        );

        return $approver instanceof User ? $approver : null;
    }
}
