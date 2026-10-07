<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Cara label ditata di atas kertas yang dimuat ke printer.
 *
 * Nilai ini disimpan sebagai setting perangkat, jadi nilai `case` di sini adalah
 * format yang tersimpan di database -- persis seperti `PaperSize`. Mengubahnya
 * berarti setting lama menjadi nilai yang tidak dikenal, dan `parse()` akan
 * melempar; pemanggil dari form sudah memvalidasinya, dan pembacaan dari halaman
 * cetak memakai `tryFrom()` supaya tidak ikut mati.
 *
 * Dua mode ini bukan pilihan selera. Keduanya menghasilkan geometri dan aturan
 * cetak yang berbeda total:
 *
 * - `Roll`  kertas continuous. Tidak ada ukuran halaman yang terstruktur, jadi
 *           satu label satu halaman dan printer yang menarik gulungan melakukan
 *           pemotongan.
 * - `Sheet` kertas stiker yang sudah dipotong, jadi label ditata dalam grid dan
 *           halaman print adalah ukuran kertasnya.
 *
 * Default-nya `Roll`. Itu mode yang sudah dipakai sistem sebelum fitur ini ada,
 * jadi printer yang masih memakai gulungan tidak ikut berubah hanya karena Owner
 * membuka halaman Pengaturan.
 */
enum LabelPaperMode: string
{
    case Roll = 'roll';

    case Sheet = 'sheet';

    public function label(): string
    {
        return match ($this) {
            self::Roll => 'Gulungan (satu label per halaman)',
            self::Sheet => 'Kertas stiker (grid di atas kertas)',
        };
    }

    /**
     * Penjelasan untuk halaman Pengaturan.
     *
     * Ditulis lengkap karena dampaknya besar dan tidak terlihat dari nama
     * menunya: memilih mode yang salah membuat seluruh label keluar dengan
     * jarak yang salah, dan gejalanya baru terlihat di depan printer.
     */
    public function description(): string
    {
        return match ($this) {
            self::Roll => 'Printer thermal menarik kertas continuous dan memotong per label. Pilih ini kalau label Anda datang dari roll.',
            self::Sheet => 'Label ditata dalam baris dan kolom di atas kertas stiker yang sudah dipotong. Pilih ini untuk kertas stiker A6.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ],
            self::cases(),
        );
    }

    /**
     * @throws InvalidArgumentException bila nilai tidak dikenal.
     */
    public static function parse(?string $value): self
    {
        $candidate = self::tryFrom((string) $value);

        if ($candidate === null) {
            throw new InvalidArgumentException("Mode kertas label tidak dikenal: '{$value}'.");
        }

        return $candidate;
    }
}
