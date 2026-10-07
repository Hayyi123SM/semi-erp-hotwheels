<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use DateTimeInterface;

/**
 * Hasil tukar PIN Owner menjadi token yang boleh ikut di form.
 *
 * `owner` ikut dibawa bukan untuk ditampilkan, melainkan supaya pemanggil yang
 * menulis jejak audit tahu siapa yang sebenarnya mengotorisasi aksi tersebut.
 * Id yang masuk ke token adalah milik Owner, sedangkan id yang mencatat adalah
 * milik kasir yang meminta dan mengetik PIN-nya.
 */
final readonly class PinGrant
{
    public function __construct(
        public string $token,
        public DateTimeInterface $expiresAt,
        public User $owner,
    ) {}
}
