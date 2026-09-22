<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\sql;

/**
 * SQL dialects the normaliser understands, keyed by `driverName`, with the rules that differ
 * between them (spec 02 §4, dialect table).
 *
 * @internal
 */
enum Dialect: string
{
    case MySql = 'mysql';
    case PgSql = 'pgsql';

    /** `"..."` is a string literal rather than a quoted identifier. */
    public function doubleQuoteIsString(): bool
    {
        return $this === self::MySql;
    }

    /** Backslash escapes the next character inside `'...'`. */
    public function backslashEscapes(): bool
    {
        return $this === self::MySql;
    }

    /** `#` starts a comment. */
    public function hashComments(): bool
    {
        return $this === self::MySql;
    }

    /** `--` starts a comment only when followed by whitespace or the end of the text. */
    public function dashCommentNeedsSpace(): bool
    {
        return $this === self::MySql;
    }

    /** `` `...` `` is a quoted identifier. */
    public function backtickIdentifiers(): bool
    {
        return $this === self::MySql;
    }

    /** `$1` parameters and `$$...$$`, `$tag$...$tag$` literals. */
    public function dollarSyntax(): bool
    {
        return $this === self::PgSql;
    }
}
