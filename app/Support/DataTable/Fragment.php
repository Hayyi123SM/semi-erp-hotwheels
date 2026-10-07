<?php

namespace App\Support\DataTable;

use Illuminate\Http\Request;

/**
 * The request/response contract for refreshing a data table in place.
 *
 * Searching, sorting and paging a table do not need a new document: the reader
 * already has the layout, the sidebar and the toolbar, and re-sending them means
 * the browser re-parses and Alpine re-boots a page the reader is looking at.
 * Sending this header asks for the two regions the table owns and nothing else.
 *
 * A header rather than a query key, so a fragment request can never be cached
 * apart from the full page it shares a URL with.
 */
final class Fragment
{
    public const HEADER = 'X-Table-Fragment';

    /**
     * Whether the current request wants the table regions instead of a page.
     */
    public static function requested(Request $request): bool
    {
        return $request->hasHeader(self::HEADER);
    }
}
