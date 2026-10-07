<?php

namespace Tests\Feature\Inventory;

use App\Models\Product;
use App\Models\StockLot;
use App\Services\Inventory\StockLotSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lot search as the operator types it.
 *
 * The pattern is built in PHP and interpreted by the database, and those two
 * disagree about what a backslash means. MySQL treats it as the escape
 * character whether or not the query says so; SQLite does not. So a pattern
 * that is right on one engine silently returns the wrong rows on the other,
 * and the only honest test is the one that states the escape character.
 */
class StockLotSearchTest extends TestCase
{
    use RefreshDatabase;

    private function lotSearch(): StockLotSearch
    {
        return new StockLotSearch;
    }

    private function lotWithProductName(string $name): StockLot
    {
        return StockLot::factory()->create([
            'product_id' => Product::factory()->create(['name' => $name]),
        ]);
    }

    #[Test]
    public function it_matches_a_wildcard_character_in_the_term_as_ordinary_text(): void
    {
        $this->lotWithProductName('Diskon 50% Hotwheels');
        $this->lotWithProductName('Diskon 40% Hotwheels');

        $found = $this->lotSearch()->search('50%');

        $this->assertCount(1, $found);
        $this->assertSame('Diskon 50% Hotwheels', $found->first()->product->name);
    }

    #[Test]
    public function it_matches_an_underscore_in_the_term_as_ordinary_text(): void
    {
        $this->lotWithProductName('Hotwheels_Minions');
        $this->lotWithProductName('Hotwheels Minions');

        $found = $this->lotSearch()->search('_Minions');

        $this->assertCount(1, $found);
        $this->assertSame('Hotwheels_Minions', $found->first()->product->name);
    }

    #[Test]
    public function it_matches_the_escape_character_in_the_term_as_ordinary_text(): void
    {
        $this->lotWithProductName('Gigi! Hotwheels');
        $this->lotWithProductName('Gigi Hotwheels');

        $found = $this->lotSearch()->search('Gigi!');

        $this->assertCount(1, $found);
        $this->assertSame('Gigi! Hotwheels', $found->first()->product->name);
    }

    #[Test]
    public function it_declares_the_escape_character_it_escaped_with(): void
    {
        $this->lotWithProductName('Diskon 50% Hotwheels');

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });

        $this->lotSearch()->search('50%');

        $like = collect($queries)->first(fn ($sql) => str_contains(mb_strtolower($sql), 'like'));

        $this->assertNotNull($like, 'Expected a LIKE comparison.');
        $this->assertStringContainsString("escape '!'", mb_strtolower($like));
        $this->assertStringNotContainsString("escape '\\'", mb_strtolower($like));
    }

    #[Test]
    public function it_ignores_a_term_too_short_to_be_worth_a_query(): void
    {
        $this->lotWithProductName('Hotwheels');

        $this->assertCount(0, $this->lotSearch()->search('H'));
    }
}
