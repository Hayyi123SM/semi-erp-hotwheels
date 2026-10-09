<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Inisial avatar dari nama user.
 *
 * Satu-satunya sumber inisial di seluruh aplikasi. Kalau dua halaman membuat
 * inisial dengan aturannya sendiri, avatar boleh tampil berbeda di dua tempat
 * untuk orang yang sama -- jadi aturannya satu, di model.
 */
class UserInitialsTest extends TestCase
{
    #[Test]
    #[DataProvider('names')]
    public function it_derives_initials_from_the_name(?string $name, ?string $username, string $expected): void
    {
        $user = new User([
            'name' => $name,
            'username' => $username,
        ]);

        $this->assertSame($expected, $user->initials());
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function names(): iterable
    {
        yield 'dua kata' => ['Ahmad Fauzi', 'ahmad.fauzi', 'AF'];
        yield 'tiga kata memakai kata pertama dan terakhir' => ['Ahmad Fauzi Santoso', 'ahmad', 'AS'];
        yield 'satu kata' => ['Andi', 'andi', 'A'];
        yield 'inisial dalam nama' => ['A. Fauzi', 'afauzi', 'AF'];
        yield 'nama kosong jatuh ke username' => ['', 'dewilestari', 'D'];
        yield 'nama dan username kosong' => [null, null, '?'];
    }
}
