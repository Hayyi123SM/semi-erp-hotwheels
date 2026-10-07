<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Consignment;
use Illuminate\Testing\TestResponse;

/**
 * After a commit, the redirect goes to the receipt, not to the list.
 *
 * Three test files need this, and each of them needs it for the same reason: the
 * commit used to land on the list, and the assertion was written for that. When
 * the redirect changed, the assertion didn't fail loudly -- it failed by
 * comparing two URLs that are both 302, and the person reading the failure had
 * to work out which one was correct.
 *
 * Shared as a trait rather than copied into three files so that "where does
 * commit land" is stated once. Three copies would drift, and the drift would
 * show up as a test file whose assertion describes a different flow than the
 * other two.
 */
trait AssertsReceiptRedirect
{
    /**
     * Assert the response landed on the receipt of the newest document.
     *
     * "Newest" rather than "the document just created" on purpose: the tests
     * that use this don't hold the ID, and re-reading it from the database
     * proves the commit actually created a document instead of only returning a
     * redirect. A commit that creates nothing would otherwise pass with a
     * redirect built from a nonexistent ID.
     */
    protected function assertRedirectedToReceipt(TestResponse $response): void
    {
        $consignment = Consignment::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('inbound.consignment-in.bukti-terima', [
            'consignment' => $consignment,
            'auto' => 1,
        ]));
    }
}
