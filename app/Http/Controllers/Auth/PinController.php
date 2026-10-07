<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PinService;

class PinController extends Controller
{
    public function __construct(
        private readonly PinService $pins,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tukar PIN Owner yang diketik menjadi token berumur pendek.
     *
     * Endpoint ini menjawab "apakah PIN ini benar", bukan "apakah aksi ini
     * boleh". Yang kedua diputuskan lagi di FormRequest yang menerima tokennya,
     * karena itu sebabnya token memakai konteks: satu verifikasi dicatat sebagai
     * sah untuk aksi tertentu, bukan sebagai tiket serbaguna.
     */
    public function verify(VerifyPinRequest $request)
    {
        $actor = $request->user();
        $context = $request->pinContext();

        // Melempar ValidationException, jadi PIN yang salah sampai ke klien sebagai
        // 422 dengan `errors.pin`, sama seperti validation form mana pun, bukan
        // sebagai pesan yang harus dibaca pemanggil.
        $grant = $this->pins->issue($actor, (string) $request->validated('pin'), $context);

        // Yang meminta adalah kasir, yang mengotorisasi adalah Owner. Keduanya
        // dicatat supaya riwayat audit tidak terlihat seperti kasir yang
        // menyetujui miliknya sendiri.
        $this->audit->log('AUTHORIZE_PIN', User::class, $grant->owner->getKey(), [], [
            'context' => $context,
            'requested_by' => $actor->getKey(),
            'expires_at' => $grant->expiresAt->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'PIN Owner diterima.',
            'token' => $grant->token,
            'expires_at' => $grant->expiresAt->toIso8601String(),
            'context' => $context,
            'owner' => $grant->owner->name,
        ]);
    }
}
