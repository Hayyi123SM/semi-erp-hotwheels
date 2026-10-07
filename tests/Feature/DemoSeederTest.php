<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Data contoh harus bisa dipercaya seperti data asli.
 *
 * Daftar "Belum sinkron" adalah antrean perangkat yang menjual saat koneksi
 * putus. Nota demo yang ikut masuk ke sana membuat filternya tidak bisa
 * dipakai untuk apa pun: satu baris palsu membuat seluruh daftar diragukan,
 * dan petugas yang membukanya akan mencari perangkat offline yang memang tidak
 * pernah ada.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_demo_notes_are_not_queued_as_unsynchronised(): void
    {
        $this->seed();

        $this->assertCount(3, Sale::all());
        $this->assertSame(
            0,
            Sale::query()->whereNull('synced_at')->count(),
            'Nota demo harus tercatat sebagai penjualan yang sudah sampai ke server.',
        );
    }
}
