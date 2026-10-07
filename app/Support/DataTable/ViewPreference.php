<?php

namespace App\Support\DataTable;

/**
 * The saved table/grid preference.
 *
 * The key is scoped to the user so two accounts sharing a machine do not inherit
 * each other's layout, and the values are kept here rather than in the markup so
 * the CSS, the inline bootstrap script and the Alpine component cannot drift.
 */
final class ViewPreference
{
    public const TABLE = 'table';

    public const GRID = 'grid';

    public static function storageKey(): string
    {
        return 'datatable-view:'.(auth()->id() ?? 'guest');
    }
}
