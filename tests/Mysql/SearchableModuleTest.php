<?php

namespace Tests\Mysql;

use App\Models\User;
use App\Support\DataTable\Fragment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every searchable list, asked for a term, on MySQL.
 *
 * One test per module rather than one test for all of them, because the failure
 * this suite exists for was not visible in any result: the pages returned a 500
 * while the whole SQLite suite stayed green. Asserting the status alone would
 * have caught that; asserting the row that came back says the answer is the one
 * the reader asked for.
 */
class SearchableModuleTest extends MySqlTestCase
{
    /**
     * Route names, not URLs: a provider runs before the application is booted,
     * so there is no router to ask for a path yet.
     *
     * @return array<string, array{string}>
     */
    public static function modules(): array
    {
        return [
            'Data Penitip' => ['master.penitip'],
            'Katalog Produk' => ['master.katalog-produk'],
            'Lokasi Rak' => ['master.lokasi-rak'],
            'Pengguna' => ['setting.pengguna'],
            'Riwayat Titipan' => ['inbound.consignment-in.riwayat'],
            // Laporan audit mencari di dalam `json` dengan `LIKE` biasa, dan
            // kolom `json` MySQL hanya menerima `LIKE` yang sudah di-cast eksplisit
            // atau ke bentuk teks. Suite ini yang memberitahu kalau bentuk itu
            // ternyata tidak diterima mesin.
            'Audit Log' => ['report.audit-log'],
        ];
    }

    #[Test]
    #[DataProvider('modules')]
    public function a_search_term_is_answered(string $route): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route($route, ['q' => 'asdf']))->assertOk();
    }

    #[Test]
    #[DataProvider('modules')]
    public function a_wildcard_typed_by_the_reader_does_not_break_the_query(string $route): void
    {
        $this->actingAs(User::factory()->owner()->create());

        // What an operator types while meaning something else entirely: this is
        // the shape of input that used to take a page down.
        $this->get(route($route, ['q' => '50%_asdf']))->assertOk();
    }

    #[Test]
    #[DataProvider('modules')]
    public function the_in_place_refresh_is_answered_for_the_same_term(string $route): void
    {
        $this->actingAs(User::factory()->owner()->create());

        // The refresh asks the same question and swaps the answer in. It has to
        // hold to the same terms, or a list would load and then break on the
        // first keystroke.
        $this->get(route($route, ['q' => 'asdf']), [Fragment::HEADER => '1'])->assertOk();
    }
}
