<?php

namespace Tests\Feature\Ui;

use App\Enums\ConsignorStatus;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guard's server-side contract: which forms opted in, and what still works
 * when the script never arrives.
 *
 * The guard is opt-in through `data-guard` rather than taking hold of every form
 * on the page, so this file is mostly about the boundary of that opt-in. It also
 * pins the fact that opting in changed nothing about how the form is submitted:
 * the guard is a courtesy, and a reader whose JavaScript failed to load is still
 * able to save and still ends up somewhere sensible.
 */
class UnsavedChangesGuardTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function penitip(): Consignor
    {
        return Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Budi Santoso',
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 10,
        ]);
    }

    /** @return list<array{0: string}> */
    public static function createRoutes(): array
    {
        return [
            ['master.penitip.create'],
            ['master.katalog-produk.create'],
            ['master.lokasi-rak.create'],
            ['setting.pengguna.create'],
        ];
    }

    #[Test]
    #[DataProvider('createRoutes')]
    public function every_form_page_opts_its_form_in(string $route): void
    {
        $owner = $this->owner();

        $this->assertStringContainsString(
            '<form data-guard method="POST"',
            $this->actingAs($owner)->get(route($route))->getContent()
        );
    }

    #[Test]
    public function opting_in_did_not_take_the_post_away(): void
    {
        $owner = $this->owner();
        $consignor = $this->penitip();

        $html = $this->actingAs($owner)->get(route('master.penitip.edit', $consignor))->getContent();

        // No JavaScript means no guard, and the reader still has to be able to
        // save. A form that only worked through a script would be a worse bug
        // than the one the guard is here to prevent.
        $this->assertMatchesRegularExpression(
            '/<form data-guard method="POST" action="'.preg_quote(route('master.penitip.update', $consignor), '/').'"/',
            $html
        );
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('onsubmit', $html);
    }

    #[Test]
    public function the_list_search_is_not_guarded(): void
    {
        $owner = $this->owner();

        // The search box is a form too, and a reader halfway through typing a
        // search has to be free to click away from it.
        $this->assertStringNotContainsString(
            '<form data-guard method="GET"',
            $this->actingAs($owner)->get(route('master.penitip'))->getContent()
        );
    }

    #[Test]
    public function the_guard_is_mounted_once_for_the_whole_application(): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route('master.penitip'))->getContent();

        $this->assertSame(1, substr_count($html, 'x-data="unsavedChanges"'));
    }

    #[Test]
    public function the_layout_carries_no_warning_dialog_of_its_own(): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route('master.penitip'))->getContent();

        // The warning is the shared dialog now. What used to be pinned here --
        // a dialog island whose name had to match a constant in the script, in
        // two languages with nothing comparing them -- is gone, and this is what
        // stops a second one creeping back in beside it.
        $this->assertStringNotContainsString('name="unsaved-changes"', $html);
        $this->assertStringNotContainsString('Perubahan belum disimpan', $html);
        $this->assertStringNotContainsString('x-ui.modal', $html);
    }

    #[Test]
    public function the_island_carries_no_dialog_markup_to_go_stale(): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route('master.penitip'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<div x-data="unsavedChanges"><\/div>/',
            $html,
            'The island exists for its listeners, not as a wrapper for a dialog'
        );
    }

    #[Test]
    public function the_script_is_registered(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import { unsavedChanges } from './alpine/unsaved-changes';", $js);
        $this->assertStringContainsString("Alpine.data('unsavedChanges', unsavedChanges);", $js);
    }

    #[Test]
    public function the_warning_is_asked_through_the_shared_helper(): void
    {
        $js = (string) file_get_contents(resource_path('js/alpine/unsaved-changes.js'));

        // The one place the reader is told what losing the page costs. Kept
        // here, in the script that decides to warn, rather than split between
        // the script and a template.
        $this->assertStringContainsString('title: \'Perubahan belum disimpan\'', $js);
        $this->assertStringContainsString('notify.dangerConfirm(WARNING)', $js);
    }
}
