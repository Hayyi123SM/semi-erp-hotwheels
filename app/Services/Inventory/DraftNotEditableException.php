<?php

namespace App\Services\Inventory;

use App\Models\Consignment;
use RuntimeException;

/**
 * Dilempar saat draft sudah di-commit dan tak boleh diubah lagi (BR-01: pemilik,
 * qty, dan skema terkunci sejak commit). Controller memetakan ini ke 409 supaya
 * pesan yang muncul di browser menjelaskan why, bukan generic 500.
 */
class DraftNotEditableException extends RuntimeException
{
    public function __construct(public readonly Consignment $consignment)
    {
        parent::__construct("Consignment {$consignment->doc_no} sudah di-commit dan tidak bisa diubah.");
    }
}
