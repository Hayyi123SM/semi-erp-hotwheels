<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dialog PIN Owner adalah satu di halaman, bukan satu per halaman.
 *
 * Yang diuji di sini adalah bentuknya: satu template di layout, satu helper global,
 * dan nol dialog yang ditulis tangan di halaman. Bentuk ini yang membuat
 * "cukup satu overlay, satu body lock, satu focus return" benar -- dan
 * `RowActionsDialogTest` sudah mengunci bagian yang sama untuk dialog konfirmasi.
 */
class PinDialogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_authenticated_page_ships_exactly_one_pin_dialog(): void
    {
        // `array_keys` akan memberi label dataset ("master penitip"), bukan
        // address-nya; yang mau/request itu kolom pertama tiap baris.
        foreach (array_column(self::pages(), 0) as $route) {
            $html = $this->actingAs($this->owner())->get($route)->assertOk()->content();

            $this->assertSame(
                1,
                substr_count($html, 'id="pin-dialog"'),
                "Halaman {$route} harus punya tepat satu #pin-dialog.",
            );
        }
    }

    #[Test]
    public function the_dialog_is_a_template_and_not_rendered_markup(): void
    {
        $html = $this->actingAs($this->owner())->get('/inbound/consignment-in')->content();

        $start = strpos($html, '<template id="pin-dialog">');

        $this->assertNotFalse($start, 'Layout harus punya #pin-dialog.');

        // Dicari mulai dari `$start`, bukan dari awal dokumen: halaman ini punya
        // template lain (toast, grid), dan `</template>` milik mereka muncul
        // lebih dulu -- sehingga awal yang salah akan membuat field PIN di luar
        // template terlihat seperti field di dalam template.
        $end = strpos($html, '</template>', $start);

        $this->assertNotFalse($end, '#pin-dialog harus ditutup.');

        $field = 'type="password" inputmode="numeric" maxlength="6"';
        $at = strpos($html, $field, $start);

        // The field travels inside the template, because a template is inert
        // markup lifted into the popup on open. What must not exist is a PIN
        // field that is a live element of the page: present before anyone asked
        // for it, and never torn down.
        $this->assertNotFalse($at, 'Field PIN harus ada.');
        $this->assertGreaterThan(
            $start,
            $at,
            'Field PIN harus berada di dalam #pin-dialog, bukan sebagai elemen halaman.',
        );
        $this->assertLessThan($end, $at, 'Field PIN harus berada di dalam #pin-dialog.');
    }

    #[Test]
    public function the_dialog_body_is_reachable_from_the_helper_by_data_attribute(): void
    {
        $html = $this->actingAs($this->owner())->get('/inbound/consignment-in')->content();

        // `Alpine.$data` is handed the element carrying this attribute, and the
        // attribute is the only thing that ties the scope in the template to the
        // helper in JS. Renaming one side alone breaks it silently.
        $this->assertStringContainsString('data-pin-form', $html);
        $this->assertStringContainsString('pinDialogForm', $html);
    }

    #[Test]
    public function the_dialog_carries_a_csrf_token_source(): void
    {
        $html = $this->actingAs($this->owner())->get('/inbound/consignment-in')->content();

        // The helper reads the meta tag to send the PIN. Without it the endpoint
        // answers 419 and the cashier is told the PIN was wrong.
        $this->assertStringContainsString('name="csrf-token"', $html);
    }

    #[Test]
    public function no_page_writes_its_own_pin_field(): void
    {
        // A page that types a PIN into its own field is a page that will send
        // that PIN on every POST, and that cannot be checked in one place.
        foreach (glob(resource_path('views/pages/**/*.blade.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/name=["\']pin["\']/',
                $source,
                basename($file).' jangan punya input PIN sendiri.',
            );
        }
    }

    #[Test]
    public function the_pin_overlay_is_a_thin_door_opener_rather_than_a_pin_field(): void
    {
        $component = (string) file_get_contents(resource_path('views/components/ui/pin-overlay.blade.php'));

        // The old card typed a PIN into an input and toasted "PIN diterima" when
        // the button was pressed, checking nothing. What is left is a button that
        // asks the shared dialog and a hidden field for the token.
        $this->assertStringNotContainsString('type="password"', $component);
        $this->assertStringContainsString('window.pin.request', $component);
        $this->assertStringContainsString('type="hidden"', $component);
    }

    #[Test]
    public function the_overlay_ships_no_token_before_it_is_asked_for(): void
    {
        $html = $this->actingAs($this->owner())->get('/inventory/stok-opname')->assertOk()->content();

        // A page that arrived with an authorised token would mean authorisation
        // was decided by the server render rather than by the Owner at the moment
        // of the action.
        $this->assertStringNotContainsString('name="pin_token" value=', $html);
    }

    #[Test]
    public function the_dialog_actually_ships_in_the_built_bundle(): void
    {
        $this->assertFileExists(public_path('build/manifest.json'), 'Bundle belum dibangun.');

        $entry = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertArrayHasKey('resources/js/app.js', $entry);

        // Paths in the manifest are relative to `public/build`, not to `public`.
        $bundle = (string) file_get_contents(public_path('build/'.$entry['resources/js/app.js']['file']));

        // String literals survive minification, so these two prove the dialog
        // code reached the browser rather than merely that a bundle exists. The
        // template id is the half that is easy to leave behind: renaming it in
        // the layout or in the helper compiles fine and fails only when a
        // cashier opens the dialog.
        $this->assertStringContainsString('/pin/verify', $bundle);
        $this->assertStringContainsString('pin-dialog', $bundle);
    }

    public static function pages(): array
    {
        // One page of each kind that renders: a master list, an inbound form, a
        // page carrying the pin overlay, and a report.
        return [
            'master penitip' => ['/master/penitip'],
            'inbound consignment' => ['/inbound/consignment-in'],
            'inbound stock sendiri' => ['/inbound/stock-in-pribadi'],
            'stok opname' => ['/inventory/stok-opname'],
            'retur rtv (pakai overlay)' => ['/inventory/retur-rtv'],
            'audit log' => ['/reports/audit-log'],
        ];
    }

    #[DataProvider('pages')]
    #[Test]
    public function the_pin_endpoint_is_not_rendered_into_the_page(string $route): void
    {
        $html = $this->actingAs($this->owner())->get($route)->assertOk()->content();

        // The endpoint address belongs to the helper, not to the markup. A page
        // carrying its own copy is a page that can be pointed somewhere else.
        $this->assertStringNotContainsString('post(\'/pin/verify\'', $html);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }
}
