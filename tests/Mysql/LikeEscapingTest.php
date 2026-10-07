<?php

namespace Tests\Mysql;

use App\Models\Consignor;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Inventory\StockLotSearch;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * `LIKE` escaping, checked on the engine the application runs on.
 *
 * Everything here passes on SQLite, and that is the point: the SQLite suite is
 * how a pattern that MySQL rejects got as far as production. MySQL treats a
 * backslash as the escape character whether or not a query says so, and reads
 * `ESCAPE '\'` as an unterminated string literal, so a search built for one
 * engine is not a search that merely behaves differently on the other -- it is a
 * query the server refuses outright.
 */
class LikeEscapingTest extends MySqlTestCase
{
    private function consignor(string $code, string $name, ?string $bankHolder = null): Consignor
    {
        return Consignor::create([
            'consignor_code' => $code,
            'name' => $name,
            'status' => 'ACTIVE',
            'settlement_cycle' => 'MONTHLY',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 10,
            'bank_holder' => $bankHolder,
        ]);
    }

    private function search(string $term): int
    {
        return DataTable::for(request()->merge(['q' => $term]), Consignor::query())
            ->searchable(['name', 'consignor_code'])
            ->columns([Column::make('name', 'Nama')])
            ->rows()
            ->total();
    }

    #[Test]
    public function it_is_actually_talking_to_mysql(): void
    {
        // Guards the rest of this file: a suite that quietly fell back to SQLite
        // would pass every assertion below while proving nothing. The version is
        // reported rather than matched, because the number is not the claim --
        // which engine is asked is.
        $this->assertSame('mysql', config('database.default'), 'Running on '.$this->engine());
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    #[Test]
    public function a_search_term_is_answered_at_all(): void
    {
        $this->consignor('CN01', 'Budi Santoso');
        $this->consignor('CN02', 'Siti Aminah');

        // The regression in one line: this raised a 500 on every searchable page.
        $this->assertSame(1, $this->search('budi'));
    }

    #[Test]
    public function a_wildcard_character_in_the_term_is_ordinary_text(): void
    {
        $this->consignor('CN01', 'Budi Santoso');
        $this->consignor('CN02', 'Budi% Santoso');
        $this->consignor('CN03', 'Andi Saputra');
        $this->consignor('CN04', 'Andi-Saputra');
        $this->consignor('CN05', 'Andi_Saputra');

        $this->assertSame(1, $this->search('Budi%'));
        $this->assertSame(1, $this->search('i_Sap'), 'Underscore must not match any character.');
        $this->assertSame(1, $this->search('%'), 'A bare wildcard must not match every row.');
    }

    #[Test]
    public function the_escape_character_itself_is_ordinary_text(): void
    {
        $this->consignor('CN01', 'Gigi Santoso');
        $this->consignor('CN02', 'Gigi! Santoso');
        $this->consignor('CN03', 'Gigi!X Santoso');

        $this->assertSame(1, $this->search('Gigi!X'));
    }

    #[Test]
    public function a_backslash_in_the_term_is_ordinary_text(): void
    {
        // The character the old escape used. It is ordinary now, and nothing in
        // the pattern building may treat it as syntax again.
        $this->consignor('CN01', 'Budi Santoso');
        $this->consignor('CN02', 'Budi\ Santoso');

        $this->assertSame(1, $this->search('Budi\\'));
        $this->assertSame(1, $this->search('Budi\ Santoso'));
    }

    #[Test]
    public function the_declared_escape_character_is_the_one_the_pattern_uses(): void
    {
        $this->consignor('CN01', 'Budi Santoso');

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });

        $this->search('budi');

        $like = collect($queries)->first(fn ($sql) => str_contains(mb_strtolower($sql), 'like'));

        $this->assertNotNull($like);
        $this->assertStringContainsString("escape '!'", mb_strtolower($like));
    }

    /**
     * Kolom yang isinya terenkripsi harus bisa menampung ciphertext-nya.
     *
     * Bug ini hanya terlihat di MySQL. Kolomnya `varchar(255)`, sementara
     * `encrypted` menambah JSON dan tag integritas: nama 24 karakter sudah
     * jadi 256 byte dan ditolak. SQLite tidak menegakkan batas `varchar`, jadi
     * suite harian hijau terus sementara produksi menolak menyimpan nama penitip
     * yang sedikit lebih panjang.
     *
     * Panjangnya diambil dari batas yang benar-benar ada di aplikasi, bukan
     * angka tebakan: nama panjang di sini sengaja melewati ambang itu supaya
     * test gagal kalau kolomnya pernah dikecilkan lagi.
     */
    #[Test]
    public function an_encrypted_bank_holder_that_outgrows_varchar_still_fits(): void
    {
        $longName = 'Budi Santoso Wibowo Kusumo';
        $this->assertGreaterThan(
            255,
            strlen(encrypt($longName)),
            'Test ini tidak lagi menguji batas kolom kalau ciphertext-nya muat di 255.',
        );

        $consignor = $this->consignor('CN99', 'Budi Santoso', bankHolder: $longName);

        $this->assertSame($longName, $consignor->fresh()->bank_holder);
    }

    #[Test]
    public function lot_search_escapes_the_same_way(): void
    {
        StockLot::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Diskon 50% Hotwheels']),
        ]);
        StockLot::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Hotwheels_Minions']),
        ]);

        $this->assertCount(1, (new StockLotSearch)->search('50%'));
        $this->assertCount(1, (new StockLotSearch)->search('_Minions'));
    }
}
