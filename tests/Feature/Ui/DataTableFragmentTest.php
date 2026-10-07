<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\User;
use App\Support\DataTable\Fragment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The table's in-place refresh, from the server's side.
 *
 * A refresh asks the same URL for the two regions the table owns. What matters
 * here is that the answer really is smaller, and that it is a faithful answer:
 * the rows have to be the ones the query describes, and the second row has to
 * say whether it should be there at all.
 */
class DataTableFragmentTest extends TestCase
{
    use RefreshDatabase;

    private function penitip(string $name, array $overrides = []): Consignor
    {
        return Consignor::create(array_merge([
            'consignor_code' => 'CN'.str_pad((string) Consignor::count() + 1, 2, '0', STR_PAD_LEFT),
            'name' => $name,
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 10,
        ], $overrides));
    }

    #[Test]
    public function it_answers_a_refresh_with_the_table_and_not_the_page_around_it(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-fragment="results"', $html);
        $this->assertStringContainsString('data-fragment="strip"', $html);

        // The shell is what made a keystroke expensive in the first place.
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('layouts.sidebar', $html);
        $this->assertStringNotContainsString('Sidebar', $html);
    }

    #[Test]
    public function it_leaves_the_toolbar_out_of_a_refresh_so_the_search_field_survives_it(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        // Overwriting the field someone is halfway through typing in is worse
        // than a value a moment out of step.
        $this->assertStringNotContainsString('name="q"', $html);
        $this->assertStringNotContainsString('data-table-form', $html);
    }

    #[Test]
    public function a_refresh_carries_the_rows_the_query_asked_for(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');
        $this->penitip('Penitip Dua');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip', ['q' => 'Dua']), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Penitip Dua', $html);
        $this->assertStringNotContainsString('Penitip Satu', $html);
    }

    #[Test]
    public function a_refresh_states_the_applied_filters_so_the_chips_cannot_go_stale(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu', ['status' => ConsignorStatus::Archived]);

        $html = $this->actingAs($owner)
            ->get(route('master.penitip', ['status' => 'archived']), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('filter-token', $html);
        $this->assertStringContainsString('data-present="1"', $html);
    }

    #[Test]
    public function a_refresh_says_when_the_toolbar_second_row_has_no_reason_to_exist(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        // Penitip always has a status dropdown, so the row is here whatever the
        // query says. The flag the client reads is the one that has to travel.
        $html = $this->actingAs($owner)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-present="1"', $html);
        $this->assertStringContainsString('filter-strip', $html);
    }

    #[Test]
    public function an_ordinary_request_is_still_a_whole_page(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        // The refresh is an enhancement over a working baseline, not a
        // replacement for it: with the script gone, this is what has to arrive.
        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('<!DOCTYPE html>', false)
            ->assertSee('name="q"', false)
            ->assertSee('data-table-form', false);
    }

    #[Test]
    public function a_refresh_never_carries_a_handler_that_depends_on_being_inside_a_form(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        // The toolbar form is left out on purpose, so markup in the answer that
        // goes looking for one throws the moment a reader touches it. That is
        // the "cannot read properties of undefined" in the console, and it is
        // not something the rows can show a failure for: the filter simply stops
        // answering.
        $this->assertStringNotContainsString('$el.form', $html);
        $this->assertStringNotContainsString('closest(', $html);
    }

    #[Test]
    public function the_page_size_form_survives_a_refresh_and_still_submits_itself(): void
    {
        $owner = User::factory()->owner()->create();
        foreach (range(1, 25) as $i) {
            $this->penitip('Penitip '.$i);
        }

        $html = $this->actingAs($owner)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        // The form is rebuilt by the client from this answer, so it arrives as
        // new markup and is initialised by the same tree walk as the rows. If it
        // carried no listener, changing the page size would silently do nothing.
        $this->assertStringContainsString('name="per_page"', $html);
        $this->assertStringContainsString('x-data="dataTableForm"', $html);
        $this->assertStringContainsString('x-on:change="apply()"', $html);
    }

    #[Test]
    public function a_filter_change_on_a_full_page_reaches_the_table_form(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        $html = $this->actingAs($owner)->get(route('master.penitip'))->assertOk()->getContent();

        // One listener on the form, rather than one on each control: whatever a
        // page drops into the second row submits with the search box above it.
        $this->assertStringContainsString('data-table-form', $html);
        $this->assertStringContainsString('x-on:change="apply()"', $html);
        $this->assertStringNotContainsString('$el.form', $html);
    }

    #[Test]
    public function a_refresh_carries_the_same_empty_state_the_page_would_have_shown(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Satu');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip', ['q' => 'tidak ada yang begitu']), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Penitip tidak ditemukan', $html);
    }

    #[Test]
    public function a_refresh_still_obeys_the_readers_permissions(): void
    {
        $this->penitip('Penitip Satu');

        $staff = User::factory()->staff()->create();

        $html = $this->actingAs($staff)
            ->get(route('master.penitip'), [Fragment::HEADER => '1'])
            ->assertOk()
            ->getContent();

        // A refresh is a way of asking sooner, not a way of asking for more.
        $this->assertStringNotContainsString('Tambah Penitip', $html);
    }
}
