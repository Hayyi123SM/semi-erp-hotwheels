<?php

namespace App\Support\Sql;

/**
 * A `LIKE` pattern that means the same thing on every database.
 *
 * Reading a term the reader typed means two things have to agree: what the
 * wildcard characters are, and what the character that stands for a literal one
 * is. The second is where the engines disagree.
 *
 * MySQL treats a backslash as the escape character whether or not the query says
 * so, and reading `ESCAPE '\'` as an unterminated string literal makes it reject
 * the whole statement. SQLite treats backslash as an ordinary character, so a
 * pattern that leans on the default silently matches the wrong rows there.
 *
 * There is no character both engines read the same way by default, so the
 * choice is made here and declared in every query: `!`. It has to be escaped
 * first, or a term containing one would read as a pattern rather than as text.
 */
final class LikePattern
{
    /** The escape character, declared by {@see clause()} and used by {@see escape()}. */
    public const ESCAPE = '!';

    /**
     * Escape the characters that would otherwise be read as pattern syntax.
     *
     * The escape character itself comes first in the list because `str_replace`
     * runs its pairs in order, and doubling it is what makes the other two
     * replacements safe to write with the character they are escaping.
     */
    public static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }

    /** Match the term anywhere in the column. */
    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }

    /** Match the term at the start of the column. */
    public static function startsWith(string $value): string
    {
        return self::escape($value).'%';
    }

    /**
     * The comparison to hand to `whereRaw`, for a column reference built by the
     * caller -- a table and column from the developer's own whitelist, never
     * anything the reader sent.
     *
     * @param  string  $column  A quoted column reference, e.g. '`consignors`.`name`'
     */
    public static function clause(string $column): string
    {
        return $column." LIKE ? ESCAPE '".self::ESCAPE."'";
    }
}
