<?php

namespace Tests\Feature;

use App\Enums\ConsignorStatus;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens to a number that arrives wearing the separators a reader gave it.
 *
 * Every amount in this project is typed the Indonesian way -- `1.000.000` -- and
 * PHP reads a dot as a decimal point. The two disagree about the same character,
 * and the disagreement is the worst kind: the value that reaches the database is
 * a perfectly good number, just a hundredth or a thousandth of the one that was
 * typed, and no rule in the application will ever object to it.
 *
 * These tests are pinned against the request path rather than the helper, because
 * the helper being right proves nothing on its own. The bug this fixes lived in
 * a gap: the importer had a normalizer and the form did not, and nothing ever
 * asked whether the two agreed. A test that only exercises the helper would have
 * been green throughout.
 */
class NumericInputNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function consignorPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'scheme_type' => SchemeType::Nett->value,
            'loss_liability' => 'CONSIGNOR',
            'settlement_cycle' => 'MONTHLY',
        ], $overrides);
    }

    #[Test]
    public function a_grouped_amount_survives_the_round_trip(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/penitip', $this->consignorPayload([
            'scheme_amount' => '1.500.000',
            'min_payout' => '500.000',
        ]))->assertRedirect(route('master.penitip'))->assertSessionHasNoErrors();

        $consignor = Consignor::where('name', 'Budi Santoso')->firstOrFail();

        // `(int) '1.500.000'` is 1500000, but the cast happens after `numeric`
        // has already read the string as 1.5 -- so the assertion here is the only
        // place the difference is visible.
        $this->assertSame(1500000, (int) $consignor->scheme_amount);
        $this->assertSame(500000, (int) $consignor->min_payout);
    }

    #[Test]
    public function a_plain_amount_is_untouched(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/penitip', $this->consignorPayload([
            'scheme_amount' => '20000',
        ]))->assertRedirect(route('master.penitip'));

        $this->assertSame(20000, (int) Consignor::firstOrFail()->scheme_amount);
    }

    /**
     * The case that was silently wrong.
     *
     * `1.000` on a percentage is a thousand, because a share is bounded at 100
     * and cannot have a thousands separator. Read as a decimal it becomes `1.00`,
     * which passes `numeric`, passes `between:0,100`, and stores a hundredth of
     * the agreed share while the screen says it saved.
     *
     * The fix is not to accept it. The fix is to read it as what it plainly is
     * and let the rule refuse it out loud, so the reader is still holding the
     * figure they typed and told why it will not stand.
     */
    #[Test]
    public function a_grouped_rate_is_refused_rather_than_silently_divided_by_a_thousand(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post('/master/penitip', $this->consignorPayload([
                'scheme_type' => SchemeType::Percentage->value,
                'scheme_rate' => '1.000',
            ]))
            ->assertSessionHasErrors('scheme_rate');

        $this->assertDatabaseCount('consignors', 0);
    }

    #[Test]
    public function a_rate_is_read_as_a_decimal_in_either_convention(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['12,5' => 12.5, '12.5' => 12.5, '20,00' => 20.0, '20' => 20.0] as $input => $expected) {
            $this->actingAs($owner)->post('/master/penitip', $this->consignorPayload([
                'name' => 'Siti '.$input,
                'scheme_type' => SchemeType::Percentage->value,
                'scheme_rate' => (string) $input,
            ]))->assertSessionHasNoErrors();

            $this->assertSame($expected, (float) Consignor::where('name', 'Siti '.$input)->firstOrFail()->scheme_rate);
        }
    }

    #[Test]
    public function a_rate_outside_the_agreed_range_is_still_refused(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/penitip', $this->consignorPayload([
            'scheme_type' => SchemeType::Percentage->value,
            'scheme_rate' => '150',
        ]))->assertSessionHasErrors('scheme_rate');

        $this->assertDatabaseCount('consignors', 0);
    }

    /**
     * A field left empty is a choice, and a blank that became `0` on the way
     * through would quietly take that choice away.
     */
    #[Test]
    public function an_empty_amount_stays_empty_rather_than_becoming_zero(): void
    {
        $owner = User::factory()->owner()->create();

        // PERCENTAGE, because NETT and FLAT both require an amount -- a field the
        // reader cannot leave empty is not the one this is about.
        $this->actingAs($owner)->post('/master/penitip', $this->consignorPayload([
            'scheme_type' => SchemeType::Percentage->value,
            'scheme_rate' => '10',
            'scheme_amount' => '',
            'min_payout' => '',
        ]))->assertRedirect(route('master.penitip'))->assertSessionHasNoErrors();

        $consignor = Consignor::firstOrFail();
        $this->assertNull($consignor->scheme_amount);
        $this->assertNull($consignor->min_payout);
    }

    /**
     * The other direction of the same mistake, in one request.
     *
     * A bank account is digits and is not a number. It is a `string` to this
     * application, and the reader is entitled to the separators they typed --
     * stripping them would rewrite an account number to suit a rule about
     * amounts. Normalizing by guessing from the field name is how that starts
     * happening, so the list is declared per request and this pins what that
     * buys: the amount beside the account is reduced, the account is not.
     */
    #[Test]
    public function only_the_declared_fields_are_read_as_numbers(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::create([
            'name' => 'Pending PIN',
            // The controller issues the code; building the row by hand has to
            // supply it, or the insert stops on a NOT NULL nothing to do with
            // numbers.
            'consignor_code' => 'CN01',
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Nett,
            'bank_account' => '1234567890',
        ]);

        $this->actingAs($owner)->put('/master/penitip/'.$consignor->id, [
            'name' => 'Pending PIN',
            'scheme_type' => SchemeType::Nett->value,
            'loss_liability' => 'CONSIGNOR',
            'settlement_cycle' => 'MONTHLY',
            // The rest of a complete row. A partial PUT is refused on a missing
            // required field before it ever reaches the rule under test, which
            // would pass for the right reason.
            'discount_policy' => 'SHARED',
            'scheme_amount' => '2.000',
            'bank_account' => '12-34-5678',
        ])->assertSessionHasNoErrors();

        $fresh = $consignor->fresh();

        // Reduced, because it was declared: `2.000` is two thousand.
        $this->assertSame(2000, (int) $fresh->scheme_amount);
        // Untouched, because it was not.
        $this->assertSame('12-34-5678', $fresh->bank_account);
    }

    #[Test]
    public function a_price_grouped_on_the_product_form_survives(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/katalog-produk', [
            'name' => 'Honda Civic 1998',
            'default_list_price' => '1.750.000',
        ])->assertRedirect(route('master.katalog-produk'))->assertSessionHasNoErrors();

        $this->assertSame(1750000, (int) Product::firstOrFail()->default_list_price);
    }

    /**
     * A capacity is not an amount and is not grouped on screen -- but it shares
     * the `integer` column, and a spreadsheet cell holding `1.200` would be cast
     * to `1` on the way in. Normalizing it costs nothing and closes that gap.
     */
    #[Test]
    public function a_capacity_from_a_spreadsheet_is_not_cast_down_to_a_digit(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/lokasi-rak', [
            'code' => 'a-01',
            'zone' => 'A',
            'type' => 'DISPLAY',
            'capacity' => '1.200',
            'is_active' => '1',
        ])->assertRedirect(route('master.lokasi-rak'))->assertSessionHasNoErrors();

        $this->assertSame(1200, (int) Rack::firstOrFail()->capacity);
    }

    /**
     * The ceiling is the column's, not the application's.
     *
     * `unsignedInteger` tops out at 4294967295, and a number past it passes
     * `integer` and `min:0` only to be refused by the database, which reports
     * it as a driver error rather than as anything the reader can act on.
     */
    #[Test]
    public function an_amount_past_the_column_ceiling_is_refused_with_a_message(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/katalog-produk', [
            'name' => 'Too Expensive',
            'default_list_price' => '99999999999',
        ])->assertSessionHasErrors('default_list_price');

        $this->assertDatabaseCount('products', 0);
    }

    #[Test]
    public function the_largest_amount_the_column_holds_is_still_accepted(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/katalog-produk', [
            'name' => 'Exactly At The Ceiling',
            'default_list_price' => '4294967295',
        ])->assertRedirect(route('master.katalog-produk'))->assertSessionHasNoErrors();

        $this->assertSame(4294967295, (int) Product::firstOrFail()->default_list_price);
    }
}
