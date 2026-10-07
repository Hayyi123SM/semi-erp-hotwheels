<?php

namespace Tests\Feature\Ui;

use App\Models\Consignor;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RowActionsDialogTest extends TestCase
{
    use RefreshDatabase;

    private function render(DataTable $table): string
    {
        return Blade::render('<x-ui.data-table :table="$table" />', ['table' => $table]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function table(array $actions, string $route = 'master.penitip.edit'): DataTable
    {
        return DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('consignor_code', 'Kode')->mono(),
                Column::make('name', 'Nama'),
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', ['actions' => $actions]),
            ])
            ->title('Data Penitip');
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteAction(): array
    {
        return [
            'key' => 'archive',
            'label' => 'Arsipkan',
            'method' => 'PATCH',
            'route' => 'master.penitip.archive',
            'variant' => 'danger',
            'confirm' => [
                'title' => 'Arsipkan penitip?',
                'description' => 'Data tidak bisa dikembalikan.',
                'confirm_text' => 'Arsipkan',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (range(1, 10) as $i) {
            Consignor::create([
                'consignor_code' => 'CN'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => 'Penitip '.$i,
                'status' => 'ACTIVE',
                'scheme_type' => 'PERCENTAGE',
                'scheme_rate' => 10,
            ]);
        }
    }

    #[Test]
    public function it_carries_one_shared_form_per_table_rather_than_one_per_row(): void
    {
        $html = $this->render($this->table([$this->deleteAction()]));

        $this->assertSame(1, substr_count($html, 'x-data="rowConfirm"'));
        $this->assertSame(
            1,
            substr_count($html, 'x-ref="form"'),
            'A ten-row table must still only carry a single form to submit through'
        );
    }

    #[Test]
    public function it_ships_no_dialog_markup_in_the_table_at_all(): void
    {
        $html = $this->render($this->table([$this->deleteAction()]));

        // The confirmation is the shared helper's now, so the table carries a
        // form and nothing else. A `role="dialog"` here would mean a second
        // dialog mechanism had crept back in beside the helper.
        $this->assertStringNotContainsString('role="alertdialog"', $html);
        $this->assertStringNotContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('aria-modal="true"', $html);
    }

    #[Test]
    public function the_shared_form_is_a_real_post_form_with_a_csrf_token(): void
    {
        $html = $this->render($this->table([$this->deleteAction()]));

        // Assembled in the browser it would be a request nobody can authorise;
        // rendered by Blade it is the same request the row would have made.
        $this->assertStringContainsString('<form method="POST" x-ref="form"', $html);
        $this->assertSame(1, substr_count($html, 'name="_token"'));
        $this->assertSame(1, substr_count($html, 'x-ref="method"'));
    }

    #[Test]
    public function it_delegates_confirmable_row_actions_to_the_shared_dialog(): void
    {
        $html = $this->render($this->table([$this->deleteAction()]));

        // Ten rows are rendered twice on purpose: once in the md+ table and once
        // in the mobile card list, so there are twenty triggers, not ten.
        $this->assertSame(20, substr_count($html, "\$dispatch('row-confirm'"));
        $this->assertStringContainsString('Arsipkan penitip?', $html, 'Confirm title is passed in');
        $this->assertStringContainsString("confirmText: 'Arsipkan'", $html, 'confirm_text key is honoured');
    }

    #[Test]
    public function it_never_nests_a_post_form_inside_a_row(): void
    {
        $html = $this->render($this->table([$this->deleteAction()]));

        $this->assertStringNotContainsString(
            '<form method="POST" action="http://localhost:8000/master/penitip/1/archive"',
            $html,
            'A confirmable action must not ship its own POST form per row'
        );
    }

    #[Test]
    public function it_keeps_plain_row_actions_as_direct_links(): void
    {
        $html = $this->render($this->table([
            [
                'key' => 'edit',
                'label' => 'Ubah',
                'method' => 'GET',
                'route' => 'master.penitip.edit',
            ],
        ]));

        $this->assertStringNotContainsString("\$dispatch('row-confirm'", $html);
        $this->assertStringContainsString('Ubah', $html);
    }

    #[Test]
    public function it_omits_the_shared_dialog_when_no_row_actions_are_confirmable(): void
    {
        $table = DataTable::for(request(), Consignor::query())
            ->columns([
                Column::make('consignor_code', 'Kode'),
                Column::make('name', 'Nama'),
            ])
            ->title('Data Penitip');

        $html = $this->render($table);

        $this->assertStringNotContainsString('x-data="rowConfirm"', $html);
    }

    #[Test]
    public function no_page_mounts_two_tables_that_would_both_answer_the_one_event(): void
    {
        // Each table brings its own `x-data="rowConfirm"` scope, and every scope
        // listens on the window. Two tables on a page therefore means two scopes
        // submitting the same event, and the per-scope latch in the script stops
        // neither. Today every page mounts one, so this pins the fact the
        // script's correctness rests on -- and points at the change that would
        // break it, rather than leaving it to be discovered by a double delete.
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/pages'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            // `getExtension()` on a .blade.php file answers "php", not
            // "blade.php", so the full name is what has to be matched.
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (substr_count((string) file_get_contents($file->getPathname()), '<x-ui.data-table') > 1) {
                $offenders[] = str_replace(resource_path('views/pages').'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Scope the row-confirm event to its own table, or hoist the shared form to the page, before adding a second table');
    }
}
