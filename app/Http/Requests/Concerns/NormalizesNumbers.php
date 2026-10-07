<?php

namespace App\Http\Requests\Concerns;

use App\Support\Numbers;

/**
 * Reduces a number the reader typed down to the digits the column can hold.
 *
 * `Numbers` says what the reduction is; this says which fields get it. The list
 * is declared per request rather than worked out from the field names, because
 * "looks numeric" is not the test. A PIN is digits and is not a number: stripping
 * the dash out of `12-34` would turn a refused entry into an accepted one, which
 * is the same class of mistake pointed the other way.
 *
 * It runs before the rules, which is also what makes it work: the request
 * normalises and then validates, so a refused `1.000` is flashed back as the
 * `1000` it was read as, and the field repopulates with the figure that was
 * actually refused rather than with the string that caused it.
 */
trait NormalizesNumbers
{
    /**
     * The fields to reduce, keyed by name, valued by how to read them.
     *
     * Dotted names reach into a nested field, which is how the import request
     * reaches one entry of a `defaults` array without a second code path.
     *
     * @return array<string, 'integer'|'rate'>
     */
    abstract protected function normalizableNumbers(): array;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->normalizableNumbers() as $field => $mode) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $mode === 'rate'
                ? Numbers::rate($this->input($field))
                : Numbers::integer($this->input($field));

            // Left exactly as it arrived, so a field the reader emptied is still
            // empty rather than a zero nobody typed, and `nullable` still means
            // something on the way to the database.
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
